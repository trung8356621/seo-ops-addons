<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-backed comparable content inventory (Domain Overview + Site Sync Preflight).
 *
 * Membership universe (authoritative for WP↔local comparison UI):
 * - articles.site_id = site
 * - articles.deleted_at IS NULL
 * - INNER JOIN wordpress_article_links with wal.wp_post_id > 0
 *   (link table is SoT — articles.wp_post_id is retired / projection-only)
 * - not a taxonomy term (wp_is_term ≠ 1)
 * - not a system/structural WP post type ({@see ArticleSeoInventoryPolicy})
 *
 * Distinct from Workspace SEO scoring eligibility (may include local-only rows via
 * {@see ArticleSeoInventoryPolicy::scopeCandidates} alone).
 */
final class WpBackedComparableInventory
{
    /**
     * Restrict an articles Eloquent query to WP-backed comparable membership.
     *
     * Does not apply skip_seo_score — callers that need scoring presentation must
     * chain {@see \Omnichannel\Addons\Content\Models\SeoArticle::scopeCountsTowardSeoScore()}.
     *
     * @param  Builder<\Omnichannel\Addons\Content\Models\SeoArticle>  $query
     * @return Builder<\Omnichannel\Addons\Content\Models\SeoArticle>
     */
    public static function scopeArticles(Builder $query): Builder
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            return $query->whereRaw('0 = 1');
        }

        $query->whereExists(static function ($sub): void {
            $sub->selectRaw('1')
                ->from('wordpress_article_links as wal')
                ->whereColumn('wal.article_id', 'articles.id')
                ->where('wal.wp_post_id', '>', 0);
        });

        return ArticleSeoInventoryPolicy::scopeCandidates($query);
    }

    /**
     * Eligible WP-backed comparable rows for a site.
     *
     * @return list<object{
     *   article_id: int|string,
     *   content_type: mixed,
     *   wp_post_type: mixed,
     *   wp_is_term: mixed,
     *   language?: mixed
     * }>
     */
    public static function rows(int $siteId, ?string $language = null): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return [];
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            return [];
        }

        $language = $language !== null ? trim($language) : '';

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
                'a.language as language',
                'am_ct.meta_value as content_type',
                'am_pt.meta_value as wp_post_type',
                'am_term.meta_value as wp_is_term',
            ]);

        if ($language !== '') {
            $q->where('a.language', $language);
        }

        $out = [];
        foreach ($q->get() as $row) {
            $wpPostType = $row->wp_post_type !== null ? (string) $row->wp_post_type : null;
            $wpIsTerm = $row->wp_is_term !== null ? (string) $row->wp_is_term : null;
            if (! ArticleSeoInventoryPolicy::isSeoInventoryCandidate($wpPostType, $wpIsTerm)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Counts by raw WordPress post type (Domain Overview “Nội dung WordPress”).
     *
     * @return array<string, int> e.g. ['post' => 319, 'page' => 16, 'product' => 673]
     */
    public static function countByWpPostType(int $siteId): array
    {
        $by = [];
        foreach (self::rows($siteId) as $row) {
            $type = strtolower(trim((string) ($row->wp_post_type ?? '')));
            if ($type === '') {
                continue;
            }
            $by[$type] = ($by[$type] ?? 0) + 1;
        }

        return $by;
    }

    /**
     * Local WP-backed comparable counts by canonical content_type (Preflight).
     *
     * @return array{total: int, post: int, page: int, product: int, other: int}
     */
    public static function countByContentType(int $siteId, ?string $language = null): array
    {
        $by = ['post' => 0, 'page' => 0, 'product' => 0, 'other' => 0];
        foreach (self::rows($siteId, $language) as $row) {
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
}
