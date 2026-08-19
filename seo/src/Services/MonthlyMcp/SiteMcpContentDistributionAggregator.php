<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;

/**
 * Canonical Site MCP content-type counts from Site Sync + WP manifest.
 *
 * Posts/pages split uses WP manifest because local articles.type merges post+page → article.
 *
 * @return array{
 *   posts: ?int,
 *   pages: ?int,
 *   categories: ?int,
 *   products: ?int,
 *   product_categories: ?int,
 *   other: ?int,
 *   available: bool,
 *   sources: list<string>,
 *   warnings: list<string>
 * }
 */
final class SiteMcpContentDistributionAggregator
{
    public function __construct(
        private readonly DomainOverviewService $overview,
    ) {}

    /**
     * @return array{
     *   posts: ?int,
     *   pages: ?int,
     *   categories: ?int,
     *   products: ?int,
     *   product_categories: ?int,
     *   other: ?int,
     *   available: bool,
     *   sources: list<string>,
     *   warnings: list<string>
     * }
     */
    public function aggregate(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return $this->unavailable(['articles table missing']);
        }

        $sync = $this->overview->getSyncStatistics($siteId);
        $manifest = $this->manifestCounts($siteId);
        $wpPostTypeCounts = is_array($sync['wp_post_type_counts'] ?? null) ? $sync['wp_post_type_counts'] : [];
        $hasLocal = (int) ($sync['total'] ?? 0) > 0;
        $hasManifest = $manifest !== [];
        $hasWpPostTypeMeta = $wpPostTypeCounts !== [];

        if (! $hasLocal && ! $hasManifest && ! $hasWpPostTypeMeta) {
            return $this->unavailable(['no synced articles or WP manifest']);
        }

        $sources = [];
        $warnings = [];

        $posts = null;
        if ($hasWpPostTypeMeta && array_key_exists('post', $wpPostTypeCounts)) {
            $posts = (int) $wpPostTypeCounts['post'];
            $sources[] = 'meta.wp_post_type.post';
        } elseif ($hasManifest && array_key_exists('article', $manifest)) {
            $posts = (int) $manifest['article'];
            $sources[] = 'wp_manifest.posts';
        } elseif ($hasLocal) {
            $posts = (int) ($sync['articles'] ?? 0);
            $sources[] = 'local.articles';
            $warnings[] = 'pages split unavailable without WP manifest or wp_post_type meta; posts uses local article count (post+page merged locally)';
        }

        $pages = null;
        if ($hasWpPostTypeMeta && array_key_exists('page', $wpPostTypeCounts)) {
            $pages = (int) $wpPostTypeCounts['page'];
            $sources[] = 'meta.wp_post_type.page';
        } elseif ($hasManifest && array_key_exists('page', $manifest)) {
            $pages = (int) $manifest['page'];
            $sources[] = 'wp_manifest.pages';
        }

        $categories = null;
        $localCategories = (int) ($sync['categories'] ?? 0);
        $manifestCategories = (int) ($manifest['category'] ?? 0);
        if ($localCategories > 0) {
            $categories = $localCategories;
            $sources[] = 'local.categories';
        } elseif ($manifestCategories > 0) {
            $categories = $manifestCategories;
            $sources[] = 'wp_manifest.categories';
        } elseif ($hasLocal || $hasManifest) {
            $categories = 0;
            $sources[] = 'confirmed_zero.categories';
        }

        $products = $hasLocal || $hasManifest ? (int) ($sync['products'] ?? 0) : null;
        if ($products !== null) {
            $sources[] = 'local.products';
        }

        $productCategories = $hasLocal || $hasManifest ? (int) ($sync['product_categories'] ?? 0) : null;
        if ($productCategories !== null) {
            $sources[] = 'local.product_categories';
        }

        $other = null;
        if ($hasLocal) {
            $other = (int) ($sync['other'] ?? 0);
            $sources[] = 'local.other';
        }

        return [
            'posts' => $posts,
            'pages' => $pages,
            'categories' => $categories,
            'products' => $products,
            'product_categories' => $productCategories,
            'other' => $other,
            'available' => true,
            'sources' => array_values(array_unique($sources)),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function manifestCounts(int $siteId): array
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return [];
        }
        $raw = $site->getMeta('seo_wp_manifest_counts');
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_array($decoded['counts'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($decoded['counts'] as $key => $value) {
            if (is_numeric($value)) {
                $out[(string) $key] = (int) $value;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $warnings
     * @return array{
     *   posts: ?int,
     *   pages: ?int,
     *   categories: ?int,
     *   products: ?int,
     *   product_categories: ?int,
     *   other: ?int,
     *   available: bool,
     *   sources: list<string>,
     *   warnings: list<string>
     * }
     */
    private function unavailable(array $warnings): array
    {
        return [
            'posts' => null,
            'pages' => null,
            'categories' => null,
            'products' => null,
            'product_categories' => null,
            'other' => null,
            'available' => false,
            'sources' => [],
            'warnings' => $warnings,
        ];
    }
}
