<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

use Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncStepJob;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use App\Support\RuntimeLogger;

/**
 * Cooperative cancellation + execution generation for chunked Site Sync jobs.
 */
final class SiteSyncRunExecution
{
    public const META_GENERATION = 'execution_generation';

    public const META_CANCELED_AT = 'canceled_at';

    public function initialGeneration(): int
    {
        return 1;
    }

    public function freshRun(int $runId): ?SeoSiteSyncRun
    {
        return SeoSiteSyncRun::query()->find($runId);
    }

    public function readGeneration(SeoSiteSyncRun $run): int
    {
        $meta = is_array($run->meta) ? $run->meta : [];

        return max(1, (int) ($meta[self::META_GENERATION] ?? 1));
    }

    public function isCanceled(?SeoSiteSyncRun $run): bool
    {
        if ($run === null) {
            return true;
        }

        return in_array((string) $run->status, ['canceled', 'cancelled'], true);
    }

    /**
     * Job entry guard: stale generation or canceled run → no-op.
     */
    public function shouldSkipJob(int $runId, int $jobGeneration): bool
    {
        $run = $this->freshRun($runId);
        if ($run === null || $this->isCanceled($run)) {
            return true;
        }

        if ($jobGeneration <= 0) {
            return false;
        }

        return $this->readGeneration($run) !== $jobGeneration;
    }

    /**
     * Fresh DB guard before dispatching continuation.
     */
    public function canDispatchContinuation(int $runId, int $fromGeneration): bool
    {
        $run = $this->freshRun($runId);
        if ($run === null || $this->isCanceled($run)) {
            return false;
        }

        if ((string) $run->status !== 'running') {
            return false;
        }

        if ($fromGeneration > 0 && $this->readGeneration($run) !== $fromGeneration) {
            return false;
        }

        return true;
    }

    /**
     * Invalidate queued continuations and stamp cancel time (no extra DB column).
     *
     * @return int new generation after bump
     */
    public function stampCancel(SeoSiteSyncRun $run): int
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $next = $this->readGeneration($run) + 1;
        $meta[self::META_GENERATION] = $next;
        $meta[self::META_CANCELED_AT] = now()->toIso8601String();
        $run->meta = $meta;

        return $next;
    }

    public function dispatchContinuation(
        int $runId,
        int $fromGeneration,
        ?int $delaySeconds = null,
        ?string $step = null,
        ?string $subphase = null,
    ): bool {
        if (! $this->canDispatchContinuation($runId, $fromGeneration)) {
            $run = $this->freshRun($runId);
            RuntimeLogger::warning('site_sync.continuation_skipped_canceled', [
                'run_id' => $runId,
                'step' => $step,
                'subphase' => $subphase,
                'job_generation' => $fromGeneration,
                'run_generation' => $run !== null ? $this->readGeneration($run) : null,
                'run_status' => $run !== null ? (string) $run->status : null,
                'cursor' => $run?->cursor,
            ]);

            return false;
        }

        $pending = ProcessSiteSyncStepJob::dispatch($runId, $fromGeneration);
        if ($delaySeconds !== null && $delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
        $pending->afterCommit();

        return true;
    }

    /**
     * Mid-chunk cooperative stop check (reload from DB).
     */
    public function isRunStopped(int $runId): bool
    {
        return $this->isCanceled($this->freshRun($runId));
    }

    /**
     * @return array{__canceled_stop: true}
     */
    public function chunkStoppedPayload(
        int $runId,
        string $step,
        string $subphase,
        ?string $cursor = null,
    ): array {
        $run = $this->freshRun($runId);
        RuntimeLogger::warning('site_sync.chunk_stopped_canceled', [
            'run_id' => $runId,
            'step' => $step,
            'subphase' => $subphase,
            'cursor' => $cursor ?? $run?->cursor,
            'run_generation' => $run !== null ? $this->readGeneration($run) : null,
        ]);

        return ['__canceled_stop' => true];
    }
}
