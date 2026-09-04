<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MCP planning +N signal — Draft reviewed OR project.meta.mcp_planning (never both).
 *
 * Does not mutate MCP score % — UI-only pending pipeline count.
 */
final class McpPlanningSignalService
{
    public function __construct(
        private readonly McpPlanningMetaStore $metaStore = new McpPlanningMetaStore,
        private readonly McpPlanningSignalResolver $resolver = new McpPlanningSignalResolver,
    ) {}

    /**
     * @return array{total: int, by_site: array<int, int>, by_cluster: array<string, int>}
     */
    public function countsForSite(int $siteId): array
    {
        if ($siteId <= 0) {
            return ['total' => 0, 'by_site' => [], 'by_cluster' => []];
        }

        $signals = $this->collectSignals([$siteId]);

        return $this->aggregate($signals);
    }

    /**
     * @param  list<int>|null  $siteIds  null = all sites with signals
     * @return array{total: int, by_site: array<int, int>, by_cluster: array<string, int>}
     */
    public function counts(?array $siteIds = null): array
    {
        $signals = $this->collectSignals($siteIds);

        return $this->aggregate($signals);
    }

    /**
     * @return array<string, int> cluster_key => count
     */
    public function countsByClusterKey(int $siteId): array
    {
        return $this->countsForSite($siteId)['by_cluster'];
    }

    public function countForSite(int $siteId): int
    {
        return (int) ($this->countsForSite($siteId)['by_site'][$siteId] ?? 0);
    }

    public function countForCluster(int $siteId, string $clusterKey): int
    {
        $clusterKey = trim($clusterKey);
        if ($siteId <= 0 || $clusterKey === '') {
            return 0;
        }

        return (int) ($this->countsByClusterKey($siteId)[$clusterKey] ?? 0);
    }

    /**
     * Attach mcp_planning entries for tasks just moved Draft → Execution.
     *
     * @param  list<int>  $taskIds
     */
    public function recordSplitToExecution(SeoProject $execution, array $taskIds): void
    {
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
        if ($taskIds === [] || $execution->isDraftPlanning() || $execution->isProjectArchived()) {
            return;
        }

        $tasks = SeoProjectTask::query()
            ->whereIn('id', $taskIds)
            ->where('project_id', (int) $execution->getKey())
            ->get()
            ->keyBy(static fn (SeoProjectTask $t): int => (int) $t->getKey());

        $origins = SeoContentProjectItemOrigin::query()
            ->whereIn('project_task_id', $taskIds)
            ->get()
            ->keyBy(static fn (SeoContentProjectItemOrigin $o): int => (int) $o->project_task_id);

        $entries = [];
        foreach ($taskIds as $taskId) {
            $task = $tasks->get($taskId);
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $origin = $origins->get($taskId);
            $entry = $this->resolver->entryForTask(
                $task,
                $origin instanceof SeoContentProjectItemOrigin ? $origin : null,
            );
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        if ($entries !== []) {
            $this->metaStore->upsertItems($execution, $entries);
        }
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return list<array{site_id: int, cluster_key: string|null, item_key: string}>
     */
    private function collectSignals(?array $siteIds): array
    {
        $siteFilter = null;
        if ($siteIds !== null) {
            $siteFilter = array_values(array_filter(array_map('intval', $siteIds)));
            if ($siteFilter === []) {
                return [];
            }
        }

        /** @var array<string, array{site_id: int, cluster_key: string|null, item_key: string}> $byItem */
        $byItem = [];

        foreach ($this->draftReviewedSignals($siteFilter) as $signal) {
            $byItem[$signal['item_key']] = $signal;
        }

        foreach ($this->projectMetaSignals($siteFilter) as $signal) {
            // Project meta wins if same item somehow present in both (should not happen).
            $byItem[$signal['item_key']] = $signal;
        }

        return array_values($byItem);
    }

    /**
     * @param  list<int>|null  $siteFilter
     * @return list<array{site_id: int, cluster_key: string|null, item_key: string}>
     */
    private function draftReviewedSignals(?array $siteFilter): array
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_reviewed_at')) {
            return [];
        }

        // Query builder (not Eloquent): SoftDeletes would emit seo_project_tasks.deleted_at
        // while the table is aliased as `t`, which MySQL rejects.
        $query = DB::connection('omi_seo_ai')
            ->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->where('p.status', SeoProject::STATUS_DRAFT)
            ->whereNull('p.archived_at')
            ->whereNull('t.archived_at')
            ->whereNotNull('t.planning_reviewed_at')
            ->where('t.site_id', '>', 0)
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('t.deleted_at');
        }

        if ($siteFilter !== null) {
            $query->whereIn('t.site_id', $siteFilter);
        }

        $ids = $query->orderBy('t.id')->pluck('t.id')->map(static fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        $tasks = SeoProjectTask::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(static fn (SeoProjectTask $t): int => (int) $t->getKey());

        $origins = SeoContentProjectItemOrigin::query()
            ->whereIn('project_task_id', $ids)
            ->get()
            ->keyBy(static fn (SeoContentProjectItemOrigin $o): int => (int) $o->project_task_id);

        $out = [];
        foreach ($ids as $taskId) {
            $task = $tasks->get($taskId);
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $origin = $origins->get($taskId);
            $resolved = $this->resolver->resolve(
                $task,
                $origin instanceof SeoContentProjectItemOrigin ? $origin : null,
            );
            $siteId = (int) $resolved['site_id'];
            if ($siteId <= 0) {
                continue;
            }
            $out[] = [
                'site_id' => $siteId,
                'cluster_key' => $resolved['cluster_key'],
                'item_key' => 'draft:'.$taskId,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>|null  $siteFilter
     * @return list<array{site_id: int, cluster_key: string|null, item_key: string}>
     */
    private function projectMetaSignals(?array $siteFilter): array
    {
        if (! $this->metaStore->metaColumnAvailable()) {
            return [];
        }

        $projects = SeoProject::query()
            ->activeProjects()
            ->where('status', '!=', SeoProject::STATUS_DRAFT)
            ->whereNotNull('meta')
            ->get(['id', 'meta']);

        $out = [];
        foreach ($projects as $project) {
            foreach ($this->metaStore->items($project) as $item) {
                $siteId = (int) $item['site_id'];
                if ($siteId <= 0) {
                    continue;
                }
                if ($siteFilter !== null && ! in_array($siteId, $siteFilter, true)) {
                    continue;
                }
                $out[] = [
                    'site_id' => $siteId,
                    'cluster_key' => $item['cluster_key'] !== null ? (string) $item['cluster_key'] : null,
                    'item_key' => 'exec:'.(int) $item['project_item_id'],
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{site_id: int, cluster_key: string|null, item_key: string}>  $signals
     * @return array{total: int, by_site: array<int, int>, by_cluster: array<string, int>}
     */
    private function aggregate(array $signals): array
    {
        $bySite = [];
        $byCluster = [];
        foreach ($signals as $signal) {
            $siteId = (int) $signal['site_id'];
            $bySite[$siteId] = ($bySite[$siteId] ?? 0) + 1;
            $clusterKey = trim((string) ($signal['cluster_key'] ?? ''));
            if ($clusterKey !== '') {
                $byCluster[$clusterKey] = ($byCluster[$clusterKey] ?? 0) + 1;
            }
        }

        return [
            'total' => count($signals),
            'by_site' => $bySite,
            'by_cluster' => $byCluster,
        ];
    }
}
