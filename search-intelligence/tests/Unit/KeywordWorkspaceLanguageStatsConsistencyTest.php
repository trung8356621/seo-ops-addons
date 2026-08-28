<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class KeywordWorkspaceLanguageStatsConsistencyTest extends TestCase
{
    public function test_cluster_site_scope_supports_language_and_dictionary_aligned_flags(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/KeywordIntelligence/KeywordClusterSiteScope.php');

        $this->assertStringContainsString('excludeSuggest', $src);
        $this->assertStringContainsString('requireLinkedSource', $src);
        $this->assertStringContainsString('KeywordWorkspaceLanguageScope', $src);
        $this->assertStringContainsString('TYPE_SUGGEST', $src);
    }

    public function test_cluster_ui_summary_uses_linked_language_inventory(): void
    {
        $query = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/KeywordIntelligence/KeywordClusterQuery.php');
        $eligibility = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/KeywordIntelligence/KeywordClusterEligibility.php');

        $this->assertStringContainsString('KeywordUiInventoryQuery', $query);
        $this->assertStringContainsString('summaryMetricsForKeywordIds', $eligibility);
        $this->assertStringContainsString('languageVariants', $eligibility);
    }

    public function test_cluster_index_exposes_seo_eligible_denominator(): void
    {
        $blade = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));
        $page = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');

        $this->assertStringContainsString('topic_summary_seo_eligible', $blade);
        $this->assertStringContainsString('topic_summary_denominator_line', $blade);
        $this->assertStringContainsString('seo_eligible_keywords', $blade);
        $this->assertStringContainsString('resolveKeywordLanguageFilterVariants', $page);
    }

    public function test_classification_summary_reuses_ui_inventory_query(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Support/KeywordIntelligence/KeywordClassificationVisibility.php');

        $this->assertStringContainsString('KeywordUiInventoryQuery', $src);
        $this->assertStringContainsString('languageVariants', $src);
        $this->assertStringNotContainsString('KeywordClusterSiteScope::apply', $src);
    }

    public function test_mcp_preview_summary_accepts_language_variants(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/KeywordIntelligence/ClusterIndexMcpPreviewSummary.php');

        $this->assertStringContainsString('?array $languageVariants = null', $src);
        $this->assertStringContainsString('clusterAggregates($siteId, 500, $languageVariants)', $src);
    }
}
