<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Progress;

use App\Core\Operations\LongRunningProgress;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use App\Support\RuntimeLogger;

/**
 * Throttled, monotonic progress writes onto seo_site_sync_runs.meta.task_progress.
 */
final class SiteSyncProgressTracker
{
    public const META_KEY = 'task_progress';

    /**
     * @param  array<string, mixed>  $patch
     */
    public function checkpoint(SeoSiteSyncRun $run, array $patch, bool $force = false): bool
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $previous = is_array($meta[self::META_KEY] ?? null)
            ? LongRunningProgress::fromArray($meta[self::META_KEY])
            : null;

        $base = $previous ?? LongRunningProgress::fromArray([
            'status' => (string) $run->status,
            'started_at' => optional($run->started_at)?->toIso8601String(),
        ]);
        $next = $base->merge($patch);
        if (! $force && ! $next->shouldPersist($previous)) {
            return false;
        }

        $meta[self::META_KEY] = $next->toArray();
        $meta['last_progress_at'] = $next->lastActivityAt;
        $run->meta = $meta;
        $run->save();

        RuntimeLogger::warning('site_sync.progress', [
            'run_id' => (int) $run->id,
            'step' => $next->step,
            'total_steps' => $next->totalSteps,
            'phase' => $next->phase,
            'current' => $next->current,
            'total' => $next->total,
            'batch' => $next->batch,
            'batch_total' => $next->batchTotal,
            'duration' => $next->batchDurationSeconds,
            'status' => $next->status,
        ]);

        return true;
    }

    public function read(SeoSiteSyncRun $run): LongRunningProgress
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        if (is_array($meta[self::META_KEY] ?? null)) {
            return LongRunningProgress::fromArray($meta[self::META_KEY]);
        }

        return LongRunningProgress::fromArray([
            'status' => (string) $run->status,
            'phase' => (string) ($run->current_step ?? ''),
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'last_activity_at' => (string) ($meta['last_progress_at'] ?? ''),
            'finished_at' => optional($run->finished_at)?->toIso8601String(),
        ]);
    }
}
