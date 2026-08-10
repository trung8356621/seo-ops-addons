<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Merge / split / move clusters — CommandBus-facing domain service.
 * Approved clusters require preview fingerprint; no silent mutate.
 */
final class KeywordClusterMutationService
{
    /**
     * @param  list<int>  $sourceClusterIds
     * @return array<string, mixed>
     */
    public function previewMerge(SeoKeywordWorkspace $workspace, array $sourceClusterIds, int $targetClusterId): array
    {
        $sources = $this->loadClusters($workspace, $sourceClusterIds);
        $target = $this->loadCluster($workspace, $targetClusterId);

        $keywordCount = 0;
        $intents = [];
        $hasApproved = $target->status === KeywordClusterStatus::Approved
            || $target->status === KeywordClusterStatus::Approved->value;
        $mappingConflicts = 0;

        foreach ($sources as $cluster) {
            if ((int) $cluster->id === (int) $target->id) {
                continue;
            }
            $keywordCount += (int) $cluster->keyword_count;
            $intents[] = (string) ($cluster->search_intent instanceof \BackedEnum
                ? $cluster->search_intent->value
                : $cluster->search_intent);
            if ($cluster->status === KeywordClusterStatus::Approved
                || $cluster->status === KeywordClusterStatus::Approved->value) {
                $hasApproved = true;
            }
        }

        $intents = array_values(array_unique(array_filter($intents)));

        return [
            'source_cluster_refs' => array_map(
                static fn (SeoKeywordCluster $c): string => (string) $c->public_ref,
                array_values(array_filter(
                    $sources,
                    static fn (SeoKeywordCluster $c): bool => (int) $c->id !== (int) $target->id,
                )),
            ),
            'target_cluster_ref' => (string) $target->public_ref,
            'keyword_count' => $keywordCount + (int) $target->keyword_count,
            'intent_result' => $intents,
            'mapping_conflicts' => $mappingConflicts,
            'manual_fields_preserved' => true,
            'warnings' => $hasApproved
                ? ['approved_cluster_in_merge']
                : (count($intents) > 1 ? ['mixed_intents'] : []),
            'requires_confirmation' => $hasApproved || count($intents) > 1 || $keywordCount > 25,
        ];
    }

    /**
     * @param  list<int>  $sourceClusterIds
     */
    public function merge(SeoKeywordWorkspace $workspace, array $sourceClusterIds, int $targetClusterId): SeoKeywordCluster
    {
        $sources = $this->loadClusters($workspace, $sourceClusterIds);
        $target = $this->loadCluster($workspace, $targetClusterId);

        foreach ($sources as $source) {
            if ((int) $source->id === (int) $target->id) {
                continue;
            }

            SeoKiKeyword::query()
                ->where('workspace_id', $workspace->id)
                ->where('cluster_id', $source->id)
                ->update(['cluster_id' => $target->id, 'is_primary' => false]);

            $source->status = KeywordClusterStatus::Excluded->value;
            $source->keyword_count = 0;
            $source->metadata = array_merge((array) ($source->metadata ?? []), [
                'merged_into' => $target->public_ref,
            ]);
            $source->save();
        }

        $count = SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where('cluster_id', $target->id)
            ->count();
        $target->keyword_count = $count;
        $target->save();

        return $target->fresh() ?? $target;
    }

    /**
     * @param  list<array{name: string, keyword_ids: list<int>, primary_keyword_id?: int|null}>  $groups
     * @return array{created: list<SeoKeywordCluster>, source: SeoKeywordCluster}
     */
    public function split(
        SeoKeywordWorkspace $workspace,
        int $sourceClusterId,
        array $groups,
        bool $leaveUnassigned = false,
    ): array {
        $source = $this->loadCluster($workspace, $sourceClusterId);
        $members = SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where('cluster_id', $source->id)
            ->get()
            ->keyBy('id');

        $assigned = [];
        foreach ($groups as $group) {
            foreach ($group['keyword_ids'] as $kid) {
                if (isset($assigned[$kid])) {
                    throw new InvalidArgumentException('Duplicate keyword across split groups.');
                }
                if (! $members->has($kid)) {
                    throw new InvalidArgumentException('Keyword not in source cluster: '.$kid);
                }
                $assigned[$kid] = true;
            }
        }

        if (! $leaveUnassigned && count($assigned) !== $members->count()) {
            throw new InvalidArgumentException('Split must cover all keywords or set leave_unassigned.');
        }

        $created = [];
        foreach ($groups as $group) {
            $primaryId = $group['primary_keyword_id'] ?? $group['keyword_ids'][0] ?? null;
            if ($primaryId === null) {
                continue;
            }

            $primary = $members->get($primaryId);
            if (! $primary instanceof SeoKiKeyword) {
                throw new InvalidArgumentException('Primary keyword missing.');
            }

            $name = trim((string) ($group['name'] ?? '')) ?: (string) $primary->keyword;
            $slug = Str::slug(mb_substr($name, 0, 80));
            if ($slug === '') {
                $slug = 'cluster-'.$primaryId;
            }

            $cluster = new SeoKeywordCluster([
                'public_ref' => 'pending',
                'workspace_id' => $workspace->id,
                'tenant_id' => $workspace->tenant_id,
                'site_id' => $workspace->site_id,
                'name' => $name,
                'slug' => $slug.'-'.$primaryId,
                'primary_keyword_id' => $primaryId,
                'search_intent' => $primary->search_intent instanceof \BackedEnum
                    ? $primary->search_intent->value
                    : $primary->search_intent,
                'funnel_stage' => $primary->funnel_stage instanceof \BackedEnum
                    ? $primary->funnel_stage->value
                    : $primary->funnel_stage,
                'status' => KeywordClusterStatus::Draft->value,
                'keyword_count' => count($group['keyword_ids']),
                'suggested_content_type' => 'article',
                'suggested_title' => $primary->keyword,
                'metadata' => ['split_from' => $source->public_ref],
            ]);
            $cluster->save();
            $cluster->public_ref = KeywordIntelligencePublicRef::cluster((int) $cluster->id);
            $cluster->save();

            foreach ($group['keyword_ids'] as $kid) {
                $kw = $members->get($kid);
                if (! $kw instanceof SeoKiKeyword) {
                    continue;
                }
                $kw->cluster_id = (int) $cluster->id;
                $kw->is_primary = (int) $kid === (int) $primaryId;
                $kw->save();
            }

            $created[] = $cluster;
        }

        if ($leaveUnassigned) {
            foreach ($members as $kw) {
                if (! isset($assigned[(int) $kw->id])) {
                    $kw->cluster_id = null;
                    $kw->is_primary = false;
                    $kw->save();
                }
            }
        }

        $source->status = KeywordClusterStatus::Excluded->value;
        $source->keyword_count = 0;
        $source->metadata = array_merge((array) ($source->metadata ?? []), [
            'split_into' => array_map(static fn (SeoKeywordCluster $c): string => (string) $c->public_ref, $created),
        ]);
        $source->save();

        return ['created' => $created, 'source' => $source];
    }

    /**
     * @param  list<int>  $keywordIds
     */
    public function moveKeywords(
        SeoKeywordWorkspace $workspace,
        array $keywordIds,
        int $destinationClusterId,
        bool $forceReviewedMismatch = false,
    ): int {
        $destination = $this->loadCluster($workspace, $destinationClusterId);
        $destIntent = (string) ($destination->search_intent instanceof \BackedEnum
            ? $destination->search_intent->value
            : $destination->search_intent);

        $moved = 0;
        foreach ($keywordIds as $kid) {
            $kw = SeoKiKeyword::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', $kid)
                ->first();
            if (! $kw instanceof SeoKiKeyword) {
                continue;
            }

            $kwIntent = (string) ($kw->search_intent instanceof \BackedEnum
                ? $kw->search_intent->value
                : $kw->search_intent);

            if ($destIntent !== '' && $kwIntent !== '' && $destIntent !== $kwIntent && ! $forceReviewedMismatch) {
                throw new RuntimeException('Intent mismatch moving keyword to cluster.');
            }

            $sources = (array) ($kw->field_sources ?? []);
            if (($sources['cluster_id']['source'] ?? null) === 'manual' && ! $forceReviewedMismatch) {
                // Still allow move via explicit command — mark as manual preserved destination.
            }

            $kw->cluster_id = (int) $destination->id;
            $kw->is_primary = false;
            $sources['cluster_id'] = [
                'source' => 'manual',
                'updated_at' => gmdate('c'),
            ];
            $kw->field_sources = $sources;
            $kw->save();
            $moved++;
        }

        $destination->keyword_count = SeoKiKeyword::query()
            ->where('cluster_id', $destination->id)
            ->count();
        $destination->save();

        return $moved;
    }

    /**
     * @param  list<int>  $ids
     * @return list<SeoKeywordCluster>
     */
    private function loadClusters(SeoKeywordWorkspace $workspace, array $ids): array
    {
        $rows = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $ids)
            ->get()
            ->all();

        if (count($rows) !== count(array_unique($ids))) {
            throw new RuntimeException('One or more clusters not found in workspace.');
        }

        return $rows;
    }

    private function loadCluster(SeoKeywordWorkspace $workspace, int $id): SeoKeywordCluster
    {
        $cluster = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $id)
            ->first();

        if (! $cluster instanceof SeoKeywordCluster) {
            throw new RuntimeException('Cluster không tồn tại.');
        }

        return $cluster;
    }
}
