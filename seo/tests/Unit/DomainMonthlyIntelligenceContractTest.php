<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordGenerationContextBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMcpContextBuilder;
use Omnichannel\Addons\Seo\Filament\Pages\McpIntelligence;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\DomainMonthlyIntelligenceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DomainMonthlyIntelligenceContractTest extends TestCase
{
    public function test_monthly_tool_reads_stored_context_without_rebuild_helpers(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(DomainMonthlyIntelligenceService::class))->getFileName());
        self::assertStringContainsString('ai_context_json', $src);
        self::assertStringContainsString("'rebuilt' => false", $src);
        self::assertStringNotContainsString('refreshLandscapeSnapshot', $src);
        self::assertStringNotContainsString('syncFromSnapshots', $src);
        self::assertStringNotContainsString('ClassifyDirtyKeywordsJob', $src);
    }

    public function test_domain_seo_mcp_routes_monthly_before_live_sync(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\Seo\Services\DomainSeoMcpService::class))->getFileName());
        $monthlyPos = strpos($src, "domain.monthly_intelligence");
        $syncPos = strpos($src, 'syncFromSnapshots');
        self::assertNotFalse($monthlyPos);
        self::assertNotFalse($syncPos);
        self::assertLessThan($syncPos, $monthlyPos);
    }

    public function test_filament_page_exists_with_period_and_ai_preview(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(McpIntelligence::class))->getFileName());
        self::assertStringContainsString('mcp-intelligence', $src);
        self::assertStringContainsString('McpMarkdownRenderer', $src);
        self::assertStringContainsString('McpAiContextBuilder', $src);
        self::assertStringContainsString('periodKey', $src);
        self::assertStringContainsString('refreshKeywordSnapshot', $src);
        self::assertStringContainsString('syncSiteFromGlobalContext', $src);
        self::assertStringContainsString('onDomainContextChanged', $src);

        $blade = (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/mcp-intelligence.blade.php');
        self::assertStringNotContainsString('wire:model.live="siteId"', $blade);
        self::assertStringContainsString('view_markdown', $blade);
        self::assertStringContainsString('view_ai_context', $blade);
        self::assertStringContainsString('markdownOpen', $blade);
        self::assertStringContainsString('domain-context-changed', $blade);
        self::assertStringContainsString('SITE OVERVIEW', $blade);
        self::assertStringContainsString('KEYWORD OVERVIEW', $blade);
        self::assertStringContainsString('mcp-linked-section', $blade);
        self::assertStringContainsString('wire:key="mcp-dashboard-', $blade);
        self::assertStringNotContainsString('mcp-top-clusters', $blade);
        self::assertStringNotContainsString('mcp_intelligence.overview', $blade);
    }

    public function test_keyword_builder_keeps_compact_budget(): void
    {
        $builder = (new ReflectionClass(KeywordMcpContextBuilder::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(KeywordMcpContextBuilder::class, 'fromPrepared');
        $out = $method->invoke($builder, [
            'raw_keywords' => 110,
            'cluster_count' => 2,
            'clusters' => [
                [
                    'cluster' => '12',
                    'primary' => 'Balo vải bố',
                    'usable_keyword_count' => 18,
                    'target_pages' => 4,
                    'coverage' => 'weak',
                    'intent_coverage' => ['informational'],
                ],
            ],
        ], [
            'focus' => 96,
            'error' => 5,
            'seo_excluded' => 9,
            'has_link' => 40,
        ], [
            ['key' => 'group:1', 'label' => 'Vật liệu', 'count' => 23],
        ], (new KeywordGenerationContextBuilder())->build([
            'clusters' => [
                ['cluster' => '12', 'primary' => 'Balo vải bố', 'coverage' => 'weak', 'usable_keyword_count' => 18, 'missing_directions' => ['price'], 'intent_gaps' => ['commercial'], 'representative_variants' => ['balo vai bo']],
            ],
        ]), '2026-08', 4, '2026-08-15T00:00:00+00:00');

        self::assertSame(110, $out['metrics']['total']);
        self::assertSame(96, $out['metrics']['focus']);
        self::assertArrayNotHasKey('keywords', $out['summary']);
        self::assertLessThanOrEqual(KeywordMcpContextBuilder::MAX_CLUSTERS, count($out['summary']['clusters']));
        self::assertSame('keywords.mcp.v1', $out['context']['schema']);
    }

    public function test_finalized_period_blocks_silent_overwrite(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSnapshotService::class))->getFileName());
        self::assertStringContainsString('assertOpen', $src);
        $report = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpReportService::class))->getFileName());
        self::assertStringContainsString('assertOpen', $report);
    }
}
