<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

use Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncStepJob;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRunStep;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * RunSiteSync orchestrator — creates run + step rows, dispatches first step job.
 * Each step is a separate job/capability (never one mega-job).
 */
final class RunSiteSyncOrchestrator
{
    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
    ) {}

    private function stepRunner(): SiteSyncStepRunner
    {
        return app(SiteSyncStepRunner::class);
    }

    /**
     * @param  array{mode?: string, trigger_source?: string, triggered_by?: int|null, force_snapshot?: bool, force_full?: bool, supersede_active?: bool, steps?: list<string>|null, sync?: bool, meta?: array<string, mixed>}  $options
     * @return array{success: bool, message: string, run_id?: int, public_ref?: string}
     */
    public function start(Site $site, array $options = []): array
    {
        if (! $this->flags->orchestratorEnabled()) {
            return [
                'success' => false,
                'message' => 'Site Sync V2 orchestrator disabled (feature flag).',
            ];
        }

        $forceFull = (bool) ($options['force_full'] ?? false)
            || (string) ($options['mode'] ?? '') === SiteSyncSchema::MODE_FORCE_FULL;

        $mode = match (true) {
            $forceFull => SiteSyncSchema::MODE_FORCE_FULL,
            (bool) ($options['force_snapshot'] ?? false) => SiteSyncSchema::MODE_SNAPSHOT,
            default => (string) ($options['mode'] ?? SiteSyncSchema::MODE_DELTA),
        };

        if (! in_array($mode, [
            SiteSyncSchema::MODE_SNAPSHOT,
            SiteSyncSchema::MODE_DELTA,
            SiteSyncSchema::MODE_FORCE_FULL,
        ], true)) {
            $mode = SiteSyncSchema::MODE_DELTA;
        }

        $active = SeoSiteSyncRun::query()
            ->where('site_id', (int) $site->id)
            ->whereIn('status', ['pending', 'running'])
            ->first();

        if ($active !== null) {
            if ($forceFull && ($options['supersede_active'] ?? true)) {
                $this->cancel((int) $active->id);
                $active->refresh();
                $meta = is_array($active->meta) ? $active->meta : [];
                $meta['superseded_by_force_full'] = true;
                $active->forceFill([
                    'meta' => $meta,
                    'error_message' => 'Superseded by force_full run',
                ])->save();
            } else {
                ProcessSiteSyncStepJob::dispatch((int) $active->id);

                return [
                    'success' => true,
                    'message' => 'Sync đang chạy — đã kiểm tra lại queue.',
                    'run_id' => (int) $active->id,
                    'public_ref' => (string) $active->public_ref,
                ];
            }
        }

        $steps = $options['steps'] ?? SiteSyncSchema::ORCHESTRATOR_STEPS;
        if (! is_array($steps) || $steps === []) {
            $steps = SiteSyncSchema::ORCHESTRATOR_STEPS;
        }

        $runMeta = array_merge(
            [
                'requested_steps' => array_values($steps),
                'include_unchanged' => $forceFull,
                'force_full' => $forceFull,
            ],
            is_array($options['meta'] ?? null) ? $options['meta'] : [],
        );

        $run = SeoSiteSyncRun::query()->create([
            'site_id' => (int) $site->id,
            'public_ref' => 'ssr_'.Str::lower(Str::random(16)),
            'mode' => $mode,
            'status' => 'pending',
            'current_step' => $steps[0],
            'cursor' => null,
            'run_token' => Str::uuid()->toString(),
            'resumable' => true,
            'triggered_by' => $options['triggered_by'] ?? null,
            'trigger_source' => (string) ($options['trigger_source'] ?? 'ui'),
            'counters' => [
                'fetched' => 0,
                'checked' => 0,
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'failed' => 0,
                'stale' => 0,
                'total_to_check' => 0,
                'urls_synced' => 0,
                'provider_keywords' => 0,
                'scores' => 0,
            ],
            'warnings' => [],
            'meta' => $runMeta,
            'started_at' => now(),
        ]);

        foreach (array_values($steps) as $order => $stepKey) {
            SeoSiteSyncRunStep::query()->create([
                'run_id' => (int) $run->id,
                'step_key' => (string) $stepKey,
                'step_order' => $order,
                'status' => 'pending',
            ]);
        }

        $sync = (bool) ($options['sync'] ?? false);
        if ($sync) {
            $this->stepRunner()->runNext((int) $run->id);

            return [
                'success' => true,
                'message' => $forceFull
                    ? 'Force full site sync completed (sync mode).'
                    : 'Site sync completed (sync mode).',
                'run_id' => (int) $run->id,
                'public_ref' => (string) $run->public_ref,
            ];
        }

        ProcessSiteSyncStepJob::dispatch((int) $run->id);

        return [
            'success' => true,
            'message' => $forceFull
                ? 'Đã xếp hàng Đồng bộ lại toàn bộ website.'
                : 'Đã xếp hàng Đồng bộ & kiểm tra website.',
            'run_id' => (int) $run->id,
            'public_ref' => (string) $run->public_ref,
        ];
    }

    public function resume(int $runId): array
    {
        $run = SeoSiteSyncRun::query()->find($runId);
        if ($run === null) {
            return ['success' => false, 'message' => 'Run not found.'];
        }

        if ($run->status === 'canceled') {
            return ['success' => false, 'message' => 'Run canceled — cannot resume.'];
        }

        if (! $run->resumable && $run->status === 'failed') {
            $run->forceFill(['resumable' => true, 'status' => 'pending'])->save();
        }

        ProcessSiteSyncStepJob::dispatch((int) $run->id);

        return [
            'success' => true,
            'message' => 'Resuming site sync.',
            'run_id' => (int) $run->id,
            'public_ref' => (string) $run->public_ref,
        ];
    }

    public function retryStep(int $runId, string $stepKey): array
    {
        $run = SeoSiteSyncRun::query()->find($runId);
        if ($run === null) {
            return ['success' => false, 'message' => 'Run not found.'];
        }
        $step = SeoSiteSyncRunStep::query()
            ->where('run_id', $runId)
            ->where('step_key', $stepKey)
            ->first();
        if ($step === null) {
            return ['success' => false, 'message' => 'Step not found.'];
        }
        $step->forceFill([
            'status' => 'pending',
            'error_message' => null,
            'finished_at' => null,
            'attempt_count' => (int) ($step->attempt_count ?? 0) + 1,
        ])->save();
        $run->forceFill([
            'status' => 'pending',
            'current_step' => $stepKey,
            'resumable' => true,
            'error_message' => null,
        ])->save();
        ProcessSiteSyncStepJob::dispatch($runId);

        return [
            'success' => true,
            'message' => 'Step queued for retry.',
            'run_id' => $runId,
            'public_ref' => (string) $run->public_ref,
        ];
    }

    public function cancel(int $runId): array
    {
        $run = SeoSiteSyncRun::query()->find($runId);
        if ($run === null) {
            return ['success' => false, 'message' => 'Run not found.'];
        }
        if (in_array($run->status, ['completed', 'canceled'], true)) {
            return [
                'success' => true,
                'message' => 'Run already finished.',
                'run_id' => $runId,
                'public_ref' => (string) $run->public_ref,
            ];
        }

        SeoSiteSyncRunStep::query()
            ->where('run_id', $runId)
            ->where('status', 'pending')
            ->update(['status' => 'skipped']);

        $run->forceFill([
            'status' => 'canceled',
            'resumable' => false,
            'finished_at' => now(),
            'error_message' => 'Canceled by operator',
        ])->save();

        return [
            'success' => true,
            'message' => 'Run canceled. Already reconciled data kept.',
            'run_id' => $runId,
            'public_ref' => (string) $run->public_ref,
        ];
    }
}
