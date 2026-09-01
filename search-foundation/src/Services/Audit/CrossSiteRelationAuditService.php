<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;

/**
 * READ-ONLY cross-site relation audit for keywords / articles / link maps.
 *
 * @phpstan-type Finding array{
 *     site_id: int|null,
 *     keyword_id: int|null,
 *     keyword: string|null,
 *     article_id: int|null,
 *     article_site_id: int|null,
 *     wp_post_id: int|null,
 *     url: string|null,
 *     relation_type: string
 * }
 */
final class CrossSiteRelationAuditService
{
    /**
     * @return array{
     *     findings: list<Finding>,
     *     counts: array<string, int>,
     *     duplicate_wp_post_ids: list<array{wp_post_id: int, site_ids: list<int>, article_ids: list<int>}>
     * }
     */
    public function audit(?int $siteId = null): array
    {
        $findings = [];
        $counts = [
            'focus_keyword_cross_site' => 0,
            'link_map_source_cross_site' => 0,
            'internal_link_target_cross_site' => 0,
            'legacy_global_main_article_ambiguous' => 0,
            'duplicate_wp_post_id' => 0,
        ];

        if (Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            $findings = array_merge($findings, $this->auditFocusKeywordMeta($siteId, $counts));
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            $findings = array_merge($findings, $this->auditLinkMaps($siteId, $counts));
        }

        $duplicates = $this->auditDuplicateWpPostIds($siteId);
        $counts['duplicate_wp_post_id'] = count($duplicates);

        return [
            'findings' => $findings,
            'counts' => $counts,
            'duplicate_wp_post_ids' => $duplicates,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<Finding>
     */
    private function auditFocusKeywordMeta(?int $siteId, array &$counts): array
    {
        $rows = DB::connection('omi_seo_ai')->table('keyword_meta as km')
            ->join('keywords as k', 'k.id', '=', 'km.keyword_id')
            ->leftJoin('articles as a', 'a.id', '=', DB::raw('CAST(km.meta_value AS UNSIGNED)'))
            ->where(function ($q): void {
                $q->where('km.meta_key', KeywordMetaKey::MainArticleId->value)
                    ->orWhere('km.meta_key', 'like', 'site.%.main_article_id');
            })
            ->whereNotNull('km.meta_value')
            ->where('km.meta_value', '!=', '')
            ->select([
                'km.keyword_id',
                'km.meta_key',
                'km.meta_value',
                'k.phrase',
                'a.id as article_id',
                'a.site_id as article_site_id',
            ])
            ->get();

        $findings = [];
        foreach ($rows as $row) {
            $metaKey = (string) ($row->meta_key ?? '');
            $articleId = (int) ($row->article_id ?? 0);
            $articleSiteId = (int) ($row->article_site_id ?? 0);
            $keywordId = (int) ($row->keyword_id ?? 0);
            $phrase = (string) ($row->phrase ?? '');

            $metaSiteId = KeywordMetaKey::siteIdFromKey($metaKey);
            if ($metaSiteId !== null) {
                if ($siteId !== null && $siteId > 0 && $metaSiteId !== $siteId) {
                    continue;
                }
                if ($articleId <= 0 || $articleSiteId !== $metaSiteId) {
                    $counts['focus_keyword_cross_site']++;
                    $findings[] = [
                        'site_id' => $metaSiteId,
                        'keyword_id' => $keywordId,
                        'keyword' => $phrase,
                        'article_id' => $articleId > 0 ? $articleId : (int) ($row->meta_value ?? 0),
                        'article_site_id' => $articleSiteId > 0 ? $articleSiteId : null,
                        'wp_post_id' => null,
                        'url' => null,
                        'relation_type' => 'focus_keyword_site_meta_mismatch',
                    ];
                }

                continue;
            }

            // Legacy global main_article_id: flag when keyword also has site meta for other sites.
            if ($metaKey === KeywordMetaKey::MainArticleId->value && $articleSiteId > 0) {
                if ($siteId !== null && $siteId > 0 && $articleSiteId !== $siteId) {
                    // Global points at another site — still report as ambiguous for this audit scope.
                }
                $otherSites = DB::connection('omi_seo_ai')->table('keyword_meta')
                    ->where('keyword_id', $keywordId)
                    ->where('meta_key', 'like', 'site.%.%')
                    ->pluck('meta_key')
                    ->map(static fn (mixed $key): ?int => KeywordMetaKey::siteIdFromKey((string) $key))
                    ->filter(static fn (?int $id): bool => $id !== null && $id > 0 && $id !== $articleSiteId)
                    ->unique()
                    ->values()
                    ->all();

                if ($otherSites !== []) {
                    $counts['legacy_global_main_article_ambiguous']++;
                    $findings[] = [
                        'site_id' => $articleSiteId,
                        'keyword_id' => $keywordId,
                        'keyword' => $phrase,
                        'article_id' => $articleId,
                        'article_site_id' => $articleSiteId,
                        'wp_post_id' => null,
                        'url' => null,
                        'relation_type' => 'legacy_global_main_article_shared_phrase',
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<Finding>
     */
    private function auditLinkMaps(?int $siteId, array &$counts): array
    {
        $query = DB::connection('omi_seo_ai')->table('seo_link_maps as m')
            ->leftJoin('articles as src', 'src.id', '=', 'm.source_article_id')
            ->leftJoin('articles as tgt', 'tgt.id', '=', 'm.target_article_id')
            ->leftJoin('keywords as k', 'k.id', '=', 'm.keyword_id')
            ->select([
                'm.id',
                'm.keyword_id',
                'm.link_type',
                'm.target_external_url',
                'k.phrase',
                'src.id as source_article_id',
                'src.site_id as source_site_id',
                'tgt.id as target_article_id',
                'tgt.site_id as target_site_id',
            ]);

        if ($siteId !== null && $siteId > 0) {
            $query->where(function ($q) use ($siteId): void {
                $q->where('src.site_id', $siteId)->orWhere('tgt.site_id', $siteId);
            });
        }

        $findings = [];
        foreach ($query->orderBy('m.id')->cursor() as $row) {
            $sourceSiteId = (int) ($row->source_site_id ?? 0);
            $targetSiteId = (int) ($row->target_site_id ?? 0);
            $targetArticleId = (int) ($row->target_article_id ?? 0);
            $linkType = strtolower((string) ($row->link_type ?? ''));

            if (
                $targetArticleId > 0
                && $sourceSiteId > 0
                && $targetSiteId > 0
                && $sourceSiteId !== $targetSiteId
                && $linkType === 'internal'
            ) {
                $counts['internal_link_target_cross_site']++;
                $findings[] = [
                    'site_id' => $sourceSiteId,
                    'keyword_id' => (int) ($row->keyword_id ?? 0) ?: null,
                    'keyword' => (string) ($row->phrase ?? '') ?: null,
                    'article_id' => $targetArticleId,
                    'article_site_id' => $targetSiteId,
                    'wp_post_id' => null,
                    'url' => (string) ($row->target_external_url ?? '') ?: null,
                    'relation_type' => 'internal_link_cross_site_target',
                ];
            }
        }

        return $findings;
    }

    /**
     * @return list<array{wp_post_id: int, site_ids: list<int>, article_ids: list<int>}>
     */
    private function auditDuplicateWpPostIds(?int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            return [];
        }

        $rows = DB::connection('omi_seo_ai')->table('wordpress_article_links')
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->when($siteId !== null && $siteId > 0, static fn ($q) => $q->where('site_id', $siteId))
            ->select('wp_post_id')
            ->groupBy('wp_post_id')
            ->havingRaw('COUNT(DISTINCT site_id) > 1')
            ->limit(500)
            ->pluck('wp_post_id');

        $out = [];
        foreach ($rows as $wpId) {
            $wpId = (int) $wpId;
            $links = DB::connection('omi_seo_ai')->table('wordpress_article_links')
                ->where('wp_post_id', $wpId)
                ->get(['site_id', 'article_id']);
            $out[] = [
                'wp_post_id' => $wpId,
                'site_ids' => $links->pluck('site_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all(),
                'article_ids' => $links->pluck('article_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all(),
            ];
        }

        return $out;
    }
}
