<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\DissolveTopicClusterResult;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMultiSiteOwnership;
use RuntimeException;
use Throwable;

final class DissolveTopicClusterService
{
    /** Chunk size for whereIn updates / meta upserts inside one transaction (packet + bind limits). */
    private const MUTATION_CHUNK = 1000;

    /**
     * Optional test seam: invoked inside the transaction after mutations, before commit.
     * Return true to simulate remaining membership (forces rollback). Null = real DB check.
     *
     * @var (callable(int, string): bool)|null
     */
    private $membershipRemainingCheck;

    public function __construct(
        private readonly KeywordClusterQuery $clusters,
        private readonly TopicClusterDissolveSideEffects $sideEffects,
        private readonly TopicClusterDerivedCleanup $derivedCleanup,
        ?callable $membershipRemainingCheck = null,
    ) {
        $this->membershipRemainingCheck = $membershipRemainingCheck;
    }

    public function dissolve(int $siteId, string $clusterKey, ?string $clusterLabel = null): DissolveTopicClusterResult
    {
        $clusterKey = trim($clusterKey);
        if ($clusterKey === '' || $siteId <= 0) {
            return DissolveTopicClusterResult::invalidClusterKey();
        }

        if (! $this->clusters->classificationsReady()) {
            return DissolveTopicClusterResult::alreadyEmpty($clusterKey);
        }

        // Full snapshot — no silent truncate. limit(5000) belonged to UI/diag helpers, not dissolve.
        $keywordIds = $this->siteScopedClusterMemberIds($siteId, $clusterKey);
        if ($keywordIds === []) {
            // Orphan derived rows (meta/DNA/aliases) with no members — still purge.
            $this->derivedCleanup->purgeClusterArtifacts($siteId, $clusterKey);

            return DissolveTopicClusterResult::alreadyEmpty($clusterKey);
        }

        // Refuse before mutating when any site member is still owned elsewhere — clearing
        // would either no-op (false success) or half-clear while the topic stays visible.
        $clearableIds = [];
        foreach ($keywordIds as $keywordId) {
            $keywordId = (int) $keywordId;
            if ($keywordId <= 0) {
                continue;
            }
            if (KeywordMultiSiteOwnership::isSharedWithOtherSites($keywordId, $siteId)) {
                return DissolveTopicClusterResult::blockedSharedOwnership($clusterKey);
            }
            $clearableIds[] = $keywordId;
        }

        if ($clearableIds === []) {
            return DissolveTopicClusterResult::failed($clusterKey);
        }

        try {
            $affected = (int) DB::connection('omi_seo_ai')->transaction(function () use ($clearableIds, $clusterKey, $siteId): int {
                $updated = $this->clearClusterKeys($clearableIds, $clusterKey);

                if ($updated <= 0) {
                    throw new RuntimeException('dissolve_no_rows_updated');
                }

                $this->markManualExclude($clearableIds);
                $this->derivedCleanup->purgeClusterArtifacts($siteId, $clusterKey);

                // Invariant before commit — failure rolls back membership + meta + derived cleanup.
                if ($this->clusterStillHasSiteMembers($siteId, $clusterKey)) {
                    throw new RuntimeException('dissolve_membership_remaining');
                }

                return $updated;
            });
        } catch (Throwable) {
            return DissolveTopicClusterResult::failed($clusterKey);
        }

        $label = trim((string) ($clusterLabel ?? ''));
        if ($label === '') {
            $label = $this->clusters->displayLabel($clusterKey, '', $siteId);
        }

        $this->sideEffects->afterDissolve($siteId, $clusterKey, $label, $affected);

        return DissolveTopicClusterResult::success($clusterKey, $affected);
    }

    /**
     * Site-owned members of a cluster for dissolve (cluster scope, not UI dictionary word-count filter).
     * Returns the complete set — must not silently truncate.
     *
     * @return list<int>
     */
    private function siteScopedClusterMemberIds(int $siteId, string $clusterKey): array
    {
        if (! $this->clusters->classificationsReady()) {
            return [];
        }

        $siteScope = KeywordClusterSiteScope::keywordIdSubquery(
            $siteId,
            null,
            excludeSuggest: true,
            requireLinkedSource: true,
        );

        return SeoKeywordClassification::query()
            ->where('cluster_key', $clusterKey)
            ->whereIn('keyword_id', $siteScope)
            ->orderBy('keyword_id')
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function clusterStillHasSiteMembers(int $siteId, string $clusterKey): bool
    {
        if ($this->membershipRemainingCheck !== null) {
            return (bool) ($this->membershipRemainingCheck)($siteId, $clusterKey);
        }

        if (! $this->clusters->classificationsReady()) {
            return false;
        }

        $siteScope = KeywordClusterSiteScope::keywordIdSubquery(
            $siteId,
            null,
            excludeSuggest: true,
            requireLinkedSource: true,
        );

        return SeoKeywordClassification::query()
            ->where('cluster_key', $clusterKey)
            ->whereIn('keyword_id', $siteScope)
            ->exists();
    }

    /**
     * @param  list<int>  $keywordIds
     */
    private function clearClusterKeys(array $keywordIds, string $clusterKey): int
    {
        $updated = 0;
        foreach (array_chunk($keywordIds, self::MUTATION_CHUNK) as $chunk) {
            $updated += (int) SeoKeywordClassification::query()
                ->whereIn('keyword_id', $chunk)
                ->where('cluster_key', $clusterKey)
                ->update(['cluster_key' => null]);
        }

        return $updated;
    }

    /**
     * @param  list<int>  $keywordIds
     */
    private function markManualExclude(array $keywordIds): void
    {
        if ($keywordIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return;
        }

        $now = now();
        $metaKey = ReclusterTopicClustersService::META_MANUAL_EXCLUDE;
        $connection = DB::connection('omi_seo_ai');

        foreach (array_chunk($keywordIds, self::MUTATION_CHUNK) as $chunk) {
            $existingIds = $connection->table('keyword_meta')
                ->whereIn('keyword_id', $chunk)
                ->where('meta_key', $metaKey)
                ->pluck('keyword_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $existingSet = array_fill_keys($existingIds, true);

            if ($existingIds !== []) {
                $connection->table('keyword_meta')
                    ->whereIn('keyword_id', $existingIds)
                    ->where('meta_key', $metaKey)
                    ->update([
                        'meta_value' => '1',
                        'updated_at' => $now,
                    ]);
            }

            $insertRows = [];
            foreach ($chunk as $keywordId) {
                $keywordId = (int) $keywordId;
                if ($keywordId <= 0 || isset($existingSet[$keywordId])) {
                    continue;
                }
                $insertRows[] = [
                    'keyword_id' => $keywordId,
                    'meta_key' => $metaKey,
                    'meta_value' => '1',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($insertRows !== []) {
                $connection->table('keyword_meta')->insert($insertRows);
            }
        }
    }
}
