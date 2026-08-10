<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicClusterRelationship;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicalMapVersionStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicType;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiTopic;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicalMapVersion;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicClusterLink;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Topic tree mutations + map version review/approve/save + conflict detection.
 */
final class KeywordTopicalMapMutationService
{
    public function __construct(
        private readonly ?TopicalMapConflictDetector $conflictDetector = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTopic(SeoKeywordWorkspace $workspace, array $attributes, ?string $parentTopicRef = null): SeoKiTopic
    {
        $maxTopics = max(1, (int) config('seo-content-ai.keyword_intelligence.topical_map.max_topics_per_workspace', 200));
        $count = SeoKiTopic::query()->where('workspace_id', $workspace->id)->count();
        if ($count >= $maxTopics) {
            throw new RuntimeException('topical_map.hierarchy_invalid');
        }

        $parent = null;
        $depth = 0;
        $pathPrefix = (string) $workspace->id;

        if ($parentTopicRef !== null && trim($parentTopicRef) !== '') {
            $parent = $this->resolveTopic($workspace, $parentTopicRef);
            $depth = (int) $parent->depth + 1;
            $maxDepth = max(1, (int) config('seo-content-ai.keyword_intelligence.topical_map.max_depth', 4));
            if ($depth > $maxDepth) {
                throw new RuntimeException('topical_map.hierarchy_invalid');
            }
            $pathPrefix = (string) ($parent->path ?: $parent->id);
        }

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Topic name is required.');
        }

        $slug = trim((string) ($attributes['slug'] ?? '')) ?: (Str::slug($name) ?: 'topic-'.Str::random(6));
        $topicType = (string) ($attributes['topic_type'] ?? KeywordTopicType::Subtopic->value);
        if (KeywordTopicType::tryFrom($topicType) === null) {
            throw new InvalidArgumentException('Invalid topic_type.');
        }

        $topic = new SeoKiTopic([
            'public_ref' => 'pending',
            'workspace_id' => $workspace->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'slug' => $slug,
            'topic_type' => $topicType,
            'status' => KeywordTopicStatus::Draft->value,
            'depth' => $depth,
            'path' => $pathPrefix.'/'.$slug,
            'metadata' => array_merge((array) ($attributes['metadata'] ?? []), ['is_manual' => true]),
        ]);
        $topic->save();
        $topic->public_ref = KeywordIntelligencePublicRef::topic((int) $topic->id);
        $topic->save();

        $workspace->topic_count = SeoKiTopic::query()->where('workspace_id', $workspace->id)->count();
        $workspace->save();

        return $topic;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTopic(SeoKeywordWorkspace $workspace, string $topicRef, array $attributes): SeoKiTopic
    {
        $topic = $this->resolveTopic($workspace, $topicRef);

        if (isset($attributes['name'])) {
            $name = trim((string) $attributes['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Topic name cannot be empty.');
            }
            $topic->name = $name;
        }

        if (isset($attributes['slug'])) {
            $slug = trim((string) $attributes['slug']);
            if ($slug !== '') {
                $topic->slug = $slug;
            }
        }

        if (isset($attributes['topic_type'])) {
            $type = (string) $attributes['topic_type'];
            if (KeywordTopicType::tryFrom($type) === null) {
                throw new InvalidArgumentException('Invalid topic_type.');
            }
            $topic->topic_type = $type;
        }

        if (isset($attributes['status'])) {
            $status = (string) $attributes['status'];
            if (KeywordTopicStatus::tryFrom($status) === null) {
                throw new InvalidArgumentException('Invalid topic status.');
            }
            $topic->status = $status;
        }

        $meta = (array) ($topic->metadata ?? []);
        $meta['is_manual'] = true;
        if (isset($attributes['metadata']) && is_array($attributes['metadata'])) {
            $meta = array_merge($meta, $attributes['metadata']);
        }
        $topic->metadata = $meta;
        $topic->save();

        return $topic;
    }

    /**
     * @return array{topic: SeoKiTopic, descendants_moved: int, requires_confirmation: bool, descendant_count: int}
     */
    public function previewMoveTopic(SeoKeywordWorkspace $workspace, string $topicRef, ?string $newParentTopicRef): array
    {
        $topic = $this->resolveTopic($workspace, $topicRef);
        $descendantCount = $this->descendantIds($topic)->count();

        return [
            'topic' => $topic,
            'descendants_moved' => $descendantCount,
            'requires_confirmation' => $descendantCount > 0,
            'descendant_count' => $descendantCount,
            'new_parent_topic_ref' => $newParentTopicRef,
        ];
    }

    public function moveTopic(SeoKeywordWorkspace $workspace, string $topicRef, ?string $newParentTopicRef): SeoKiTopic
    {
        return DB::connection('omi_seo_ai')->transaction(function () use ($workspace, $topicRef, $newParentTopicRef): SeoKiTopic {
            $topic = $this->resolveTopic($workspace, $topicRef);

            $newParent = null;
            $depth = 0;
            $pathPrefix = (string) $workspace->id;

            if ($newParentTopicRef !== null && trim($newParentTopicRef) !== '') {
                $newParent = $this->resolveTopic($workspace, $newParentTopicRef);
                if ((int) $newParent->id === (int) $topic->id) {
                    throw new RuntimeException('topical_map.hierarchy_invalid');
                }
                if ($this->descendantIds($topic)->contains((int) $newParent->id)) {
                    throw new RuntimeException('topical_map.hierarchy_invalid');
                }
                $depth = (int) $newParent->depth + 1;
                $pathPrefix = (string) ($newParent->path ?: $newParent->id);
            }

            $maxDepth = max(1, (int) config('seo-content-ai.keyword_intelligence.topical_map.max_depth', 4));
            if ($depth > $maxDepth) {
                throw new RuntimeException('topical_map.hierarchy_invalid');
            }

            $topic->parent_id = $newParent?->id;
            $topic->depth = $depth;
            $topic->path = $pathPrefix.'/'.$topic->slug;
            $meta = (array) ($topic->metadata ?? []);
            $meta['is_manual'] = true;
            $topic->metadata = $meta;
            $topic->save();

            $this->repathDescendants($topic);

            return $topic->fresh() ?? $topic;
        });
    }

    public function deleteEmptyTopic(SeoKeywordWorkspace $workspace, string $topicRef): void
    {
        $topic = $this->resolveTopic($workspace, $topicRef);

        if ($topic->topic_type === KeywordTopicType::Root) {
            throw new InvalidArgumentException('Cannot delete root topic.');
        }

        $hasChildren = SeoKiTopic::query()->where('parent_id', $topic->id)->exists();
        $hasLinks = SeoTopicClusterLink::query()->where('topic_id', $topic->id)->exists();
        $hasClusters = SeoKeywordCluster::query()->where('topic_id', $topic->id)->exists();

        if ($hasChildren || $hasLinks || $hasClusters) {
            throw new InvalidArgumentException('Topic is not empty.');
        }

        $topic->delete();
        $workspace->topic_count = SeoKiTopic::query()->where('workspace_id', $workspace->id)->count();
        $workspace->save();
    }

    public function attachCluster(
        SeoKeywordWorkspace $workspace,
        string $topicRef,
        string $clusterRef,
        string $relationship = 'primary',
    ): SeoTopicClusterLink {
        $topic = $this->resolveTopic($workspace, $topicRef);
        $cluster = $this->resolveCluster($workspace, $clusterRef);
        $rel = KeywordTopicClusterRelationship::tryFrom($relationship)
            ?? throw new InvalidArgumentException('Invalid relationship.');

        return DB::connection('omi_seo_ai')->transaction(function () use ($topic, $cluster, $rel): SeoTopicClusterLink {
            if ($rel === KeywordTopicClusterRelationship::Primary) {
                SeoTopicClusterLink::query()
                    ->where('cluster_id', $cluster->id)
                    ->where('relationship', KeywordTopicClusterRelationship::Primary->value)
                    ->where('topic_id', '!=', $topic->id)
                    ->update(['relationship' => KeywordTopicClusterRelationship::Supporting->value]);
                $cluster->topic_id = $topic->id;
                $cluster->save();
            }

            $link = SeoTopicClusterLink::query()
                ->where('topic_id', $topic->id)
                ->where('cluster_id', $cluster->id)
                ->first();

            if (! $link instanceof SeoTopicClusterLink) {
                $link = new SeoTopicClusterLink([
                    'public_ref' => 'pending',
                    'topic_id' => $topic->id,
                    'cluster_id' => $cluster->id,
                    'position' => 0,
                ]);
            }

            $link->relationship = $rel->value;
            $link->save();
            if ($link->public_ref === 'pending' || $link->public_ref === null || $link->public_ref === '') {
                $link->public_ref = KeywordIntelligencePublicRef::topicClusterLink((int) $link->id);
                $link->save();
            }

            $this->refreshTopicCounts($topic);

            return $link;
        });
    }

    public function detachCluster(SeoKeywordWorkspace $workspace, string $topicRef, string $clusterRef): void
    {
        $topic = $this->resolveTopic($workspace, $topicRef);
        $cluster = $this->resolveCluster($workspace, $clusterRef);

        SeoTopicClusterLink::query()
            ->where('topic_id', $topic->id)
            ->where('cluster_id', $cluster->id)
            ->delete();

        if ((int) $cluster->topic_id === (int) $topic->id) {
            $cluster->topic_id = null;
            $cluster->save();
        }

        $this->refreshTopicCounts($topic);
    }

    public function moveClusterPrimary(SeoKeywordWorkspace $workspace, string $clusterRef, string $newTopicRef): SeoTopicClusterLink
    {
        $this->detachPrimary($workspace, $clusterRef);

        return $this->attachCluster($workspace, $newTopicRef, $clusterRef, KeywordTopicClusterRelationship::Primary->value);
    }

    public function setTopicRelationship(
        SeoKeywordWorkspace $workspace,
        string $topicRef,
        string $clusterRef,
        string $relationship,
    ): SeoTopicClusterLink {
        return $this->attachCluster($workspace, $topicRef, $clusterRef, $relationship);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function detectConflicts(SeoKeywordWorkspace $workspace): array
    {
        if ($this->conflictDetector instanceof TopicalMapConflictDetector) {
            return $this->conflictDetector->detect($workspace);
        }

        $conflicts = [];

        $multiPrimary = SeoTopicClusterLink::query()
            ->selectRaw('cluster_id, COUNT(*) as c')
            ->where('relationship', KeywordTopicClusterRelationship::Primary->value)
            ->whereIn('topic_id', SeoKiTopic::query()->where('workspace_id', $workspace->id)->select('id'))
            ->groupBy('cluster_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('cluster_id');

        foreach ($multiPrimary as $clusterId) {
            $conflicts[] = [
                'type' => 'multiple_primary_topics',
                'cluster_ref' => KeywordIntelligencePublicRef::cluster((int) $clusterId),
                'blocking' => true,
            ];
        }

        $approvedWithoutTopic = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', KeywordClusterStatus::Approved->value)
            ->whereNull('topic_id')
            ->limit(50)
            ->get();

        foreach ($approvedWithoutTopic as $cluster) {
            $conflicts[] = [
                'type' => 'approved_cluster_without_topic',
                'cluster_ref' => $cluster->public_ref,
                'blocking' => true,
            ];
        }

        $maxDepth = max(1, (int) config('seo-content-ai.keyword_intelligence.topical_map.max_depth', 4));
        $tooDeep = SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->where('depth', '>', $maxDepth)
            ->limit(50)
            ->get();

        foreach ($tooDeep as $topic) {
            $conflicts[] = [
                'type' => 'topic_depth_exceeded',
                'topic_ref' => $topic->public_ref,
                'blocking' => true,
            ];
        }

        return $conflicts;
    }

    public function reviewMapVersion(SeoKeywordWorkspace $workspace, string $mapVersionRef, ?int $actorId = null): SeoTopicalMapVersion
    {
        $version = $this->resolveMapVersion($workspace, $mapVersionRef);
        if (! in_array((string) $version->status, [
            KeywordTopicalMapVersionStatus::Draft->value,
            KeywordTopicalMapVersionStatus::Reviewed->value,
        ], true)) {
            throw new InvalidArgumentException('Only draft/reviewed map versions can be marked reviewed.');
        }

        $version->status = KeywordTopicalMapVersionStatus::Reviewed->value;
        $version->save();

        return $version;
    }

    public function approveMapVersion(
        SeoKeywordWorkspace $workspace,
        string $mapVersionRef,
        ?int $actorId = null,
        bool $allowBlockingOverride = false,
    ): SeoTopicalMapVersion {
        $version = $this->resolveMapVersion($workspace, $mapVersionRef);

        $conflicts = $this->detectConflicts($workspace);
        $blocking = array_values(array_filter(
            $conflicts,
            static fn (array $c): bool => ($c['blocking'] ?? false) === true || ($c['risk'] ?? '') === 'blocking',
        ));

        if ($blocking !== [] && ! $allowBlockingOverride) {
            throw new RuntimeException('topical_map.approval_blocked');
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($workspace, $version, $actorId): SeoTopicalMapVersion {
            SeoTopicalMapVersion::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', KeywordTopicalMapVersionStatus::Approved->value)
                ->where('id', '!=', $version->id)
                ->update([
                    'status' => KeywordTopicalMapVersionStatus::Superseded->value,
                    'superseded_by_version_id' => $version->id,
                ]);

            $version->status = KeywordTopicalMapVersionStatus::Approved->value;
            $version->approved_at = now();
            $version->approved_by = $actorId;
            $version->save();

            return $version;
        });
    }

    /**
     * Snapshot current topic tree into a new draft map version (manual save).
     */
    public function saveMapVersion(SeoKeywordWorkspace $workspace, ?int $actorId = null, ?string $mode = null): SeoTopicalMapVersion
    {
        $root = SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('parent_id')
            ->first();

        $pillars = SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->where('topic_type', KeywordTopicType::Pillar->value)
            ->orderBy('id')
            ->get();

        $versionNo = (int) (SeoTopicalMapVersion::query()->where('workspace_id', $workspace->id)->max('version') ?? 0) + 1;
        $clusterCount = SeoKeywordCluster::query()->where('workspace_id', $workspace->id)->whereNotNull('topic_id')->count();
        $totalVolume = (int) SeoKeywordCluster::query()->where('workspace_id', $workspace->id)->whereNotNull('topic_id')->sum('total_search_volume');

        $snapshot = [
            'root' => $root instanceof SeoKiTopic ? [
                'topic_ref' => $root->public_ref,
                'name' => $root->name,
            ] : null,
            'pillars' => $pillars->map(static fn (SeoKiTopic $p): array => [
                'topic_ref' => $p->public_ref,
                'name' => $p->name,
                'keyword_count' => $p->keyword_count,
                'cluster_count' => $p->cluster_count,
                'total_search_volume' => $p->total_search_volume,
            ])->all(),
            'topics' => SeoKiTopic::query()
                ->where('workspace_id', $workspace->id)
                ->orderBy('depth')
                ->orderBy('id')
                ->get()
                ->map(static fn (SeoKiTopic $t): array => [
                    'topic_ref' => $t->public_ref,
                    'parent_topic_ref' => $t->parent_id !== null
                        ? KeywordIntelligencePublicRef::topic((int) $t->parent_id)
                        : null,
                    'name' => $t->name,
                    'topic_type' => $t->topic_type instanceof \BackedEnum ? $t->topic_type->value : (string) $t->topic_type,
                    'depth' => $t->depth,
                    'status' => $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status,
                ])
                ->all(),
        ];

        $mapVersion = new SeoTopicalMapVersion([
            'public_ref' => 'pending',
            'workspace_id' => $workspace->id,
            'tenant_id' => $workspace->tenant_id ?? null,
            'site_id' => $workspace->site_id,
            'version' => $versionNo,
            'status' => KeywordTopicalMapVersionStatus::Draft->value,
            'mode' => $mode,
            'snapshot' => $snapshot,
            'summary' => [
                'pillar_count' => $pillars->count(),
                'cluster_count' => $clusterCount,
                'total_search_volume' => $totalVolume,
                'topic_count' => SeoKiTopic::query()->where('workspace_id', $workspace->id)->count(),
                'saved_manually' => true,
            ],
            'generated_by' => $actorId,
            'generated_at' => now(),
        ]);
        $mapVersion->save();
        $mapVersion->public_ref = KeywordIntelligencePublicRef::mapVersion((int) $mapVersion->id);
        $mapVersion->save();

        return $mapVersion;
    }

    public function resolveTopic(SeoKeywordWorkspace $workspace, string $topicRef): SeoKiTopic
    {
        $id = KeywordIntelligencePublicRef::resolveTopicIdStrict($topicRef);
        $topic = SeoKiTopic::query()->where('workspace_id', $workspace->id)->where('id', $id)->first();
        if (! $topic instanceof SeoKiTopic) {
            throw new RuntimeException('Topic không tồn tại.');
        }

        return $topic;
    }

    public function resolveCluster(SeoKeywordWorkspace $workspace, string $clusterRef): SeoKeywordCluster
    {
        $id = KeywordIntelligencePublicRef::resolveClusterIdStrict($clusterRef);
        $cluster = SeoKeywordCluster::query()->where('workspace_id', $workspace->id)->where('id', $id)->first();
        if (! $cluster instanceof SeoKeywordCluster) {
            throw new RuntimeException('Cluster không tồn tại.');
        }

        return $cluster;
    }

    public function resolveMapVersion(SeoKeywordWorkspace $workspace, string $mapVersionRef): SeoTopicalMapVersion
    {
        $id = KeywordIntelligencePublicRef::resolveMapVersionIdStrict($mapVersionRef);
        $version = SeoTopicalMapVersion::query()->where('workspace_id', $workspace->id)->where('id', $id)->first();
        if (! $version instanceof SeoTopicalMapVersion) {
            throw new RuntimeException('Map version không tồn tại.');
        }

        return $version;
    }

    private function detachPrimary(SeoKeywordWorkspace $workspace, string $clusterRef): void
    {
        $cluster = $this->resolveCluster($workspace, $clusterRef);
        $primaryLinks = SeoTopicClusterLink::query()
            ->where('cluster_id', $cluster->id)
            ->where('relationship', KeywordTopicClusterRelationship::Primary->value)
            ->get();

        foreach ($primaryLinks as $link) {
            $link->delete();
            $topic = SeoKiTopic::query()->find($link->topic_id);
            if ($topic instanceof SeoKiTopic) {
                $this->refreshTopicCounts($topic);
            }
        }

        $cluster->topic_id = null;
        $cluster->save();
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function descendantIds(SeoKiTopic $topic)
    {
        $ids = collect();
        $frontier = [$topic->id];

        while ($frontier !== []) {
            $children = SeoKiTopic::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            if ($children === []) {
                break;
            }
            foreach ($children as $id) {
                $ids->push((int) $id);
            }
            $frontier = $children;
        }

        return $ids;
    }

    private function repathDescendants(SeoKiTopic $topic): void
    {
        $children = SeoKiTopic::query()->where('parent_id', $topic->id)->get();
        foreach ($children as $child) {
            $child->depth = (int) $topic->depth + 1;
            $child->path = $topic->path.'/'.$child->slug;
            $child->save();
            $this->repathDescendants($child);
        }
    }

    private function refreshTopicCounts(SeoKiTopic $topic): void
    {
        $links = SeoTopicClusterLink::query()->where('topic_id', $topic->id)->pluck('cluster_id');
        $topic->cluster_count = $links->count();
        $topic->keyword_count = (int) SeoKeywordCluster::query()->whereIn('id', $links)->sum('keyword_count');
        $topic->total_search_volume = (int) SeoKeywordCluster::query()->whereIn('id', $links)->sum('total_search_volume');
        $topic->save();
    }
}
