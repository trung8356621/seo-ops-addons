<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicCoverageCalculator;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDissolveService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicManualCreateService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTagMetricsResolver;
use PHPUnit\Framework\TestCase;

/**
 * Topic UX completion: tags, manual create, dissolve toast, drawer/article contracts.
 */
final class TopicUxCompletionContractTest extends TestCase
{
    public function test_coverage_calculator_levels(): void
    {
        $calc = new TopicCoverageCalculator;
        self::assertSame('unknown', $calc->coverage(0, 0, 0, 0));
        self::assertSame('strong', $calc->coverage(8, 3, 3, 2));
        self::assertSame('medium', $calc->coverage(4, 1, 0, 0));
        self::assertSame('medium', $calc->coverage(4, 0, 2, 0));
        self::assertSame('medium', $calc->coverage(4, 0, 0, 2));
        self::assertSame('weak', $calc->coverage(4, 0, 0, 0));
        self::assertSame('weak', $calc->coverage(2, 5, 5, 5));
    }

    public function test_list_query_uses_tag_metrics_resolver(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicListQuery.php');
        self::assertStringContainsString('TopicTagMetricsResolver', $src);
        self::assertStringContainsString('tagMetrics->forTopics', $src);
        self::assertStringContainsString("'intent' => (string) (\$tags['intent'] ?? '')", $src);
        self::assertStringContainsString("'coverage' => (string) (\$tags['coverage'] ?? 'unknown')", $src);
    }

    public function test_detail_query_exposes_intent_coverage_source(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicDetailQuery.php');
        self::assertStringContainsString('TopicTagMetricsResolver', $src);
        self::assertStringContainsString("'intent' => (string)", $src);
        self::assertStringContainsString("'coverage' => (string)", $src);
        self::assertStringContainsString('linked_articles_count', $src);
        self::assertStringContainsString("where('site_id', \$siteId)", $src);
    }

    public function test_index_and_detail_blades_keep_tag_partial(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php');
        $detail = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php');
        self::assertStringContainsString('cluster-intent-coverage-tags', $index);
        self::assertStringContainsString('cluster-intent-coverage-tags', $detail);
        self::assertStringContainsString('quickCreateTopic', $index);
        self::assertStringContainsString('topic_quick_create_action', $index);
        self::assertStringContainsString('data-keyword-detail-row', $detail);
        self::assertStringContainsString('data-keyword-id', $detail);
    }

    public function test_manual_create_service_contract(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicManualCreateService.php');
        self::assertStringContainsString('TopicKeywordSource::MANUAL', $src);
        self::assertStringContainsString("'is_seed' => true", $src);
        self::assertStringContainsString('updateOrCreate', $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
        self::assertStringNotContainsString('CreateManualTopicClusterService', $src);
        self::assertSame(TopicKeywordSource::MANUAL, 'manual');
    }

    public function test_seed_resolver_includes_manual_third_source(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php');
        self::assertStringContainsString('manualSeeds', $src);
        self::assertStringContainsString('TopicKeywordSource::MANUAL', $src);
        $linkPos = strpos($src, 'linkListSeeds');
        $catPos = strpos($src, 'productCatSeeds');
        $manPos = strpos($src, 'manualSeeds');
        self::assertNotFalse($linkPos);
        self::assertNotFalse($catPos);
        self::assertNotFalse($manPos);
        self::assertTrue($linkPos < $catPos && $catPos < $manPos);
    }

    public function test_recluster_metrics_track_manual_seeds(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicReclusterService.php');
        self::assertStringContainsString("'seeds_manual'", $src);
    }

    public function test_dissolve_returns_topic_name_and_notification_uses_it(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicDissolveService.php');
        $concern = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/DissolvesTopics.php');
        self::assertStringContainsString('topic_name', $service);
        self::assertStringContainsString("\$topicName = (string) \$topic->name", $service);
        self::assertStringContainsString('trans_choice', $concern);
        self::assertStringContainsString("\$result['topic_name']", $concern);
        self::assertStringNotContainsString("'#' . \$topicId", $concern);
        self::assertStringNotContainsString("'#'.\$topicId", $concern);
        self::assertTrue(class_exists(TopicDissolveService::class));
        self::assertTrue(class_exists(TopicManualCreateService::class));
        self::assertTrue(class_exists(TopicSeedResolver::class));
        self::assertTrue(class_exists(TopicTagMetricsResolver::class));
    }

    public function test_dissolve_translation_is_plural_choice_string(): void
    {
        $en = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/lang/en/filament.php');
        self::assertStringContainsString(
            "topic_dissolve_success_body' => '{0} No keywords were returned to unassigned.|{1} :count keyword returned to unassigned.|[2,*] :count keywords returned to unassigned.'",
            $en,
        );
    }

    public function test_drawer_js_binds_generic_keyword_detail_row(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 4).'/seo/resources/js/keywordDetailPanel.js');
        self::assertStringContainsString('[data-keyword-detail-row]', $js);
        self::assertStringContainsString("getAttribute('data-keyword-id')", $js);
        self::assertStringContainsString('interactiveSelector', $js);
        self::assertStringContainsString('.fi-ta-row, [data-keyword-detail-row]', $js);
    }

    public function test_topic_detail_drawer_is_site_scoped_override(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php');
        $drawer = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/InteractsWithKeywordDetailDrawer.php');
        self::assertStringContainsString('keywordDetailDrawerSiteScope', $page);
        self::assertStringContainsString('keywordDetailDrawerSiteScope', $drawer);
        self::assertStringContainsString('$siteScope', $drawer);
        self::assertStringContainsString("where('site_id', \$siteScope)", $drawer);
    }

    public function test_dna_and_dictionary_contracts_preserved(): void
    {
        $presenter = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/KeywordIntelligence/KeywordItemPresenter.php');
        $col = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/tables/columns/keyword-item.blade.php');
        $item = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-item.blade.php');
        self::assertStringContainsString('semanticTags->forKeyword', $presenter);
        self::assertStringContainsString('CONTEXT_DICTIONARY', $col);
        self::assertStringContainsString('keyword-item__semantic', $item);
        self::assertStringContainsString('dnaValues: $dnaValues', $item);
    }

    public function test_no_retired_cluster_runtime(): void
    {
        $files = [
            dirname(__DIR__, 3).'/src/Services/Topic/TopicCoverageCalculator.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicTagMetricsResolver.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicManualCreateService.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicDissolveService.php',
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/DissolvesTopics.php',
        ];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src, basename($file));
            self::assertStringNotContainsString('KeywordClusterQuery', $src, basename($file));
            self::assertStringNotContainsString('CreateManualTopicClusterService', $src, basename($file));
            self::assertStringNotContainsString('seo_topic_cluster_meta', $src, basename($file));
            self::assertStringNotContainsString('KeywordDnaService', $src, basename($file));
            self::assertStringNotContainsString('SiteMcpClusterTopicalProfileBuilder', $src, basename($file));
        }
    }
}
