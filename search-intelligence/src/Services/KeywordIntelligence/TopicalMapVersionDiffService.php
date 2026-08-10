<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicalMapVersion;

/**
 * Compact topical map version snapshot diff.
 * Supports array snapshots (unit/UI) and SeoTopicalMapVersion models.
 */
final class TopicalMapVersionDiffService
{
    /**
     * @param  array<string, mixed>|SeoTopicalMapVersion|null  $from
     * @param  array<string, mixed>|SeoTopicalMapVersion  $to
     * @return array<string, mixed>
     */
    public function diff(array|SeoTopicalMapVersion|null $from, array|SeoTopicalMapVersion $to): array
    {
        if ($to instanceof SeoTopicalMapVersion || $from instanceof SeoTopicalMapVersion || $from === null) {
            return $this->diffVersions(
                $from instanceof SeoTopicalMapVersion || $from === null ? $from : null,
                $to instanceof SeoTopicalMapVersion
                    ? $to
                    : $this->wrapSnapshotAsVersion($to),
            );
        }

        return $this->diffSnapshots($from, $to);
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array{
     *   topics_added: list<string>,
     *   topics_removed: list<string>,
     *   topics_moved: list<array<string, mixed>>,
     *   topics_renamed: list<array<string, mixed>>,
     *   clusters_attached: list<array<string, mixed>>,
     *   clusters_detached: list<array<string, mixed>>,
     *   clusters_moved: list<array<string, mixed>>,
     *   coverage_delta: float,
     *   gap_delta: float,
     *   blocking_conflicts_delta: int
     * }
     */
    public function diffSnapshots(array $from, array $to): array
    {
        $fromTopics = $this->indexBy((array) ($from['topics'] ?? []), 'topic_ref');
        $toTopics = $this->indexBy((array) ($to['topics'] ?? []), 'topic_ref');
        $fromAssign = $this->indexAssignments((array) ($from['assignments'] ?? []));
        $toAssign = $this->indexAssignments((array) ($to['assignments'] ?? []));

        $topicsAdded = [];
        $topicsRemoved = [];
        $topicsMoved = [];
        $topicsRenamed = [];

        foreach ($toTopics as $ref => $topic) {
            if (! isset($fromTopics[$ref])) {
                $topicsAdded[] = $ref;
                continue;
            }
            $old = $fromTopics[$ref];
            if (($old['parent_ref'] ?? null) !== ($topic['parent_ref'] ?? null)) {
                $topicsMoved[] = [
                    'topic_ref' => $ref,
                    'old_parent_ref' => $old['parent_ref'] ?? null,
                    'new_parent_ref' => $topic['parent_ref'] ?? null,
                ];
            }
            if ((string) ($old['name'] ?? '') !== (string) ($topic['name'] ?? '')) {
                $topicsRenamed[] = [
                    'topic_ref' => $ref,
                    'old_name' => $old['name'] ?? null,
                    'new_name' => $topic['name'] ?? null,
                ];
            }
        }
        foreach ($fromTopics as $ref => $_) {
            if (! isset($toTopics[$ref])) {
                $topicsRemoved[] = $ref;
            }
        }

        $attached = [];
        $detached = [];
        $moved = [];
        foreach ($toAssign as $key => $row) {
            if (isset($fromAssign[$key])) {
                continue;
            }
            if (($row['relationship'] ?? '') === 'primary' && isset($fromAssign['primary:'.$row['cluster_ref']])) {
                $moved[] = [
                    'cluster_ref' => $row['cluster_ref'],
                    'old_topic_ref' => $fromAssign['primary:'.$row['cluster_ref']]['topic_ref'] ?? null,
                    'new_topic_ref' => $row['topic_ref'] ?? null,
                ];
            } else {
                $attached[] = $row;
            }
        }
        foreach ($fromAssign as $key => $row) {
            if (isset($toAssign[$key])) {
                continue;
            }
            if (($row['relationship'] ?? '') !== 'primary') {
                continue;
            }
            $still = false;
            foreach ($toAssign as $toRow) {
                if (($toRow['cluster_ref'] ?? '') === ($row['cluster_ref'] ?? '') && ($toRow['relationship'] ?? '') === 'primary') {
                    $still = true;
                    break;
                }
            }
            if (! $still) {
                $detached[] = $row;
            }
        }

        $fromSummary = (array) ($from['summary'] ?? []);
        $toSummary = (array) ($to['summary'] ?? []);

        return [
            'topics_added' => $topicsAdded,
            'topics_removed' => $topicsRemoved,
            'topics_moved' => $topicsMoved,
            'topics_renamed' => $topicsRenamed,
            'clusters_attached' => $attached,
            'clusters_detached' => $detached,
            'clusters_moved' => $moved,
            'coverage_delta' => round((float) ($toSummary['coverage_score'] ?? 0) - (float) ($fromSummary['coverage_score'] ?? 0), 2),
            'gap_delta' => round((float) ($toSummary['gap_score'] ?? 0) - (float) ($fromSummary['gap_score'] ?? 0), 2),
            'blocking_conflicts_delta' => (int) ($toSummary['blocking_conflicts'] ?? 0) - (int) ($fromSummary['blocking_conflicts'] ?? 0),
        ];
    }

    /**
     * @return array{
     *   previous_version: int|null,
     *   current_version: int,
     *   added_topic_refs: list<string>,
     *   removed_topic_refs: list<string>,
     *   changed_topics: array<string, array<string, mixed>>,
     *   summary_delta: array<string, mixed>,
     *   snapshot_diff: array<string, mixed>
     * }
     */
    public function diffVersions(?SeoTopicalMapVersion $previous, SeoTopicalMapVersion $current): array
    {
        $previousSnapshot = (array) ($previous?->snapshot ?? []);
        $currentSnapshot = (array) ($current->snapshot ?? []);

        $previousTopics = $this->indexTopics($previousSnapshot);
        $currentTopics = $this->indexTopics($currentSnapshot);

        $addedRefs = array_values(array_diff(array_keys($currentTopics), array_keys($previousTopics)));
        $removedRefs = array_values(array_diff(array_keys($previousTopics), array_keys($currentTopics)));
        $commonRefs = array_intersect(array_keys($currentTopics), array_keys($previousTopics));

        $changed = [];
        foreach ($commonRefs as $ref) {
            $delta = $this->diffTopic($previousTopics[$ref], $currentTopics[$ref]);
            if ($delta !== null) {
                $changed[$ref] = $delta;
            }
        }

        $snapshotDiff = $this->diffSnapshots(
            $this->normalizeSnapshot($previousSnapshot, $previous?->summary),
            $this->normalizeSnapshot($currentSnapshot, $current->summary),
        );

        return [
            'previous_version' => $previous?->version,
            'current_version' => (int) $current->version,
            'added_topic_refs' => $addedRefs,
            'removed_topic_refs' => $removedRefs,
            'changed_topics' => $changed,
            'summary_delta' => [
                'topic_count' => count($currentTopics) - count($previousTopics),
                'previous_topic_count' => count($previousTopics),
                'current_topic_count' => count($currentTopics),
            ],
            'snapshot_diff' => $snapshotDiff,
        ];
    }

    /**
     * @return array{previous: SeoTopicalMapVersion, current: SeoTopicalMapVersion, diff: array<string, mixed>}|null
     */
    public function diffLatestForWorkspace(SeoKeywordWorkspace $workspace): ?array
    {
        $versions = SeoTopicalMapVersion::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('version')
            ->limit(2)
            ->get();

        if ($versions->count() < 2) {
            return null;
        }

        $current = $versions->first();
        $previous = $versions->get(1);

        if (! $current instanceof SeoTopicalMapVersion || ! $previous instanceof SeoTopicalMapVersion) {
            return null;
        }

        return [
            'previous' => $previous,
            'current' => $current,
            'diff' => $this->diffVersions($previous, $current),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{fields: array<string, array{from: mixed, to: mixed}>, added_cluster_refs: list<string>, removed_cluster_refs: list<string>}|null
     */
    private function diffTopic(array $before, array $after): ?array
    {
        $fields = [];
        foreach (['name', 'parent_ref', 'topic_type', 'depth'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $fields[$field] = ['from' => $before[$field] ?? null, 'to' => $after[$field] ?? null];
            }
        }

        $beforeClusters = (array) ($before['cluster_refs'] ?? []);
        $afterClusters = (array) ($after['cluster_refs'] ?? []);
        $addedClusters = array_values(array_diff($afterClusters, $beforeClusters));
        $removedClusters = array_values(array_diff($beforeClusters, $afterClusters));

        if ($fields === [] && $addedClusters === [] && $removedClusters === []) {
            return null;
        }

        return [
            'fields' => $fields,
            'added_cluster_refs' => $addedClusters,
            'removed_cluster_refs' => $removedClusters,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function indexTopics(array $snapshot): array
    {
        return $this->indexBy((array) ($snapshot['topics'] ?? []), 'topic_ref');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $ref = (string) ($row[$key] ?? '');
            if ($ref !== '') {
                $out[$ref] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexAssignments(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $cluster = (string) ($row['cluster_ref'] ?? '');
            $topic = (string) ($row['topic_ref'] ?? '');
            $rel = (string) ($row['relationship'] ?? 'primary');
            if ($cluster === '' || $topic === '') {
                continue;
            }
            $key = $rel === 'primary' ? 'primary:'.$cluster : $rel.':'.$cluster.':'.$topic;
            $out[$key] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>
     */
    private function normalizeSnapshot(array $snapshot, mixed $summary): array
    {
        if (! isset($snapshot['summary']) && is_array($summary)) {
            $snapshot['summary'] = $summary;
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function wrapSnapshotAsVersion(array $snapshot): SeoTopicalMapVersion
    {
        $version = new SeoTopicalMapVersion;
        $version->version = (int) ($snapshot['version'] ?? 0);
        $version->snapshot = $snapshot;
        $version->summary = (array) ($snapshot['summary'] ?? []);

        return $version;
    }
}
