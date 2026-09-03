<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Illuminate\Support\Collection;

/**
 * Project-scoped planner run history (SEO Audit / AI New Content).
 * Rejection memory stays on seo_content_project_suggestion_decisions.
 */
final class ContentProjectPlannerRunService
{
    /**
     * @param  array<string, mixed>  $configurationSnapshot
     * @param  array<string, mixed>  $resultSummary
     */
    public function recordExecuted(
        SeoProject $project,
        string $sourceType,
        int $requestedQuantity,
        array $configurationSnapshot,
        array $resultSummary,
        ?int $actorId = null,
        ?int $promptResultId = null,
        ?string $executionRef = null,
    ): SeoContentProjectPlannerRun {
        $summary = $resultSummary;
        $summary['kind'] = SeoContentProjectPlannerRun::KIND_EXECUTED;
        if (! isset($summary['status'])) {
            $summary['status'] = SeoContentProjectPlannerRun::STATUS_COMPLETED;
        }

        return SeoContentProjectPlannerRun::query()->create([
            'project_id' => (int) $project->getKey(),
            'site_id' => $this->resolveRunSiteId($project, $configurationSnapshot),
            'source_type' => $sourceType,
            'requested_quantity' => max(0, $requestedQuantity),
            'configuration_snapshot' => $configurationSnapshot,
            'result_summary' => $summary,
            'prompt_result_id' => $promptResultId,
            'execution_ref' => $executionRef,
            'created_by' => $actorId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $configurationSnapshot
     */
    public function recordQueued(
        SeoProject $project,
        string $sourceType,
        int $requestedQuantity,
        array $configurationSnapshot,
        ?int $actorId = null,
    ): SeoContentProjectPlannerRun {
        return SeoContentProjectPlannerRun::query()->create([
            'project_id' => (int) $project->getKey(),
            'site_id' => $this->resolveRunSiteId($project, $configurationSnapshot),
            'source_type' => $sourceType,
            'requested_quantity' => max(0, $requestedQuantity),
            'configuration_snapshot' => $configurationSnapshot,
            'result_summary' => [
                'kind' => SeoContentProjectPlannerRun::KIND_EXECUTED,
                'status' => SeoContentProjectPlannerRun::STATUS_QUEUED,
                'requested' => max(0, $requestedQuantity),
                'added' => 0,
            ],
            'created_by' => $actorId,
        ]);
    }

    public function markStatus(SeoContentProjectPlannerRun $run, string $status): SeoContentProjectPlannerRun
    {
        $summary = is_array($run->result_summary) ? $run->result_summary : [];
        $summary['kind'] = SeoContentProjectPlannerRun::KIND_EXECUTED;
        $summary['status'] = $status;
        $run->result_summary = $summary;
        $run->save();

        return $run;
    }

    /**
     * Aggregate user-visible progress for one planner run (no batch mechanics).
     *
     * @param  array<string, mixed>  $extra
     */
    public function markProgress(SeoContentProjectPlannerRun $run, int $added, int $requested, array $extra = []): SeoContentProjectPlannerRun
    {
        $summary = is_array($run->result_summary) ? $run->result_summary : [];
        $summary['kind'] = SeoContentProjectPlannerRun::KIND_EXECUTED;
        $summary['status'] = SeoContentProjectPlannerRun::STATUS_RUNNING;
        $summary['added'] = max(0, $added);
        $summary['requested'] = max(0, $requested);
        $summary['message'] = $summary['added'].' / '.$summary['requested'];
        foreach ($extra as $key => $value) {
            if (is_string($key) && $key !== '') {
                $summary[$key] = $value;
            }
        }
        $run->result_summary = $summary;
        $run->save();

        return $run;
    }

    /**
     * @param  array<string, mixed>  $resultSummary
     */
    public function completeRun(
        SeoContentProjectPlannerRun $run,
        array $resultSummary,
        ?int $promptResultId = null,
    ): SeoContentProjectPlannerRun {
        $summary = $resultSummary;
        $summary['kind'] = SeoContentProjectPlannerRun::KIND_EXECUTED;

        $added = max(0, (int) ($summary['added'] ?? 0));
        $requested = max(0, (int) ($summary['requested'] ?? $run->requested_quantity ?? 0));
        $incoming = (string) ($summary['status'] ?? '');

        // Contract: completed IFF accepted >= requested. Never force completed on shortfall.
        // Continuing / recovering / waiting_retry are non-terminal — do not coerce to partial.
        if (in_array($incoming, [
            SeoContentProjectPlannerRun::STATUS_RECOVERING,
            SeoContentProjectPlannerRun::STATUS_WAITING_RETRY,
            SeoContentProjectPlannerRun::STATUS_RUNNING,
            SeoContentProjectPlannerRun::STATUS_QUEUED,
        ], true) || ! empty($summary['needs_continuation'])) {
            $summary['status'] = $incoming !== ''
                ? $incoming
                : SeoContentProjectPlannerRun::STATUS_RECOVERING;
            $summary['remaining'] = max(0, $requested - $added);
            $summary['completion_kind'] = (string) ($summary['completion_kind'] ?? 'continuing');
            $run->result_summary = $summary;
            if ($promptResultId !== null && $promptResultId > 0) {
                $run->prompt_result_id = $promptResultId;
            }
            $run->save();

            return $run;
        }

        if ($requested > 0 && $added >= $requested) {
            $summary['status'] = SeoContentProjectPlannerRun::STATUS_COMPLETED;
            $summary['remaining'] = 0;
            $summary['completion_kind'] = 'full';
        } elseif ($incoming === SeoContentProjectPlannerRun::STATUS_PARTIAL
            || ($added > 0 && $added < $requested)
        ) {
            $summary['status'] = SeoContentProjectPlannerRun::STATUS_PARTIAL;
            $summary['remaining'] = max(0, $requested - $added);
            $summary['completion_kind'] = 'partial';
        } elseif ($incoming === SeoContentProjectPlannerRun::STATUS_FAILED || $added === 0) {
            $summary['status'] = SeoContentProjectPlannerRun::STATUS_FAILED;
            $summary['remaining'] = max(0, $requested - $added);
            $summary['completion_kind'] = (string) ($summary['completion_kind'] ?? 'failed');
        } else {
            $summary['status'] = SeoContentProjectPlannerRun::STATUS_COMPLETED;
            $summary['remaining'] = 0;
            $summary['completion_kind'] = 'full';
        }

        $run->result_summary = $summary;
        if ($promptResultId !== null && $promptResultId > 0) {
            $run->prompt_result_id = $promptResultId;
        }
        $run->save();

        return $run;
    }

    /**
     * @param  array<string, mixed>  $resultSummary
     */
    public function failRun(
        SeoContentProjectPlannerRun $run,
        array $resultSummary,
        ?int $promptResultId = null,
    ): SeoContentProjectPlannerRun {
        $summary = $resultSummary;
        $summary['kind'] = SeoContentProjectPlannerRun::KIND_EXECUTED;
        $summary['status'] = SeoContentProjectPlannerRun::STATUS_FAILED;
        $run->result_summary = $summary;
        if ($promptResultId !== null && $promptResultId > 0) {
            $run->prompt_result_id = $promptResultId;
        }
        $run->save();

        return $run;
    }

    public function findActive(SeoProject $project, string $sourceType): ?SeoContentProjectPlannerRun
    {
        $runs = SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', $sourceType)
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        foreach ($runs as $run) {
            if (! $run instanceof SeoContentProjectPlannerRun) {
                continue;
            }
            $status = (string) (($run->result_summary ?? [])['status'] ?? '');
            if (in_array($status, SeoContentProjectPlannerRun::activeStatuses(), true)) {
                return $run;
            }
        }

        return null;
    }

    /**
     * Persist filter/options config without executing (Save filters / Save options).
     *
     * @param  array<string, mixed>  $configurationSnapshot
     */
    public function recordSavedConfig(
        SeoProject $project,
        string $sourceType,
        array $configurationSnapshot,
        ?int $actorId = null,
    ): SeoContentProjectPlannerRun {
        return SeoContentProjectPlannerRun::query()->create([
            'project_id' => (int) $project->getKey(),
            'site_id' => $this->resolveRunSiteId($project, $configurationSnapshot),
            'source_type' => $sourceType,
            'requested_quantity' => 0,
            'configuration_snapshot' => $configurationSnapshot,
            'result_summary' => ['kind' => SeoContentProjectPlannerRun::KIND_SAVED_CONFIG],
            'created_by' => $actorId,
        ]);
    }

    /**
     * Prefer snapshot.site_id (AI New Content working site); legacy fallback project.site_id.
     *
     * @param  array<string, mixed>  $configurationSnapshot
     */
    private function resolveRunSiteId(SeoProject $project, array $configurationSnapshot): ?int
    {
        $fromSnapshot = (int) ($configurationSnapshot['site_id'] ?? 0);
        if ($fromSnapshot > 0) {
            return $fromSnapshot;
        }

        $fromProject = (int) ($project->site_id ?? 0);

        return $fromProject > 0 ? $fromProject : null;
    }

    /**
     * @return Collection<int, SeoContentProjectPlannerRun>
     */
    public function listExecuted(SeoProject $project, string $sourceType, int $limit = 30, ?int $siteId = null): Collection
    {
        $query = SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', $sourceType)
            ->orderByDesc('id');

        // Pull a wider window when site-scoping — KIND_SAVED_CONFIG rows may interleave.
        $fetchLimit = $siteId !== null && $siteId > 0
            ? max(40, $limit * 4)
            : max(1, $limit);

        return $query
            ->limit($fetchLimit)
            ->get()
            ->filter(static function (SeoContentProjectPlannerRun $run): bool {
                $kind = (string) (($run->result_summary ?? [])['kind'] ?? SeoContentProjectPlannerRun::KIND_EXECUTED);

                return $kind === SeoContentProjectPlannerRun::KIND_EXECUTED;
            })
            ->filter(static function (SeoContentProjectPlannerRun $run) use ($siteId): bool {
                if ($siteId === null || $siteId <= 0) {
                    return true;
                }
                $runSite = (int) ($run->site_id ?? 0);
                if ($runSite <= 0) {
                    $snap = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
                    $runSite = (int) ($snap['site_id'] ?? 0);
                }

                return $runSite === $siteId;
            })
            ->take(max(1, $limit))
            ->values();
    }

    public function findForProject(SeoProject $project, int $runId): ?SeoContentProjectPlannerRun
    {
        if ($runId <= 0) {
            return null;
        }

        $run = SeoContentProjectPlannerRun::query()
            ->whereKey($runId)
            ->where('project_id', (int) $project->getKey())
            ->first();

        return $run instanceof SeoContentProjectPlannerRun ? $run : null;
    }

    /**
     * Latest saved or executed configuration for a source (for Draft defaults).
     *
     * @return array<string, mixed>|null
     */
    public function latestConfigurationSnapshot(SeoProject $project, string $sourceType): ?array
    {
        $run = SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', $sourceType)
            ->orderByDesc('id')
            ->first();

        if (! $run instanceof SeoContentProjectPlannerRun) {
            return null;
        }

        $snapshot = $run->configuration_snapshot;

        return is_array($snapshot) ? $snapshot : null;
    }
}
