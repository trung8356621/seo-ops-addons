<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Preflight;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Support\ArticleSeoInventoryPolicy;
use Omnichannel\Addons\Content\Support\NativeContentTypeMapper;

/**
 * Site Sync Preflight WP↔local CONTENT comparison universe.
 *
 * Distinct from {@see \Omnichannel\Addons\Content\Services\Health\ArticleRequiredDataHealthAuditor}
 * (SEO data health may include local-only rows).
 *
 * Comparable content row (WP-backed identity SSOT):
 * - site-scoped, not soft-deleted
 * - INNER JOIN `wordpress_article_links` with `wal.wp_post_id > 0`
 *   (same predicate as {@see ArticleSeoInventoryPolicy::isWpBacked}; link table is SoT —
 *   `articles.wp_post_id` is retired / projection-only and must not define membership)
 * - not a taxonomy term (wp_is_term ≠ 1)
 * - not a system/structural WP post type ({@see ArticleSeoInventoryPolicy})
 *
 * Terms are out of this table entirely (sync may still import them).
 *
 * Remote discover:
 * - Authoritative when `by_native_post_type` is present (new plugin).
 * - Fallback `sum(by_content_type)` is intentionally non-authoritative (old plugin /
 *   V2 manifest) — must not drive "sync required" recommendations.
 */
final class SiteSyncPreflightContentComparison
{
    public const SOURCE_NATIVE = 'by_native_post_type';

    public const SOURCE_CONTENT_TYPE_FALLBACK = 'by_content_type_fallback';

    /**
     * Local WP-backed comparable counts by canonical content_type.
     *
     * @return array{total: int, post: int, page: int, product: int, other: int}
     */
    public function countLocal(int $siteId): array
    {
        $empty = ['total' => 0, 'post' => 0, 'page' => 0, 'product' => 0, 'other' => 0];
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return $empty;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            return $empty;
        }

        $q = DB::connection('omi_seo_ai')
            ->table('articles as a')
            ->join('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
            ->leftJoin('article_meta as am_ct', function ($j): void {
                $j->on('am_ct.article_id', '=', 'a.id')->where('am_ct.meta_key', '=', 'content_type');
            })
            ->leftJoin('article_meta as am_pt', function ($j): void {
                $j->on('am_pt.article_id', '=', 'a.id')->where('am_pt.meta_key', '=', 'wp_post_type');
            })
            ->leftJoin('article_meta as am_term', function ($j): void {
                $j->on('am_term.article_id', '=', 'a.id')->where('am_term.meta_key', '=', 'wp_is_term');
            })
            ->where('a.site_id', $siteId)
            ->whereNull('a.deleted_at')
            ->where('wal.wp_post_id', '>', 0)
            ->select([
                'a.id as article_id',
                'am_ct.meta_value as content_type',
                'am_pt.meta_value as wp_post_type',
                'am_term.meta_value as wp_is_term',
            ]);

        $by = ['post' => 0, 'page' => 0, 'product' => 0, 'other' => 0];
        foreach ($q->get() as $row) {
            $wpPostType = $row->wp_post_type !== null ? (string) $row->wp_post_type : null;
            $wpIsTerm = $row->wp_is_term !== null ? (string) $row->wp_is_term : null;
            if (! ArticleSeoInventoryPolicy::isSeoInventoryCandidate($wpPostType, $wpIsTerm)) {
                continue;
            }
            $ct = strtolower(trim((string) ($row->content_type ?? '')));
            if (! isset($by[$ct])) {
                $ct = 'other';
            }
            $by[$ct]++;
        }

        return [
            'total' => array_sum($by),
            'post' => $by['post'],
            'page' => $by['page'],
            'product' => $by['product'],
            'other' => $by['other'],
        ];
    }

    /**
     * Normalize V3 discover into comparable content counts (no terms).
     *
     * Prefers discover.by_native_post_type filtered by {@see ArticleSeoInventoryPolicy}
     * and mapped via {@see NativeContentTypeMapper} (same SSOT as Site Sync import).
     * Falls back to discover.by_content_type with total = sum(types) — never discover.total.
     * Fallback is marked non-authoritative.
     *
     * @param  array<string, mixed>  $discover
     * @param  array<string, string>|null  $siteContentTypeMap  native => post|page|product
     * @return array{
     *   total: int,
     *   post: int,
     *   page: int,
     *   product: int,
     *   other: int,
     *   authoritative: bool,
     *   source: string
     * }
     */
    public function normalizeRemoteDiscover(array $discover, ?array $siteContentTypeMap = null): array
    {
        $native = is_array($discover['by_native_post_type'] ?? null)
            ? $discover['by_native_post_type']
            : null;

        if ($native !== null && $native !== []) {
            $counts = $this->fromNativePostTypeCounts($native, $siteContentTypeMap);

            return array_merge($counts, [
                'authoritative' => true,
                'source' => self::SOURCE_NATIVE,
            ]);
        }

        $counts = $this->fromContentTypeCounts(
            is_array($discover['by_content_type'] ?? null) ? $discover['by_content_type'] : [],
        );

        return array_merge($counts, [
            'authoritative' => false,
            'source' => self::SOURCE_CONTENT_TYPE_FALLBACK,
        ]);
    }

    /**
     * @param  array<string, mixed>  $nativeCounts  post_type => count
     * @param  array<string, string>|null  $siteContentTypeMap
     * @return array{total: int, post: int, page: int, product: int, other: int}
     */
    public function fromNativePostTypeCounts(array $nativeCounts, ?array $siteContentTypeMap = null): array
    {
        $by = ['post' => 0, 'page' => 0, 'product' => 0, 'other' => 0];
        foreach ($nativeCounts as $native => $count) {
            $slug = strtolower(trim((string) $native));
            if ($slug === '' || ArticleSeoInventoryPolicy::isSystemWpPostType($slug)) {
                continue;
            }
            $n = (int) $count;
            if ($n <= 0) {
                continue;
            }
            $bucket = $this->contentTypeBucketForNative($slug, $siteContentTypeMap);
            $by[$bucket] += $n;
        }

        return [
            'total' => array_sum($by),
            'post' => $by['post'],
            'page' => $by['page'],
            'product' => $by['product'],
            'other' => $by['other'],
        ];
    }

    /**
     * @param  array<string, mixed>  $byContentType
     * @return array{total: int, post: int, page: int, product: int, other: int}
     */
    public function fromContentTypeCounts(array $byContentType): array
    {
        $by = ['post' => 0, 'page' => 0, 'product' => 0, 'other' => 0];
        foreach ($byContentType as $type => $count) {
            $key = strtolower(trim((string) $type));
            if (! isset($by[$key])) {
                $key = 'other';
            }
            $by[$key] += (int) $count;
        }

        return [
            'total' => array_sum($by),
            'post' => $by['post'],
            'page' => $by['page'],
            'product' => $by['product'],
            'other' => $by['other'],
        ];
    }

    /**
     * @param  array<string, string>|null  $siteContentTypeMap
     */
    private function contentTypeBucketForNative(string $native, ?array $siteContentTypeMap = null): string
    {
        $mapped = NativeContentTypeMapper::map($native, $siteContentTypeMap)->value;
        if (in_array($mapped, ['post', 'page', 'product'], true)) {
            return $mapped;
        }

        return 'other';
    }
}
