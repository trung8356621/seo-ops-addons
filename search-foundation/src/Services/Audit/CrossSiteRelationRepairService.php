<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;

/**
 * Repair cross-site contaminated relations. Prefer dry-run first.
 * Never guesses by title similarity.
 */
final class CrossSiteRelationRepairService
{
    public function __construct(
        private readonly CrossSiteRelationAuditService $audit,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     repaired: list<array<string, mixed>>,
     *     unresolved: list<array<string, mixed>>,
     *     audit_counts: array<string, int>
     * }
     */
    public function repair(bool $dryRun = true, ?int $siteId = null): array
    {
        $audit = $this->audit->audit($siteId);
        $repaired = [];
        $unresolved = [];

        foreach ($audit['findings'] as $finding) {
            $type = (string) ($finding['relation_type'] ?? '');
            if ($type === 'focus_keyword_site_meta_mismatch') {
                $result = $this->repairFocusSiteMeta($finding, $dryRun);
                if ($result['status'] === 'repaired') {
                    $repaired[] = $result;
                } else {
                    $unresolved[] = $result;
                }
            } elseif ($type === 'internal_link_cross_site_target') {
                $result = $this->repairInternalCrossSiteLink($finding, $dryRun);
                if ($result['status'] === 'repaired') {
                    $repaired[] = $result;
                } else {
                    $unresolved[] = $result;
                }
            } elseif ($type === 'legacy_global_main_article_shared_phrase') {
                $result = $this->migrateLegacyGlobalToSiteScoped($finding, $dryRun);
                if ($result['status'] === 'repaired') {
                    $repaired[] = $result;
                } else {
                    $unresolved[] = $result;
                }
            }
        }

        return [
            'dry_run' => $dryRun,
            'repaired' => $repaired,
            'unresolved' => $unresolved,
            'audit_counts' => $audit['counts'],
        ];
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private function repairFocusSiteMeta(array $finding, bool $dryRun): array
    {
        $keywordId = (int) ($finding['keyword_id'] ?? 0);
        $siteId = (int) ($finding['site_id'] ?? 0);
        $articleId = (int) ($finding['article_id'] ?? 0);
        $metaKey = KeywordMetaKey::siteMainArticleId($siteId);

        if ($keywordId <= 0 || $siteId <= 0) {
            return ['status' => 'unresolved', 'reason' => 'invalid_ids', 'finding' => $finding];
        }

        if (! $dryRun) {
            DB::connection('omi_seo_ai')->table('keyword_meta')
                ->where('keyword_id', $keywordId)
                ->where('meta_key', $metaKey)
                ->delete();

            Log::info('seo.cross_site_relation_repaired', [
                'action' => 'detach_site_main_article',
                'keyword_id' => $keywordId,
                'site_id' => $siteId,
                'article_id' => $articleId,
            ]);
        }

        return [
            'status' => 'repaired',
            'action' => 'detach_site_main_article',
            'keyword_id' => $keywordId,
            'site_id' => $siteId,
            'article_id' => $articleId,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private function repairInternalCrossSiteLink(array $finding, bool $dryRun): array
    {
        $articleId = (int) ($finding['article_id'] ?? 0);
        $siteId = (int) ($finding['site_id'] ?? 0);
        $keywordId = (int) ($finding['keyword_id'] ?? 0);

        if ($articleId <= 0 || $siteId <= 0) {
            return ['status' => 'unresolved', 'reason' => 'invalid_ids', 'finding' => $finding];
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            return ['status' => 'unresolved', 'reason' => 'no_link_maps_table', 'finding' => $finding];
        }

        $query = DB::connection('omi_seo_ai')->table('seo_link_maps as m')
            ->join('articles as src', 'src.id', '=', 'm.source_article_id')
            ->where('src.site_id', $siteId)
            ->where('m.target_article_id', $articleId)
            ->where('m.link_type', 'internal');

        if ($keywordId > 0) {
            $query->where('m.keyword_id', $keywordId);
        }

        $ids = $query->pluck('m.id')->map(static fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            return ['status' => 'unresolved', 'reason' => 'map_not_found', 'finding' => $finding];
        }

        if (! $dryRun) {
            DB::connection('omi_seo_ai')->table('seo_link_maps')
                ->whereIn('id', $ids)
                ->update([
                    'link_type' => 'external',
                    'target_article_id' => null,
                ]);

            Log::info('seo.cross_site_relation_repaired', [
                'action' => 'reclassify_internal_to_external',
                'link_map_ids' => $ids,
                'site_id' => $siteId,
                'article_id' => $articleId,
            ]);
        }

        return [
            'status' => 'repaired',
            'action' => 'reclassify_internal_to_external',
            'link_map_ids' => $ids,
            'site_id' => $siteId,
            'article_id' => $articleId,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Copy legacy global main_article_id into site-scoped key for the article's site.
     *
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private function migrateLegacyGlobalToSiteScoped(array $finding, bool $dryRun): array
    {
        $keywordId = (int) ($finding['keyword_id'] ?? 0);
        $articleId = (int) ($finding['article_id'] ?? 0);
        $articleSiteId = (int) ($finding['article_site_id'] ?? 0);

        if ($keywordId <= 0 || $articleId <= 0 || $articleSiteId <= 0) {
            return ['status' => 'unresolved', 'reason' => 'invalid_ids', 'finding' => $finding];
        }

        $metaKey = KeywordMetaKey::siteMainArticleId($articleSiteId);
        $exists = DB::connection('omi_seo_ai')->table('keyword_meta')
            ->where('keyword_id', $keywordId)
            ->where('meta_key', $metaKey)
            ->exists();

        if ($exists) {
            return [
                'status' => 'repaired',
                'action' => 'site_scoped_already_present',
                'keyword_id' => $keywordId,
                'site_id' => $articleSiteId,
                'article_id' => $articleId,
                'dry_run' => $dryRun,
            ];
        }

        if (! $dryRun) {
            DB::connection('omi_seo_ai')->table('keyword_meta')->updateOrInsert(
                [
                    'keyword_id' => $keywordId,
                    'meta_key' => $metaKey,
                ],
                [
                    'meta_value' => (string) $articleId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return [
            'status' => 'repaired',
            'action' => 'migrate_legacy_global_to_site_scoped',
            'keyword_id' => $keywordId,
            'site_id' => $articleSiteId,
            'article_id' => $articleId,
            'dry_run' => $dryRun,
        ];
    }
}
