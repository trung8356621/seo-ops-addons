<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsService;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\InteractsWithKeywordItemActions;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\ListKeywords;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordLandscapeReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordRelationshipReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDetailQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTagMetricsResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\KeywordMonthlyMcpSource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * McpExcluded quarantine — canonical storage + intelligence boundary contracts.
 */
final class McpExcludedQuarantineContractTest extends TestCase
{
    public function test_canonical_meta_key_and_service_api(): void
    {
        self::assertSame('mcp_keyword_excluded', KeywordMetaKey::McpExcluded->value);
        self::assertNotSame(KeywordMetaKey::SeoHidden->value, KeywordMetaKey::McpExcluded->value);

        $ref = new ReflectionClass(SkipKeywordFromMcpService::class);
        self::assertTrue($ref->hasMethod('skip'));
        self::assertTrue($ref->hasMethod('restore'));
        self::assertTrue($ref->hasMethod('isSkipped'));
        self::assertTrue($ref->hasMethod('skippedKeywordIdMap'));

        $src = (string) file_get_contents((string) $ref->getFileName());
        self::assertStringContainsString('KeywordMetaKey::McpExcluded', $src);
        self::assertStringContainsString("meta_value', '1'", $src);
        self::assertStringContainsString('->delete()', $src);
        self::assertStringNotContainsString('KeywordMetaKey::SeoHidden', $src);
        self::assertStringNotContainsString('SeoArticle', $src);
        self::assertStringNotContainsString('PromptRunner', $src);
        self::assertStringNotContainsString('recluster', strtolower($src));
    }

    public function test_skip_does_not_write_seo_hidden(): void
    {
        $skipSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SkipKeywordFromMcpService::class))->getFileName(),
        );
        $hideSrc = (string) file_get_contents(
            (string) (new ReflectionClass(HideKeywordFromSeoService::class))->getFileName(),
        );

        self::assertStringNotContainsString('seo_keyword_hidden', $skipSrc);
        self::assertStringNotContainsString('KeywordMetaKey::SeoHidden', $skipSrc);
        self::assertStringContainsString('KeywordMetaKey::SeoHidden', $hideSrc);
        self::assertStringNotContainsString('mcp_keyword_excluded', $hideSrc);
        self::assertStringNotContainsString('KeywordMetaKey::McpExcluded', $hideSrc);
    }

    public function test_landscape_ssot_excludes_mcp_quarantined_keywords(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordLandscapeReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('SkipKeywordFromMcpService', $src);
        self::assertStringContainsString('skippedKeywordIdMap', $src);
        self::assertStringContainsString('countForTopics($siteId, $topicIds, $excludedKeywordIds)', $src);
        self::assertStringContainsString('forTopics($siteId, $topicIds, $articleCounts, $excludedKeywordIds)', $src);
        self::assertStringContainsString('loadDnaByTopic($siteId, $topicIds, $excludedKeywordIds)', $src);
    }

    public function test_gateway_and_monthly_mcp_remain_landscape_consumers(): void
    {
        $gateway = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordLandscapeGateway::class))->getFileName(),
        );
        $monthly = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordMonthlyMcpSource::class))->getFileName(),
        );

        self::assertStringContainsString('KeywordLandscapeReadModel', $gateway);
        self::assertStringContainsString('readModel->forSite', $gateway);
        self::assertStringContainsString('KeywordLandscapeGateway', $monthly);
        self::assertStringContainsString('landscape->forSite', $monthly);
        self::assertStringNotContainsString('KeywordLandscapeReadModel', $monthly);
    }

    public function test_seo_audit_and_discover_consume_gateway_only(): void
    {
        $audit = (string) file_get_contents(
            (string) (new ReflectionClass(AuditNoteClusterSuggestionQuery::class))->getFileName(),
        );
        $discover = (string) file_get_contents(
            (string) (new ReflectionClass(DiscoverNewTopicsService::class))->getFileName(),
        );

        self::assertStringContainsString('KeywordLandscapeGateway', $audit);
        self::assertStringContainsString('landscape->forSite', $audit);
        self::assertStringNotContainsString('KeywordLandscapeReadModel', $audit);

        self::assertStringContainsString('KeywordLandscapeGateway', $discover);
        self::assertStringContainsString('landscape->forSite', $discover);
        self::assertStringContainsString('encodeLandscape', $discover);
        self::assertStringNotContainsString('KeywordLandscapeReadModel', $discover);
    }

    public function test_topical_map_hides_excluded_leaves_and_uses_eligible_counts(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('SkipKeywordFromMcpService', $src);
        self::assertStringContainsString('mcpEligibleKeywordIdsForTopic', $src);
        self::assertStringContainsString('mcpEligibleKeywordCountsByTopicIds', $src);
        self::assertStringContainsString('skippedKeywordIdMap', $src);
    }

    public function test_topical_map_audit_uses_filtered_overview(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAuditService::class))->getFileName(),
        );

        self::assertStringContainsString('topicalMap->overview', $src);
        self::assertStringContainsString('structuralProjection', $src);
        self::assertStringContainsString('mcpContext->build', $src);
    }

    public function test_topic_list_share_and_coverage_exclude_mcp_quarantine(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicListQuery::class))->getFileName(),
        );

        self::assertStringContainsString('SkipKeywordFromMcpService', $src);
        self::assertStringContainsString('mcpExcludedKeywordIdsForTopics', $src);
        self::assertStringContainsString('countForTopics($siteId, $allSiteTopicIds, $excludedKeywordIds)', $src);
        self::assertStringContainsString('forTopics($siteId, $topicIds, $siteArticleCounts, $excludedKeywordIds)', $src);
    }

    public function test_topic_detail_keeps_raw_members_for_inspection(): void
    {
        $detail = (string) file_get_contents(
            (string) (new ReflectionClass(TopicDetailQuery::class))->getFileName(),
        );
        $counter = (string) file_get_contents(
            (string) (new ReflectionClass(TopicLinkedArticleCounter::class))->getFileName(),
        );
        $metrics = (string) file_get_contents(
            (string) (new ReflectionClass(TopicTagMetricsResolver::class))->getFileName(),
        );

        self::assertStringContainsString('paginateMembers', $detail);
        self::assertStringNotContainsString('SkipKeywordFromMcpService', $detail);
        self::assertStringContainsString('excludeKeywordIds', $counter);
        self::assertStringContainsString('excludeKeywordIds', $metrics);
    }

    public function test_relationship_type2_exposes_mcp_excluded_flag(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("'mcp_excluded'", $src);
        self::assertStringContainsString('isSkipped', $src);
        self::assertStringContainsString('skippedKeywordIdMap', $src);
        self::assertTrue(
            (new ReflectionMethod(KeywordRelationshipReadModel::class, 'relationship'))->isPublic(),
        );
    }

    public function test_ui_actions_reuse_skip_service_with_confirmation_copy(): void
    {
        $actions = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithKeywordItemActions::class))->getFileName(),
        );
        $list = (string) file_get_contents(
            (string) (new ReflectionClass(ListKeywords::class))->getFileName(),
        );
        $presenter = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordItemPresenter::class))->getFileName(),
        );

        self::assertStringContainsString('SkipKeywordFromMcpService', $actions);
        self::assertStringContainsString('->skip(', $actions);
        self::assertStringContainsString('->restore(', $actions);

        self::assertStringContainsString('requiresConfirmation()', $list);
        self::assertStringContainsString('keyword_item_skip_mcp_confirm', $list);

        self::assertStringContainsString('mcp_excluded', $presenter);
        self::assertStringContainsString('keyword_item_tag_mcp_skipped', $presenter);
        self::assertStringContainsString('keyword_item_tag_mcp_included', $presenter);
        self::assertStringContainsString('CONTEXT_CLUSTER', $presenter);
    }

    public function test_exclude_action_does_not_call_hide_or_ai(): void
    {
        $actions = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithKeywordItemActions::class))->getFileName(),
        );
        $method = $this->methodBody($actions, 'skipKeywordFromMcp');

        self::assertStringContainsString('SkipKeywordFromMcpService', $method);
        self::assertStringNotContainsString('HideKeywordFromSeoService', $method);
        self::assertStringNotContainsString('PromptRunner', $method);
        self::assertStringNotContainsString('recluster', strtolower($method));
        self::assertStringNotContainsString('dissolve', strtolower($method));
    }

    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*[^{]+)?\{/';
        if (! preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            self::fail('Method '.$method.' not found');
        }
        $start = (int) $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($src);
        for ($i = $start; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
        }

        return '';
    }
}
