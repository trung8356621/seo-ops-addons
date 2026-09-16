<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTaskPlanningAttribution;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileService;

/**
 * Active Site Planning units: Shared Draft (by planning_month) + active execution (by project month).
 * Deduped by canonical project_task_id — Draft→execution move must not double-count.
 *
 * Terminal lifecycle (published / completed / archived) is excluded via
 * {@see SitePlanningActiveUnitPredicate} — same predicate for matrix + Month Detail.
 *
 * planning_mcp_share (per cluster, per site/month) =
 *   distinct active attributed tasks in cluster
 *   / distinct active attributed tasks for site+month
 *   × 100
 * (rounded to 1 decimal). NOT actual Site MCP %.
 *
 * source_counts is provenance (item origin), separate from cluster attribution.
 */
final class SitePlanningActiveUnitAggregator
{
    /** Read-model only: no origin row — never invent DB provenance. */
    public const SOURCE_UNKNOWN = 'unknown';

    public function __construct(
        private readonly ?SiteMcpTopicalProfileService $mcpProfile = null,
    ) {}

    /**
     * @return array{
     *   planned: int,
     *   draft: int,
     *   execution: int,
     *   by_status: array<string, int>,
     *   clusters: list<array<string, mixed>>,
     *   unattributed: array{count: int, task_ids: list<int>},
     *   attributed: array{count: int, task_ids: list<int>},
     *   source_counts: array<string, int>,
     *   tasks: list<array<string, mixed>>
     * }
     */
    public function forSiteMonth(int $siteId, string $planningMonth): array
    {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        if ($siteId <= 0) {
            return $this->emptyPayload();
        }

        $units = $this->loadUnits($siteId, $month);
        $attributions = $this->loadAttributions(array_keys($units));
        $origins = $this->loadOrigins(array_keys($units));

        $draft = 0;
        $execution = 0;
        $byStatus = [
            'draft' => 0,
            'generated' => 0,
            'editing' => 0,
            'scheduled' => 0,
            'published' => 0,
            'other' => 0,
        ];
        $clusterBuckets = [];
        $unattributedIds = [];
        $attributedTaskIds = [];
        $attributedIdsList = [];
        $sourceCounts = [];
        $taskRows = [];

        foreach ($units as $taskId => $unit) {
            if ($unit['in_draft']) {
                $draft++;
            }
            if ($unit['in_execution']) {
                $execution++;
            }

            $status = $this->normalizeStatusBucket($unit);
            $byStatus[$status] = (int) ($byStatus[$status] ?? 0) + 1;

            $origin = $origins[$taskId] ?? null;
            $sourceType = is_array($origin)
                ? $this->normalizeSourceType((string) ($origin['source_type'] ?? ''))
                : self::SOURCE_UNKNOWN;
            $sourceCounts[$sourceType] = (int) ($sourceCounts[$sourceType] ?? 0) + 1;

            $attr = $attributions[$taskId] ?? null;
            $clusterRef = is_array($attr) ? trim((string) ($attr['cluster_ref'] ?? '')) : '';
            $dna = is_array($attr) && is_array($attr['dna_phrases'] ?? null)
                ? array_values(array_filter(array_map('strval', $attr['dna_phrases'])))
                : [];

            if ($clusterRef === '') {
                $unattributedIds[] = $taskId;
            } else {
                $attributedTaskIds[$taskId] = true;
                $attributedIdsList[] = $taskId;
                if (! isset($clusterBuckets[$clusterRef])) {
                    $clusterBuckets[$clusterRef] = [
                        'cluster_ref' => $clusterRef,
                        'cluster_name' => (string) ($attr['cluster_name_snapshot'] ?? $clusterRef),
                        'task_ids' => [],
                        'dna' => [],
                    ];
                }
                $clusterBuckets[$clusterRef]['task_ids'][$taskId] = true;
                foreach ($dna as $phrase) {
                    $key = mb_strtolower(trim($phrase), 'UTF-8');
                    if ($key !== '') {
                        $clusterBuckets[$clusterRef]['dna'][$key] = $phrase;
                    }
                }
            }

            $taskRows[] = [
                'task_id' => $taskId,
                'site_id' => $siteId,
                'planning_month' => $month,
                'in_draft' => $unit['in_draft'],
                'in_execution' => $unit['in_execution'],
                'status_bucket' => $status,
                'keyword' => $unit['keyword'],
                'title' => $unit['title'],
                'article_id' => $unit['article_id'],
                'source_type' => $sourceType,
                'source_fingerprint' => is_array($origin) ? ($origin['source_fingerprint'] ?? null) : null,
                'source_article_id' => is_array($origin) ? ($origin['source_article_id'] ?? null) : null,
                'cluster_ref' => $clusterRef !== '' ? $clusterRef : null,
                'attribution_status' => $clusterRef !== ''
                    ? SeoContentProjectTaskPlanningAttribution::STATUS_ATTRIBUTED
                    : SeoContentProjectTaskPlanningAttribution::STATUS_UNATTRIBUTED,
            ];
        }

        ksort($sourceCounts);

        $attributedTotal = count($attributedTaskIds);
        $actualMcp = $this->actualMcpByCluster($siteId);
        $actualDna = $this->actualDnaCountByCluster($siteId);

        $clusters = [];
        foreach ($clusterBuckets as $bucket) {
            $taskCount = count($bucket['task_ids']);
            $clusterRef = (string) $bucket['cluster_ref'];
            // Formula: planning_mcp_share = attributed_cluster_tasks / attributed_site_month_tasks × 100
            $planningMcp = $attributedTotal > 0
                ? round(($taskCount / $attributedTotal) * 100, 1)
                : 0.0;

            $clusters[] = [
                'cluster_ref' => $clusterRef,
                'cluster_name' => (string) $bucket['cluster_name'],
                'article_count' => $taskCount,
                'actual_mcp_share' => (float) ($actualMcp[$clusterRef] ?? 0.0),
                'planning_mcp_share' => $planningMcp,
                'dna_current' => (int) ($actualDna[$clusterRef] ?? 0),
                'dna_planned' => count($bucket['dna']),
                'task_ids' => array_map('intval', array_keys($bucket['task_ids'])),
            ];
        }

        usort($clusters, static fn (array $a, array $b): int => $b['article_count'] <=> $a['article_count']);

        return [
            'planned' => count($units),
            'draft' => $draft,
            'execution' => $execution,
            'by_status' => $byStatus,
            'clusters' => $clusters,
            'unattributed' => [
                'count' => count($unattributedIds),
                'task_ids' => $unattributedIds,
            ],
            'attributed' => [
                'count' => count($attributedIdsList),
                'task_ids' => $attributedIdsList,
            ],
            'source_counts' => $sourceCounts,
            'tasks' => $taskRows,
        ];
    }

    /**
     * Distinct active planning unit counts keyed by site_id for one month.
     *
     * @return array<int, int>
     */
    public function plannedCountsBySite(string $planningMonth): array
    {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $monthDate = ContentProjectMonthContext::toDateString($month);
        $counts = [];

        foreach ($this->draftTaskRows($month, $monthDate) as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            $taskId = (int) ($row->id ?? 0);
            if ($siteId <= 0 || $taskId <= 0) {
                continue;
            }
            $counts[$siteId][$taskId] = true;
        }

        foreach ($this->executionTaskRows($monthDate) as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            $taskId = (int) ($row->id ?? 0);
            if ($siteId <= 0 || $taskId <= 0) {
                continue;
            }
            $counts[$siteId][$taskId] = true;
        }

        $out = [];
        foreach ($counts as $siteId => $taskMap) {
            $out[(int) $siteId] = count($taskMap);
        }

        return $out;
    }

    /**
     * @return array<int, array{
     *   in_draft: bool,
     *   in_execution: bool,
     *   keyword: string,
     *   title: string,
     *   article_id: int|null,
     *   status: string,
     *   scheduled_publish_at: mixed,
     *   publish_published_at: mixed
     * }>
     */
    private function loadUnits(int $siteId, string $month): array
    {
        $monthDate = ContentProjectMonthContext::toDateString($month);
        $units = [];

        foreach ($this->draftTaskRows($month, $monthDate, $siteId) as $row) {
            $taskId = (int) ($row->id ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            $units[$taskId] = [
                'in_draft' => true,
                'in_execution' => false,
                'keyword' => (string) ($row->keyword ?? ''),
                'title' => (string) ($row->title ?? ''),
                'article_id' => ((int) ($row->article_id ?? 0)) ?: null,
                'status' => (string) ($row->status ?? ''),
                'scheduled_publish_at' => $row->scheduled_publish_at ?? null,
                'publish_published_at' => $row->publish_published_at ?? null,
            ];
        }

        foreach ($this->executionTaskRows($monthDate, $siteId) as $row) {
            $taskId = (int) ($row->id ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            if (isset($units[$taskId])) {
                $units[$taskId]['in_execution'] = true;
                $units[$taskId]['in_draft'] = false;
            } else {
                $units[$taskId] = [
                    'in_draft' => false,
                    'in_execution' => true,
                    'keyword' => (string) ($row->keyword ?? ''),
                    'title' => (string) ($row->title ?? ''),
                    'article_id' => ((int) ($row->article_id ?? 0)) ?: null,
                    'status' => (string) ($row->status ?? ''),
                    'scheduled_publish_at' => $row->scheduled_publish_at ?? null,
                    'publish_published_at' => $row->publish_published_at ?? null,
                ];
            }
        }

        return $units;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function draftTaskRows(string $month, string $monthDate, ?int $siteId = null)
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_projects')) {
            return collect();
        }

        $query = DB::connection('omi_seo_ai')->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id');
        SitePlanningActiveUnitPredicate::constrainQuery($query);
        $query
            ->where('p.status', SeoProject::STATUS_DRAFT)
            ->select([
                't.id',
                't.site_id',
                't.keyword',
                't.title',
                't.article_id',
                't.status',
                't.archived_at',
                't.deleted_at',
                't.scheduled_publish_at',
                't.publish_published_at',
                't.planning_month',
                't.created_at',
                't.target_date',
                'p.month as project_month',
                'p.status as project_status',
                'p.archived_at as project_archived_at',
            ]);

        if ($siteId !== null && $siteId > 0) {
            $query->where('t.site_id', $siteId);
        } else {
            $query->whereNotNull('t.site_id')->where('t.site_id', '>', 0);
        }

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
            $query->where(function ($q) use ($monthDate, $month): void {
                $q->whereDate('t.planning_month', $monthDate)
                    ->orWhere(function ($inner) use ($month): void {
                        // Legacy rows without stamp: deterministic backfill from created_at/target_date.
                        $inner->whereNull('t.planning_month');
                    });
            });
        }

        $rows = $query->get();

        // Filter legacy null planning_month with deterministic backfill matching $month.
        return $rows->filter(function (object $row) use ($month): bool {
            if (! SitePlanningActiveUnitPredicate::acceptsRow($row)) {
                return false;
            }
            $resolved = PlanningMonthBackfill::resolve([
                'planning_month' => $row->planning_month ?? null,
                'created_at' => $row->created_at ?? null,
                'target_date' => $row->target_date ?? null,
                'project_month' => $row->project_month ?? null,
                'project_is_draft' => true,
            ]);

            return $resolved === $month;
        })->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function executionTaskRows(string $monthDate, ?int $siteId = null)
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_projects')) {
            return collect();
        }

        $month = ContentProjectMonthContext::normalize(
            substr($monthDate, 0, 7),
        );

        $query = DB::connection('omi_seo_ai')->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id');
        SitePlanningActiveUnitPredicate::constrainQuery($query);
        $query
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->select([
                't.id',
                't.site_id',
                't.keyword',
                't.title',
                't.article_id',
                't.status',
                't.archived_at',
                't.deleted_at',
                't.scheduled_publish_at',
                't.publish_published_at',
                't.planning_month',
                't.created_at',
                't.target_date',
                'p.month as project_month',
                'p.status as project_status',
                'p.archived_at as project_archived_at',
            ]);

        if ($siteId !== null && $siteId > 0) {
            $query->where('t.site_id', $siteId);
        } else {
            $query->whereNotNull('t.site_id')->where('t.site_id', '>', 0);
        }

        // Prefer task.planning_month (immutable planning SSOT). Fall back to project.month via resolver.
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
            $query->where(function ($q) use ($monthDate): void {
                $q->whereDate('t.planning_month', $monthDate)
                    ->orWhereNull('t.planning_month')
                    ->orWhereDate('p.month', $monthDate);
            });
        } else {
            $query->whereDate('p.month', $monthDate);
        }

        $rows = $query->get();

        return $rows->filter(function (object $row) use ($month): bool {
            if (! SitePlanningActiveUnitPredicate::acceptsRow($row)) {
                return false;
            }
            $resolved = PlanningMonthBackfill::resolve([
                'planning_month' => $row->planning_month ?? null,
                'created_at' => $row->created_at ?? null,
                'target_date' => $row->target_date ?? null,
                'project_month' => $row->project_month ?? null,
                'project_is_draft' => false,
            ]);

            return $resolved === $month;
        })->values();
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array<string, mixed>>
     */
    private function loadOrigins(array $taskIds): array
    {
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
        if ($taskIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_item_origins')) {
            return [];
        }

        $rows = SeoContentProjectItemOrigin::query()
            ->whereIn('project_task_id', $taskIds)
            ->get(['project_task_id', 'source_type', 'source_fingerprint', 'source_article_id']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->project_task_id] = [
                'source_type' => (string) ($row->source_type ?? ''),
                'source_fingerprint' => $row->source_fingerprint,
                'source_article_id' => ((int) ($row->source_article_id ?? 0)) ?: null,
            ];
        }

        return $out;
    }

    private function normalizeSourceType(string $raw): string
    {
        $type = strtolower(trim($raw));
        $known = [
            SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
            SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
            SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT,
            SeoContentProjectItemOrigin::SOURCE_MANUAL,
        ];

        return in_array($type, $known, true) ? $type : self::SOURCE_UNKNOWN;
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array<string, mixed>>
     */
    private function loadAttributions(array $taskIds): array
    {
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
        if ($taskIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_task_planning_attributions')) {
            return [];
        }

        $rows = SeoContentProjectTaskPlanningAttribution::query()
            ->whereIn('project_task_id', $taskIds)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->project_task_id] = [
                'cluster_ref' => $row->cluster_ref,
                'cluster_name_snapshot' => $row->cluster_name_snapshot,
                'dna_phrases' => is_array($row->dna_phrases) ? $row->dna_phrases : [],
                'attribution_status' => $row->attribution_status,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $unit
     */
    private function normalizeStatusBucket(array $unit): string
    {
        if (! empty($unit['publish_published_at'])) {
            return 'published';
        }
        if (! empty($unit['scheduled_publish_at'])) {
            return 'scheduled';
        }
        $articleId = (int) ($unit['article_id'] ?? 0);
        $status = strtolower(trim((string) ($unit['status'] ?? '')));
        if ($articleId > 0) {
            if (in_array($status, [
                SeoProjectTask::STATUS_WRITING,
                SeoProjectTask::STATUS_PROCESSING,
                SeoProjectTask::STATUS_REVIEWING,
            ], true)) {
                return 'editing';
            }

            return 'generated';
        }
        if ($status === SeoProjectTask::STATUS_DRAFT || $status === SeoProjectTask::STATUS_PENDING) {
            return 'draft';
        }

        return 'other';
    }

    /**
     * @return array<string, float>
     */
    private function actualMcpByCluster(int $siteId): array
    {
        $service = $this->mcpProfile ?? (app()->bound(SiteMcpTopicalProfileService::class)
            ? app(SiteMcpTopicalProfileService::class)
            : null);
        if (! $service instanceof SiteMcpTopicalProfileService) {
            return [];
        }

        try {
            $profile = $service->get($siteId);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach (is_array($profile['topics'] ?? null) ? $profile['topics'] : [] as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $ref = trim((string) ($topic['cluster_ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $out[$ref] = (float) ($topic['weight'] ?? 0);
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function actualDnaCountByCluster(int $siteId): array
    {
        $service = $this->mcpProfile ?? (app()->bound(SiteMcpTopicalProfileService::class)
            ? app(SiteMcpTopicalProfileService::class)
            : null);
        if (! $service instanceof SiteMcpTopicalProfileService) {
            return [];
        }

        try {
            $profile = $service->get($siteId);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach (is_array($profile['topics'] ?? null) ? $profile['topics'] : [] as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $ref = trim((string) ($topic['cluster_ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $dna = is_array($topic['dna'] ?? null) ? $topic['dna'] : [];
            $out[$ref] = count($dna);
        }

        return $out;
    }

    /**
     * @return array{
     *   planned: int,
     *   draft: int,
     *   execution: int,
     *   by_status: array<string, int>,
     *   clusters: list<array<string, mixed>>,
     *   unattributed: array{count: int, task_ids: list<int>},
     *   attributed: array{count: int, task_ids: list<int>},
     *   source_counts: array<string, int>,
     *   tasks: list<array<string, mixed>>
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'planned' => 0,
            'draft' => 0,
            'execution' => 0,
            'by_status' => [
                'draft' => 0,
                'generated' => 0,
                'editing' => 0,
                'scheduled' => 0,
                'published' => 0,
                'other' => 0,
            ],
            'clusters' => [],
            'unattributed' => ['count' => 0, 'task_ids' => []],
            'attributed' => ['count' => 0, 'task_ids' => []],
            'source_counts' => [],
            'tasks' => [],
        ];
    }
}
