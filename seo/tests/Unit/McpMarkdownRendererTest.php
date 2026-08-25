<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Enums\McpPeriodStatus;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpReport;
use Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpAiContextBuilder;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpMarkdownRenderer;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpTokenEstimator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class McpMarkdownRendererTest extends TestCase
{
    public function test_site_markdown_contains_core_metrics(): void
    {
        $markdown = $this->invokeRenderSite('domain-a.test', '2026-07', $this->siteSnapshot([
            'article_total' => 212,
            'article_published' => 211,
            'categories' => 28,
            'internal_links' => 1245,
            'internally_linked_articles' => 57,
            'health' => 'healthy',
            'indexable' => 198,
            'noindex' => 14,
        ], [
            'content_distribution' => ['posts' => 200, 'pages' => 12, 'categories' => 28, 'products' => 0, 'product_categories' => 0, 'other' => 0],
            'publishing_status' => ['published' => 211, 'draft' => 1, 'scheduled' => 0, 'private' => 0, 'other' => 0],
            'link_health' => [
                'internal_links' => 1245,
                'internally_linked_articles' => 57,
                'articles_without_internal_links' => 12,
                'articles_single_internal_link' => 5,
                'average_links_per_linked_article' => 21.8,
                'top_linked_articles' => [
                    ['title' => 'Article A', 'internal_links' => 24],
                    ['title' => 'Article B', 'internal_links' => 18],
                ],
            ],
            'findings' => ['top' => [['title' => 'Broken canonical', 'severity' => 'high']]],
        ], 'domain-a.test'));

        self::assertStringContainsString('# Website Intelligence', $markdown);
        self::assertStringContainsString('Articles: 212', $markdown);
        self::assertStringContainsString('Categories: 28', $markdown);
        self::assertStringContainsString('Internal links: 1,245', $markdown);
        self::assertStringContainsString('Internally linked articles: 57', $markdown);
        self::assertStringContainsString('Articles receiving internal links: 57', $markdown);
        self::assertStringContainsString('Article A — 24 internal links', $markdown);
        self::assertStringContainsString('Pages: 12', $markdown);
    }

    public function test_keyword_markdown_contains_core_metrics(): void
    {
        $markdown = $this->invokeRenderKeywords('domain-a.test', '2026-07', $this->keywordSnapshot([
            'focus' => 414,
            'error' => 2,
            'excluded' => 9,
            'clusters' => 4,
            'total' => 500,
            'linked' => 57,
        ], [
            'clusters' => [
                ['name' => 'Xưởng may Hợp Phát', 'keyword_count' => 30, 'article_count' => 25, 'coverage' => 'healthy'],
            ],
            'weak_clusters' => [
                ['name' => 'Túi vải canvas', 'keyword_count' => 18, 'article_count' => 4, 'coverage' => 'weak'],
            ],
        ], [
            'generation_context' => [
                'missing_directions' => [['cluster' => 'Túi vải canvas', 'direction' => 'price']],
                'intent_gaps' => [['cluster' => 'Túi vải canvas', 'missing_intent' => 'commercial']],
            ],
        ]));

        self::assertStringContainsString('# Keyword Intelligence', $markdown);
        self::assertStringContainsString('Focus keywords: 414', $markdown);
        self::assertStringContainsString('Errors: 2', $markdown);
        self::assertStringContainsString('Topic clusters: 4', $markdown);
        self::assertStringContainsString('### Xưởng may Hợp Phát', $markdown);
        self::assertStringContainsString('Linked articles: 25', $markdown);
    }

    public function test_combined_contains_site_and_keywords_sections(): void
    {
        $siteMd = $this->invokeRenderSite('domain-a.test', '2026-07', $this->siteSnapshot(['article_total' => 100, 'internally_linked_articles' => 57], [], 'domain-a.test'));
        $kwMd = $this->invokeRenderKeywords('domain-a.test', '2026-07', $this->keywordSnapshot(['focus' => 40, 'clusters' => 3, 'total' => 50], []));
        $markdown = $this->invokeRenderCombined('domain-a.test', '2026-07', $siteMd, $kwMd, $this->reportWithSynthesis(), $this->siteSnapshot(['article_total' => 100], [], 'domain-a.test'), $this->keywordSnapshot(['focus' => 40], []));

        self::assertStringContainsString('# SEO MCP Intelligence', $markdown);
        self::assertStringContainsString('# 1. Website Intelligence', $markdown);
        self::assertStringContainsString('# 2. Keyword Intelligence', $markdown);
        self::assertStringContainsString('# 3. Key Findings', $markdown);
        self::assertStringContainsString('# 4. SEO Opportunities', $markdown);
        self::assertStringContainsString('# 5. Issues Requiring Attention', $markdown);
        self::assertStringContainsString('Internally linked articles: 57', $markdown);
        self::assertStringContainsString('Focus keywords: 40', $markdown);
    }

    public function test_domain_isolation(): void
    {
        $markdownA = $this->invokeRenderCombined(
            'domain-a.test',
            '2026-07',
            $this->invokeRenderSite('domain-a.test', '2026-07', $this->siteSnapshot(['article_total' => 100], [], 'domain-a.test')),
            $this->invokeRenderKeywords('domain-a.test', '2026-07', $this->keywordSnapshot(['focus' => 10], [])),
            null,
            $this->siteSnapshot(['article_total' => 100], [], 'domain-a.test'),
            $this->keywordSnapshot(['focus' => 10], []),
        );
        $markdownB = $this->invokeRenderCombined(
            'domain-b.test',
            '2026-07',
            $this->invokeRenderSite('domain-b.test', '2026-07', $this->siteSnapshot(['article_total' => 200], [], 'domain-b.test')),
            $this->invokeRenderKeywords('domain-b.test', '2026-07', $this->keywordSnapshot(['focus' => 99], [])),
            null,
            $this->siteSnapshot(['article_total' => 200], [], 'domain-b.test'),
            $this->keywordSnapshot(['focus' => 99], []),
        );

        self::assertNotSame($markdownA, $markdownB);
        self::assertStringContainsString('domain-a.test', $markdownA);
        self::assertStringContainsString('domain-b.test', $markdownB);
    }

    public function test_period_isolation(): void
    {
        $july = $this->invokeRenderCombined(
            'domain-a.test',
            '2026-07',
            $this->invokeRenderSite('domain-a.test', '2026-07', $this->siteSnapshot(['article_total' => 100], [], 'domain-a.test')),
            $this->invokeRenderKeywords('domain-a.test', '2026-07', $this->keywordSnapshot(['focus' => 10], [])),
            null,
            $this->siteSnapshot(['article_total' => 100], [], 'domain-a.test'),
            $this->keywordSnapshot(['focus' => 10], []),
        );
        $august = $this->invokeRenderCombined(
            'domain-a.test',
            '2026-08',
            $this->invokeRenderSite('domain-a.test', '2026-08', $this->siteSnapshot(['article_total' => 150], [], 'domain-a.test')),
            $this->invokeRenderKeywords('domain-a.test', '2026-08', $this->keywordSnapshot(['focus' => 20], [])),
            null,
            $this->siteSnapshot(['article_total' => 150], [], 'domain-a.test'),
            $this->keywordSnapshot(['focus' => 20], []),
        );

        self::assertNotSame($july, $august);
        self::assertStringContainsString('Period: 07/2026', $july);
        self::assertStringContainsString('Period: 08/2026', $august);
    }

    public function test_ai_context_builder_contains_combined_body(): void
    {
        $combined = $this->invokeRenderCombined(
            'domain-a.test',
            '2026-07',
            $this->invokeRenderSite('domain-a.test', '2026-07', $this->siteSnapshot(['internally_linked_articles' => 57], [], 'domain-a.test')),
            $this->invokeRenderKeywords('domain-a.test', '2026-07', $this->keywordSnapshot(['focus' => 12], [])),
            $this->reportWithSynthesis(),
            $this->siteSnapshot(['internally_linked_articles' => 57], [], 'domain-a.test'),
            $this->keywordSnapshot(['focus' => 12], []),
        );

        $aiContext = "# MCP Context\n\n- Domain: domain-a.test\n\n---\n\n".$combined;

        self::assertStringContainsString('# MCP Context', $aiContext);
        self::assertStringContainsString($combined, $aiContext);
        self::assertStringContainsString('Internally linked articles: 57', $aiContext);
        self::assertStringNotContainsString('seo-content-ai::filament', $aiContext);
    }

    public function test_linked_article_metric_uses_total_not_sample_size(): void
    {
        $top = [];
        for ($i = 1; $i <= 3; $i++) {
            $top[] = ['title' => "Article {$i}", 'internal_links' => 20 - $i];
        }
        $markdown = $this->invokeRenderSite('domain-a.test', '2026-07', $this->siteSnapshot(['internally_linked_articles' => 57], [
            'link_health' => [
                'internal_links' => 900,
                'internally_linked_articles' => 57,
                'top_linked_articles' => $top,
            ],
        ], 'domain-a.test'));

        self::assertStringContainsString('Internally linked articles: 57', $markdown);
        self::assertStringContainsString('Total linked articles: 57', $markdown);
        self::assertStringNotContainsString('Total linked articles: 3', $markdown);
    }

    public function test_token_estimator_returns_positive_estimate(): void
    {
        $estimator = new McpTokenEstimator;
        $result = $estimator->estimate(str_repeat('abcd ', 100));

        self::assertSame(500, $result['characters']);
        self::assertGreaterThan(0, $result['estimated_tokens']);
    }

    public function test_filament_page_uses_markdown_renderer_not_readable_presenter(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\Seo\Filament\Pages\McpIntelligence::class))->getFileName());
        self::assertStringContainsString('McpMarkdownRenderer', $src);
        self::assertStringContainsString('McpAiContextBuilder', $src);
        self::assertStringContainsString('markdownBundle', $src);
        self::assertStringNotContainsString('readableSections', $src);

        $builderSrc = (string) file_get_contents((new ReflectionClass(McpAiContextBuilder::class))->getFileName());
        self::assertStringContainsString('renderCombined', $builderSrc);
        self::assertStringNotContainsString('readableSections', $builderSrc);

        $blade = (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/mcp-intelligence.blade.php');
        self::assertStringContainsString('view_markdown', $blade);
        self::assertStringContainsString('markdownOpen', $blade);
        self::assertStringContainsString('domain-context-changed', $blade);
        self::assertStringNotContainsString('ai_readable', $blade);
    }

    private function invokeRenderSite(string $domain, string $periodKey, SeoMcpSourceSnapshot $snap): string
    {
        $method = new ReflectionMethod(McpMarkdownRenderer::class, 'renderSiteFromSnapshot');

        return (string) $method->invoke($this->renderer(), $domain, $periodKey, $snap, $this->period($periodKey));
    }

    private function invokeRenderKeywords(string $domain, string $periodKey, SeoMcpSourceSnapshot $snap): string
    {
        $method = new ReflectionMethod(McpMarkdownRenderer::class, 'renderKeywordsFromSnapshot');

        return (string) $method->invoke($this->renderer(), $domain, $periodKey, $snap, $this->period($periodKey));
    }

    private function invokeRenderCombined(
        string $domain,
        string $periodKey,
        string $siteMd,
        string $kwMd,
        ?SeoMcpReport $report,
        SeoMcpSourceSnapshot $siteSnap,
        SeoMcpSourceSnapshot $keywordSnap,
    ): string {
        $method = new ReflectionMethod(McpMarkdownRenderer::class, 'renderCombinedBody');

        return (string) $method->invoke(
            $this->renderer(),
            $domain,
            $periodKey,
            $this->period($periodKey),
            $report,
            $siteSnap,
            $keywordSnap,
            null,
            $siteMd,
            $kwMd,
            '# GSC Intelligence'."\n\n".'No GSC snapshot.',
        );
    }

    private function renderer(): McpMarkdownRenderer
    {
        return new McpMarkdownRenderer(
            new \Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodService(new \Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodPolicy()),
            new \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSnapshotService(
                new \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSourceRegistry([]),
                new \Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodPolicy(),
            ),
            new \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpReportService(
                new \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSnapshotService(
                    new \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSourceRegistry([]),
                    new \Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodPolicy(),
                ),
                new \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpReportBuilder(),
                new \Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodPolicy(),
                new \Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodService(new \Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodPolicy()),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $summary
     */
    private function siteSnapshot(array $metrics, array $summary, string $domain = 'domain-a.test'): SeoMcpSourceSnapshot
    {
        $summary['identity'] = array_merge(['site_id' => 4, 'domain' => $domain], $summary['identity'] ?? []);

        return $this->snapshot(McpSourceKey::Site, $metrics, $summary);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $context
     */
    private function keywordSnapshot(array $metrics, array $summary, array $context = []): SeoMcpSourceSnapshot
    {
        return $this->snapshot(McpSourceKey::Keywords, $metrics, $summary, $context);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $context
     */
    private function snapshot(McpSourceKey $source, array $metrics, array $summary, array $context = []): SeoMcpSourceSnapshot
    {
        $row = new SeoMcpSourceSnapshot([
            'source' => $source->value,
            'status' => 'current',
            'metrics_json' => $metrics,
            'summary_json' => $summary,
            'context_json' => $context,
        ]);
        $row->id = random_int(100, 999);

        return $row;
    }

    private function reportWithSynthesis(): SeoMcpReport
    {
        return new SeoMcpReport([
            'status' => 'ready',
            'highlights_json' => [['key' => 'strong_group', 'name' => 'Vật liệu', 'count' => 23]],
            'opportunities_json' => [['key' => 'weak_cluster', 'name' => 'Túi vải canvas', 'keyword_count' => 18, 'article_count' => 4]],
            'risks_json' => [['key' => 'keyword_error', 'count' => 2]],
        ]);
    }

    private function period(string $periodKey): SeoMcpPeriod
    {
        return new SeoMcpPeriod([
            'year' => (int) substr($periodKey, 0, 4),
            'month' => (int) substr($periodKey, 5, 2),
            'status' => McpPeriodStatus::Open->value,
        ]);
    }
}
