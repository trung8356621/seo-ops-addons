<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use RuntimeException;

/**
 * Soft-hide keywords from SEO/clustering eligibility without deleting data or article links.
 */
final class HideKeywordFromSeoService
{
    public function __construct(
        private readonly PruneAutoSingletonClustersService $singletonPruner,
    ) {}

    public function isHidden(int $keywordId): bool
    {
        if ($keywordId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return false;
        }

        return Keyword::query()
            ->whereKey($keywordId)
            ->whereHas('metas', static function ($q): void {
                $q->where('meta_key', KeywordMetaKey::SeoHidden->value)
                    ->where('meta_value', '1');
            })
            ->exists();
    }

    /**
     * @return array{
     *     keyword_id: int,
     *     phrase: string,
     *     was_hidden: bool,
     *     previous_cluster_key: string,
     *     detached: bool,
     *     pruned: int
     * }
     */
    public function hide(int $keywordId, ?int $siteId = null): array
    {
        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            throw new RuntimeException('keyword_not_found');
        }

        $previousCluster = '';
        $detached = false;
        $pruned = 0;

        DB::connection('omi_seo_ai')->transaction(function () use ($keywordId, $siteId, &$previousCluster, &$detached, &$pruned): void {
            $this->writeHiddenMeta($keywordId, true);

            if (! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')) {
                return;
            }

            $row = SeoKeywordClassification::query()->whereKey($keywordId)->first();
            if (! $row instanceof SeoKeywordClassification) {
                return;
            }

            $previousCluster = trim((string) ($row->cluster_key ?? ''));
            if ($previousCluster === '') {
                return;
            }

            $row->cluster_key = null;
            $row->save();
            $detached = true;

            if ($siteId !== null && $siteId > 0) {
                $touched = [$previousCluster => true];
                $stats = $this->singletonPruner->prune($siteId, $touched);
                $pruned = (int) ($stats['pruned'] ?? 0);
                if (! isset($touched[$previousCluster])) {
                    // Cluster was pruned — artifacts already cleaned.
                    return;
                }
                // Cluster still exists — leave meta/DNA; counts refresh on next read.
            }
        });

        if ($siteId !== null && $siteId > 0) {
            TopicClusterDirtyState::mark($siteId, 'keyword_hidden');
            SiteMcpTopicalProfileStaleState::mark($siteId, 'keyword_hidden');
        }

        return [
            'keyword_id' => $keywordId,
            'phrase' => (string) $keyword->phrase,
            'was_hidden' => true,
            'previous_cluster_key' => $previousCluster,
            'detached' => $detached,
            'pruned' => $pruned,
        ];
    }

    /**
     * @return array{keyword_id: int, phrase: string, restored: bool}
     */
    public function restore(int $keywordId, ?int $siteId = null): array
    {
        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            throw new RuntimeException('keyword_not_found');
        }

        $this->writeHiddenMeta($keywordId, false);

        if ($siteId !== null && $siteId > 0) {
            TopicClusterDirtyState::mark($siteId, 'keyword_restored');
            SiteMcpTopicalProfileStaleState::mark($siteId, 'keyword_restored');
        }

        return [
            'keyword_id' => $keywordId,
            'phrase' => (string) $keyword->phrase,
            'restored' => true,
        ];
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, true>
     */
    public function hiddenKeywordIdMap(array $keywordIds): array
    {
        $keywordIds = array_values(array_filter(array_map('intval', $keywordIds)));
        if ($keywordIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return [];
        }

        $ids = DB::connection('omi_seo_ai')->table('keyword_meta')
            ->whereIn('keyword_id', $keywordIds)
            ->where('meta_key', KeywordMetaKey::SeoHidden->value)
            ->where('meta_value', '1')
            ->pluck('keyword_id');

        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    public function hiddenKeywordIdsForSite(int $siteId): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return [];
        }

        $siteKeywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($siteKeywordIds === []) {
            return [];
        }

        return array_keys($this->hiddenKeywordIdMap($siteKeywordIds));
    }

    private function writeHiddenMeta(int $keywordId, bool $hidden): void
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            throw new RuntimeException('keyword_meta_missing');
        }

        $key = KeywordMetaKey::SeoHidden->value;
        if ($hidden) {
            $exists = DB::connection('omi_seo_ai')->table('keyword_meta')
                ->where('keyword_id', $keywordId)
                ->where('meta_key', $key)
                ->exists();
            if ($exists) {
                DB::connection('omi_seo_ai')->table('keyword_meta')
                    ->where('keyword_id', $keywordId)
                    ->where('meta_key', $key)
                    ->update(['meta_value' => '1', 'updated_at' => now()]);
            } else {
                DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
                    'keyword_id' => $keywordId,
                    'meta_key' => $key,
                    'meta_value' => '1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return;
        }

        DB::connection('omi_seo_ai')->table('keyword_meta')
            ->where('keyword_id', $keywordId)
            ->where('meta_key', $key)
            ->delete();
    }
}
