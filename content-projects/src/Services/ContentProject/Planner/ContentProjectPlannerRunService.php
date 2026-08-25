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
            'site_id' => (int) ($project->site_id ?? 0) ?: null,
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
            'site_id' => (int) ($project->site_id ?? 0) ?: null,
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
     * @param  array<string, mixed>  $resultSummary
     */
    public function completeRun(
        SeoContentProjectPlannerRun $run,
        array $resultSummary,
        ?int $promptResultId = null,
    ): SeoContentProjectPlannerRun {
        $summary = $resultSummary;
        $summary['kind'] = SeoContentProjectPlannerRun::KIND_EXECUTED;
        $summary['status'] = SeoContentProjectPlannerRun::STATUS_COMPLETED;
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
            if (in_array($status, [
                SeoContentProjectPlannerRun::STATUS_QUEUED,
                SeoContentProjectPlannerRun::STATUS_RUNNING,
            ], true)) {
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
            'site_id' => (int) ($project->site_id ?? 0) ?: null,
            'source_type' => $sourceType,
            'requested_quantity' => 0,
            'configuration_snapshot' => $configurationSnapshot,
            'result_summary' => ['kind' => SeoContentProjectPlannerRun::KIND_SAVED_CONFIG],
            'created_by' => $actorId,
        ]);
    }

    /**
     * @return Collection<int, SeoContentProjectPlannerRun>
     */
    public function listExecuted(SeoProject $project, string $sourceType, int $limit = 30): Collection
    {
        return SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', $sourceType)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->filter(static function (SeoContentProjectPlannerRun $run): bool {
                $kind = (string) (($run->result_summary ?? [])['kind'] ?? SeoContentProjectPlannerRun::KIND_EXECUTED);

                return $kind === SeoContentProjectPlannerRun::KIND_EXECUTED;
            })
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
