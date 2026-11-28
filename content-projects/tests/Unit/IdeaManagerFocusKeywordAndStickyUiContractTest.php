<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsDuplicateFilter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerDistributionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\TopicGroup\TopicGroupMatchResult;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\TopicGroup\TopicGroupRetrievalClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

/**
 * Focus Keyword exact + semantic duplicate check & Sticky UI contracts for Idea Manager.
 */
final class IdeaManagerFocusKeywordAndStickyUiContractTest extends TestCase
{
    public function test_preview_uses_only_destination_article_focus_keywords(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName()
        );

        // Must load Focus Keywords from SeoArticle scoped to $targetSiteId.
        self::assertStringContainsString('loadDestinationArticleFocusKeywords($targetSiteId)', $src);
        self::assertStringContainsString("whereIn('meta_key', ['seo_focus_keyword', 'rank_math_focus_keyword'])", $src);
        self::assertStringContainsString("where('site_id', \$targetSiteId)", $src);
        self::assertStringContainsString("ArticleResource::getUrl('edit'", $src);

        // Old non-Focus-Keyword duplicate sources must not be used in preview/analyzeItem.
        self::assertStringNotContainsString('withDestinationContentInventory', $src);
        self::assertStringNotContainsString('buildSemanticInventory', $src);
        self::assertStringNotContainsString('exact_destination_topic_core', $src);
        self::assertStringNotContainsString('SeoProjectTask', $src);
        self::assertStringContainsString("'can_confirm' => count(\$rows) > 0", $src);
    }

    public function test_analyze_item_handles_no_focus_keywords_exact_semantic_and_unavailable(): void
    {
        $service = new IdeaManagerDistributionService(
            new IdeaWorkspacePersistenceService,
            (new ReflectionClass(DiscoverNewTopicsDuplicateFilter::class))->newInstanceWithoutConstructor(),
        );
        $analyze = new ReflectionMethod(IdeaManagerDistributionService::class, 'analyzeItem');
        $analyze->setAccessible(true);

        $item = [
            'cluster_ref' => 'c-tweed',
            'cluster_name_snapshot' => 'Vải Tweed',
        ];

        // 1. Destination has no Focus Keywords -> no false duplicate.
        $noFk = $analyze->invoke($service, $item, [], [], 7);
        self::assertSame(IdeaManagerDistributionService::CHECK_NO_FOCUS_KEYWORDS, $noFk['check_status']);
        self::assertFalse($noFk['exact_match']);
        self::assertFalse($noFk['warning']);
        self::assertNull($noFk['article_id']);

        // 2. Exact Focus Keyword match on destination Article -> Exact + Article evidence.
        $exactKey = AuditNoteDnaNormalizer::normalizeKey('Vải Tweed');
        $inventory = [[
            'ref' => 'article_fk:42',
            'label' => 'Vải Tweed',
            'examples' => ['Vải Tweed'],
            'focus_keyword' => 'Vải Tweed',
            'normalized_key' => $exactKey,
            'article_id' => 42,
            'article_title' => 'Vải Tweed là gì? Đặc điểm và ứng dụng',
            'article_status' => 'published',
            'article_status_label' => 'Published',
            'article_edit_url' => '/seo/articles/42/edit',
        ]];
        $exactMap = [$exactKey => $inventory[0]];

        $exact = $analyze->invoke($service, $item, $exactMap, $inventory, 7);
        self::assertTrue($exact['exact_match']);
        self::assertTrue($exact['warning']);
        self::assertSame('exact_focus_keyword', $exact['reason']);
        self::assertSame('Vải Tweed', $exact['focus_keyword']);
        self::assertSame(42, $exact['article_id']);
        self::assertSame('Vải Tweed là gì? Đặc điểm và ứng dụng', $exact['article_title']);
        self::assertSame('Published', $exact['article_status_label']);
        self::assertSame('/seo/articles/42/edit', $exact['article_edit_url']);

        // 3. Semantic client unavailable -> Unknown, no fabricated score.
        $client = new TopicGroupRetrievalClient(new SemanticAnalyticsClient('http://127.0.0.1:0', 1));
        $serviceUnavailable = new IdeaManagerDistributionService(
            new IdeaWorkspacePersistenceService,
            (new ReflectionClass(DiscoverNewTopicsDuplicateFilter::class))->newInstanceWithoutConstructor(),
            $client,
        );
        $semInventory = [[
            'ref' => 'article_fk:99',
            'label' => 'Chất liệu vải dạ tweed',
            'examples' => ['Chất liệu vải dạ tweed'],
            'focus_keyword' => 'Chất liệu vải dạ tweed',
            'normalized_key' => AuditNoteDnaNormalizer::normalizeKey('Chất liệu vải dạ tweed'),
            'article_id' => 99,
            'article_title' => 'Phân biệt vải dạ tweed cao cấp',
            'article_status' => 'draft',
            'article_status_label' => 'Draft',
            'article_edit_url' => '/seo/articles/99/edit',
        ]];
        $unknown = $analyze->invoke(
            $serviceUnavailable,
            $item,
            [AuditNoteDnaNormalizer::normalizeKey('Chất liệu vải dạ tweed') => $semInventory[0]],
            $semInventory,
            7,
        );
        self::assertSame(IdeaManagerDistributionService::CHECK_UNAVAILABLE, $unknown['check_status']);
        self::assertFalse($unknown['warning']);
        self::assertNull($unknown['similarity_score']);

        // 4. Canonical TopicGroupMatchResult parses provider similarity score cleanly.
        $parsed = TopicGroupMatchResult::fromApi([
            'provider' => 'semantic',
            'matches' => [[
                'ref' => 'article_fk:99',
                'label' => 'Chất liệu vải dạ tweed',
                'score' => 0.842,
                'reason' => 'semantic_similarity',
            ]],
        ]);
        self::assertCount(1, $parsed->matches);
        self::assertSame('article_fk:99', $parsed->matches[0]['ref']);
        self::assertSame(0.842, $parsed->matches[0]['score']);
    }

    public function test_blade_sticky_layout_and_article_evidence_ux_contract(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');

        self::assertStringContainsString('.im-stepbar {', $blade);
        self::assertStringContainsString('position: sticky; top: 0.5rem;', $blade);
        self::assertStringContainsString('im-side__controls', $blade);
        self::assertStringContainsString('im-side__list', $blade);
        self::assertStringContainsString('im-main__sticky', $blade);
        self::assertStringContainsString('im-main__list', $blade);
        self::assertStringContainsString('.im-right__body thead th { position: sticky; top: 0;', $blade);
        self::assertStringContainsString('im-dist__head', $blade);
        self::assertStringContainsString('im-dist__scroll', $blade);
        self::assertStringContainsString('im-dist__actions', $blade);
        self::assertStringContainsString('data-im-distribution-check="1"', $blade);
        self::assertStringContainsString('data-im-distribution-confirm="1"', $blade);
        self::assertStringContainsString('checkDuplicates()', $blade);
        self::assertStringContainsString('distributeWithAi()', $blade);
        self::assertStringContainsString('startRunPolling(', $blade);
        self::assertStringContainsString('reconcileAfterTimeout()', $blade);
        self::assertStringContainsString('pendingRequestKey', $blade);
        self::assertStringContainsString('im-main__shell', $blade);
        self::assertStringContainsString('it.focus_keyword', $blade);

        // Verify exact DOM ancestry: .im-main__sticky and .im-main__list are siblings inside .im-main__shell.
        $shellPos = strpos($blade, '<div class="im-main__shell">');
        $stickyPos = strpos($blade, '<div class="im-main__sticky">');
        $listPos = strpos($blade, '<div class="im-main__list">');
        self::assertNotFalse($shellPos);
        self::assertNotFalse($stickyPos);
        self::assertNotFalse($listPos);
        self::assertGreaterThan($shellPos, $stickyPos);
        self::assertGreaterThan($stickyPos, $listPos);

        // Extract from <div class="im-main__shell"> to <div class="im-main__list"> and verify balanced <div> tags.
        $betweenStickyAndList = substr($blade, $stickyPos, $listPos - $stickyPos);
        $opens = substr_count($betweenStickyAndList, '<div');
        $closes = substr_count($betweenStickyAndList, '</div>');
        self::assertSame($opens, $closes, '.im-main__sticky must be closed before .im-main__list opens');
        self::assertStringContainsString('it.article_title', $blade);
        self::assertStringContainsString('it.article_edit_url', $blade);
        self::assertStringNotContainsString('/seo/keywords', $blade);
    }

    public function test_ai_distribution_reuses_canonical_generate_new_content_command_with_idempotency(): void
    {
        $svc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName()
        );
        self::assertStringContainsString('function distributeWithAi', $svc);
        self::assertStringContainsString('function pollRun', $svc);
        self::assertStringContainsString('function reconcileLatestRun', $svc);
        self::assertStringContainsString('GenerateNewContentSuggestionsCommand', $svc);
        self::assertStringContainsString('PlanningDraftResolver', $svc);
        self::assertStringContainsString('AuditNoteTargetAllocator::apply', $svc);
        self::assertStringContainsString("'planning_month' => \$month", $svc);
        self::assertStringContainsString('idea-manager-ai:', $svc);

        $page = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager::class))->getFileName()
        );
        self::assertStringContainsString('function checkDistributionDuplicates', $page);
        self::assertStringContainsString('function distributeWithAi', $page);
        self::assertStringContainsString('function pollDistributionRun', $page);
        self::assertStringContainsString('function reconcileDistributionRun', $page);
    }
}
