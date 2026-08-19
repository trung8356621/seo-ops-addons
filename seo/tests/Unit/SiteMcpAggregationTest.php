<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpDataQualityGuard;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpMarkdownRenderer;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\SiteMcpContentDistributionAggregator;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\SiteMcpInternalLinkingAggregator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SiteMcpAggregationTest extends TestCase
{
    public function test_missing_distribution_renders_na_not_zero(): void
    {
        $markdown = $this->invokeRenderSite('domain.test', '2026-07', $this->siteSnapshot([
            'article_total' => 212,
        ], [
            'articles' => ['total' => 212, 'published' => 211],
        ], 'domain.test'));

        self::assertStringContainsString('Posts: N/A', $markdown);
        self::assertStringNotContainsString('Posts: 0', $markdown);
        self::assertStringContainsString('Refresh the MCP report', $markdown);
    }

    public function test_content_distribution_values_preserved_in_markdown(): void
    {
        $distribution = [
            'available' => true,
            'posts' => 100,
            'pages' => 10,
            'categories' => 8,
            'products' => 20,
            'product_categories' => 4,
            'other' => 2,
            'sources' => ['wp_manifest.posts'],
            'warnings' => [],
        ];
        $markdown = $this->invokeRenderSite('domain.test', '2026-07', $this->siteSnapshot([], [
            'content_distribution' => $distribution,
            'internal_linking' => $this->linkingFixture(5, 2, 1, 2.5),
        ], 'domain.test'));

        self::assertStringContainsString('Posts: 100', $markdown);
        self::assertStringContainsString('Pages: 10', $markdown);
        self::assertStringContainsString('Categories: 8', $markdown);
        self::assertStringContainsString('Products: 20', $markdown);
        self::assertStringContainsString('Product categories: 4', $markdown);
        self::assertStringContainsString('Other: 2', $markdown);
    }

    public function test_internal_linking_metrics_in_markdown(): void
    {
        $markdown = $this->invokeRenderSite('domain.test', '2026-07', $this->siteSnapshot([], [
            'content_distribution' => ['available' => true, 'posts' => 10, 'pages' => 1, 'categories' => 2, 'products' => 0, 'product_categories' => 0, 'other' => 0, 'sources' => [], 'warnings' => []],
            'internal_linking' => $this->linkingFixture(5, 2, 1, 2.5),
        ], 'domain.test'));

        self::assertStringContainsString('Total internal links: 5', $markdown);
        self::assertStringContainsString('Articles receiving internal links: 2', $markdown);
        self::assertStringContainsString('Average links per linked article: 2.5', $markdown);
        self::assertStringContainsString('Articles without internal links: 1', $markdown);
    }

    public function test_data_quality_guard_flags_zero_distribution_with_articles(): void
    {
        $guard = new McpDataQualityGuard;
        $warnings = $guard->siteWarnings(212, [
            'available' => true,
            'posts' => 0,
            'pages' => 0,
            'categories' => 0,
            'products' => 0,
            'product_categories' => 0,
            'other' => 0,
            'warnings' => [],
        ], ['linked_articles' => 2, 'eligible_articles' => 10, 'warnings' => []]);

        self::assertContains('content distribution sums to zero while article_total=212', $warnings);
    }

    public function test_keyword_cluster_sort_tie_breaker_by_linked_articles(): void
    {
        $method = new ReflectionMethod(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMcpContextBuilder::class, 'compareClusters');
        $method->setAccessible(true);
        $builder = (new ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMcpContextBuilder::class))
            ->newInstanceWithoutConstructor();

        $clusters = [
            ['name' => 'Cluster A', 'keyword_count' => 10, 'linked_articles_count' => 5],
            ['name' => 'Cluster B', 'keyword_count' => 10, 'linked_articles_count' => 20],
        ];
        usort($clusters, static fn (array $a, array $b): int => $method->invoke($builder, $a, $b));

        self::assertSame('Cluster B', $clusters[0]['name']);
        self::assertSame('Cluster A', $clusters[1]['name']);
    }

    public function test_keyword_cluster_markdown_uses_full_keyword_counts(): void
    {
        $markdown = $this->invokeRenderKeywords('domain.test', '2026-07', $this->keywordSnapshot([
            'clusters' => [
                ['name' => 'Cluster A', 'keyword_count' => 25, 'linked_articles_count' => 10, 'coverage' => 'healthy'],
                ['name' => 'Cluster B', 'keyword_count' => 10, 'linked_articles_count' => 20, 'coverage' => 'healthy'],
            ],
        ]));

        self::assertStringContainsString('Keywords: 25', $markdown);
        self::assertStringContainsString('Linked articles: 10', $markdown);
    }

    public function test_keyword_builder_limits_after_full_sort(): void
    {
        $clusters = [];
        for ($i = 1; $i <= 30; $i++) {
            $clusters[] = [
                'cluster' => "c{$i}",
                'primary' => "Cluster {$i}",
                'usable_keyword_count' => $i,
                'target_pages' => $i,
                'coverage' => 'healthy',
                'intent_coverage' => [],
            ];
        }

        $builder = (new ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMcpContextBuilder::class))
            ->newInstanceWithoutConstructor();

        $payload = $builder->fromPrepared(
            ['clusters' => $clusters, 'cluster_count' => 30, 'raw_keywords' => 465],
            ['_total' => 465],
            [],
            ['core_topics' => [], 'saturated_topics' => [], 'weak_topics' => [], 'missing_directions' => [], 'intent_gaps' => [], 'existing_canonicals' => [], 'generation_rules' => []],
            '2026-07',
            4,
            null,
        );

        $out = $payload['summary']['clusters'];
        self::assertCount(25, $out);
        self::assertSame('Cluster 30', $out[0]['name']);
        self::assertSame('Cluster 6', $out[24]['name']);
    }

    public function test_keyword_builder_sorts_full_clusters_before_limit(): void
    {
        $builder = (new ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMcpContextBuilder::class))
            ->newInstanceWithoutConstructor();

        $payload = $builder->fromPrepared(
            ['clusters' => [
                ['cluster' => 'a', 'primary' => 'Cluster A', 'usable_keyword_count' => 10, 'target_pages' => 5, 'coverage' => 'healthy', 'intent_coverage' => []],
                ['cluster' => 'b', 'primary' => 'Cluster B', 'usable_keyword_count' => 10, 'target_pages' => 20, 'coverage' => 'healthy', 'intent_coverage' => []],
                ['cluster' => 'c', 'primary' => 'Cluster C', 'usable_keyword_count' => 2, 'target_pages' => 1, 'coverage' => 'weak', 'intent_coverage' => []],
            ], 'cluster_count' => 3, 'raw_keywords' => 22],
            ['_total' => 22],
            [],
            ['core_topics' => [], 'saturated_topics' => [], 'weak_topics' => [], 'missing_directions' => [], 'intent_gaps' => [], 'existing_canonicals' => [], 'generation_rules' => []],
            '2026-07',
            4,
            null,
        );

        self::assertSame(['Cluster B', 'Cluster A', 'Cluster C'], array_column($payload['summary']['clusters'], 'name'));
    }

    public function test_aggregator_classes_exist(): void
    {
        self::assertTrue(class_exists(SiteMcpContentDistributionAggregator::class));
        self::assertTrue(class_exists(SiteMcpInternalLinkingAggregator::class));
        self::assertTrue(class_exists(McpDataQualityGuard::class));

        $builder = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\Seo\Services\MonthlyMcp\SiteMcpContextBuilder::class))->getFileName());
        self::assertStringContainsString('SiteMcpContentDistributionAggregator', $builder);
        self::assertStringContainsString('SiteMcpInternalLinkingAggregator', $builder);
        self::assertStringNotContainsString('internalLinkArticleStats', $builder);

        $kw = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMcpContextBuilder::class))->getFileName());
        self::assertStringContainsString('refreshLandscapeSnapshot', $kw);
        self::assertStringContainsString('linked_articles_count', $kw);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $summary
     */
    private function siteSnapshot(array $metrics, array $summary, string $domain = 'domain.test'): \Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot
    {
        $summary['identity'] = array_merge(['site_id' => 4, 'domain' => $domain], $summary['identity'] ?? []);

        return new \Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot([
            'source' => 'site',
            'status' => 'current',
            'metrics_json' => $metrics,
            'summary_json' => $summary,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function linkingFixture(int $total, int $linked, int $without, float $average): array
    {
        return [
            'total_internal_links' => $total,
            'linked_articles' => $linked,
            'average_links_per_linked_article' => $average,
            'articles_without_internal_links' => $without,
            'eligible_articles' => $linked + $without,
            'articles_single_internal_link' => 0,
            'top_linked_articles' => [],
            'available' => true,
            'source' => 'link_maps',
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function keywordSnapshot(array $summary): \Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot
    {
        return new \Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot([
            'source' => 'keywords',
            'status' => 'current',
            'metrics_json' => ['total' => 100, 'focus' => 10, 'clusters' => 3],
            'summary_json' => $summary,
        ]);
    }

    private function invokeRenderKeywords(string $domain, string $periodKey, \Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot $snap): string
    {
        $method = new ReflectionMethod(McpMarkdownRenderer::class, 'renderKeywordsFromSnapshot');
        $renderer = (new ReflectionClass(McpMarkdownRenderer::class))->newInstanceWithoutConstructor();

        return (string) $method->invoke(
            $renderer,
            $domain,
            $periodKey,
            $snap,
            new \Omnichannel\Addons\Seo\Models\SeoMcpPeriod(['year' => 2026, 'month' => 7, 'status' => 'open']),
        );
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $summary
     */
    private function invokeRenderSite(string $domain, string $periodKey, \Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot $snap): string
    {
        $method = new ReflectionMethod(McpMarkdownRenderer::class, 'renderSiteFromSnapshot');
        $renderer = (new ReflectionClass(McpMarkdownRenderer::class))->newInstanceWithoutConstructor();

        return (string) $method->invoke(
            $renderer,
            $domain,
            $periodKey,
            $snap,
            new \Omnichannel\Addons\Seo\Models\SeoMcpPeriod(['year' => 2026, 'month' => 7, 'status' => 'open']),
        );
    }
}
