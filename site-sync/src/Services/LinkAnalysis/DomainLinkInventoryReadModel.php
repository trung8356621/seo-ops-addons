<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\LinkAnalysis;

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Support\ArticleSeoInventoryPolicy;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

/**
 * Authoritative Domain General link metrics from V3 seo_link_maps inventory.
 * Remote dead-link / opportunity analysis remains a separate snapshot job.
 *
 * @return array{
 *   internal_links: int,
 *   external_links: int,
 *   total_links: int,
 *   keyword_links: int,
 *   keywordless_links: int,
 *   orphan_pages: int,
 *   inventory_available: bool,
 *   inventory_state: string,
 *   link_opportunities: int|null,
 *   opportunities_checked: bool,
 *   broken_links: int|null,
 *   remote_health_checked: bool,
 *   remote_health_state: string,
 *   last_remote_analyzed_at: string|null,
 *   source: string
 * }
 */
final class DomainLinkInventoryReadModel
{
    /**
     * @return array{
     *   internal_links: int,
     *   external_links: int,
     *   total_links: int,
     *   keyword_links: int,
     *   keywordless_links: int,
     *   orphan_pages: int,
     *   inventory_available: bool,
     *   inventory_state: string,
     *   link_opportunities: int|null,
     *   opportunities_checked: bool,
     *   broken_links: int|null,
     *   remote_health_checked: bool,
     *   remote_health_state: string,
     *   last_remote_analyzed_at: string|null,
     *   source: string
     * }
     */
    public function forSite(Site $site): array
    {
        $siteId = (int) $site->id;
        $empty = [
            'internal_links' => 0,
            'external_links' => 0,
            'total_links' => 0,
            'keyword_links' => 0,
            'keywordless_links' => 0,
            'orphan_pages' => 0,
            'inventory_available' => false,
            'inventory_state' => 'Not available',
            'link_opportunities' => null,
            'opportunities_checked' => false,
            'broken_links' => null,
            'remote_health_checked' => false,
            'remote_health_state' => 'Not checked',
            'last_remote_analyzed_at' => null,
            'source' => 'none',
        ];

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')
            || ! Schema::connection('omi_seo_ai')->hasTable('articles')
        ) {
            return $empty;
        }

        $base = DB::connection('omi_seo_ai')
            ->table('seo_link_maps as m')
            ->join('articles as a', 'a.id', '=', 'm.source_article_id')
            ->where('a.site_id', $siteId)
            ->whereNull('a.deleted_at')
            ->where('a.status', '!=', 'trash')
            ->where('m.status', '!=', SeoLinkMapStatus::Ignored->value);

        $total = (int) (clone $base)->count();
        $internal = (int) (clone $base)->where('m.link_type', SeoLinkMapType::Internal->value)->count();
        $external = (int) (clone $base)->where('m.link_type', SeoLinkMapType::External->value)->count();
        $withKw = (int) (clone $base)->whereNotNull('m.keyword_id')->count();
        $withoutKw = (int) (clone $base)->whereNull('m.keyword_id')->count();

        // Orphan = public/indexable WP-backed SEO inventory with zero inbound internal links.
        $orphanPages = $this->countOrphanPages($siteId);

        $snap = SiteSyncSiteMeta::getJson($site, 'seo_link_analysis_snapshot') ?? [];
        $lastAt = isset($snap['last_analyzed_at']) ? trim((string) $snap['last_analyzed_at']) : '';
        $remoteChecked = $lastAt !== '';
        $opportunities = $remoteChecked && array_key_exists('opportunities', $snap)
            ? (int) $snap['opportunities']
            : null;
        $broken = $remoteChecked && array_key_exists('broken_links', $snap)
            ? (int) $snap['broken_links']
            : null;

        $inventoryReady = $total > 0;

        return [
            'internal_links' => $internal,
            'external_links' => $external,
            'total_links' => $total,
            'keyword_links' => $withKw,
            'keywordless_links' => $withoutKw,
            'orphan_pages' => $orphanPages,
            'inventory_available' => $inventoryReady,
            'inventory_state' => $inventoryReady
                ? 'Inventory ready'
                : ($this->siteHasWpBackedArticles($siteId) ? 'Inventory empty' : 'Not available'),
            'link_opportunities' => $opportunities,
            'opportunities_checked' => $remoteChecked,
            'broken_links' => $broken,
            'remote_health_checked' => $remoteChecked,
            'remote_health_state' => $remoteChecked ? 'Checked' : 'Not checked',
            'last_remote_analyzed_at' => $lastAt !== '' ? $lastAt : null,
            'source' => 'seo_link_maps',
        ];
    }

    private function siteHasWpBackedArticles(int $siteId): bool
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            return false;
        }

        return DB::connection('omi_seo_ai')
            ->table('wordpress_article_links as wal')
            ->join('articles as a', 'a.id', '=', 'wal.article_id')
            ->where('wal.site_id', $siteId)
            ->where('wal.wp_post_id', '>', 0)
            ->whereNull('a.deleted_at')
            ->exists();
    }

    private function countOrphanPages(int $siteId): int
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            return 0;
        }

        $systemTypes = array_map(
            static fn (string $type): string => strtolower($type),
            ArticleSeoInventoryPolicy::SYSTEM_WP_POST_TYPES,
        );
        $placeholders = implode(',', array_fill(0, count($systemTypes), '?'));

        $count = DB::connection('omi_seo_ai')
            ->table('articles as a')
            ->join('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
            ->leftJoin('article_meta as am_term', function ($j): void {
                $j->on('am_term.article_id', '=', 'a.id')->where('am_term.meta_key', '=', 'wp_is_term');
            })
            ->leftJoin('article_meta as am_pt', function ($j): void {
                $j->on('am_pt.article_id', '=', 'a.id')->where('am_pt.meta_key', '=', 'wp_post_type');
            })
            ->where('a.site_id', $siteId)
            ->where('wal.wp_post_id', '>', 0)
            ->whereNull('a.deleted_at')
            ->whereNotIn('a.status', ['trash', 'draft', 'auto-draft', 'pending'])
            ->where(function ($query): void {
                $query->whereNull('am_term.meta_value')
                    ->orWhereRaw("LOWER(TRIM(COALESCE(am_term.meta_value, ''))) NOT IN ('1', 'true', 'yes')");
            })
            ->where(function ($query) use ($systemTypes, $placeholders): void {
                $query->whereNull('am_pt.meta_value')
                    ->orWhereRaw("TRIM(am_pt.meta_value) = ''")
                    ->orWhere(function ($typed) use ($systemTypes, $placeholders): void {
                        $typed->whereRaw('LOWER(TRIM(am_pt.meta_value)) NOT IN ('.$placeholders.')', $systemTypes)
                            ->whereRaw("LOWER(TRIM(am_pt.meta_value)) NOT LIKE ? ESCAPE ?", ['wp\_%', '\\']);
                    });
            })
            ->whereNotExists(function ($sub) use ($siteId): void {
                $sub->selectRaw('1')
                    ->from('seo_link_maps as m')
                    ->join('articles as src', 'src.id', '=', 'm.source_article_id')
                    ->whereColumn('m.target_article_id', 'a.id')
                    ->where('src.site_id', $siteId)
                    ->whereNull('src.deleted_at')
                    ->where('m.link_type', SeoLinkMapType::Internal->value)
                    ->where('m.status', '!=', SeoLinkMapStatus::Ignored->value)
                    ->whereNotNull('m.target_article_id');
            })
            ->distinct()
            ->count('a.id');

        return (int) $count;
    }
}
