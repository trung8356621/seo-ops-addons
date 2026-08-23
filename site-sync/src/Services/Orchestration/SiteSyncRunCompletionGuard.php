<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncBatch;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;

/**
 * Validates force_full runs are fully exhausted before terminal status.
 */
final class SiteSyncRunCompletionGuard
{
    /**
     * @return array{ok: bool, reason: ?string}
     */
    public static function validate(SeoSiteSyncRun $run): array
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $forceFull = (string) $run->mode === SiteSyncSchema::MODE_FORCE_FULL
            || (bool) ($meta['force_full'] ?? false);

        if (! $forceFull) {
            return ['ok' => true, 'reason' => null];
        }

        if ((bool) ($meta['has_more_batches'] ?? false)) {
            return ['ok' => false, 'reason' => 'snapshot_has_more=true'];
        }

        $counters = is_array($run->counters) ? $run->counters : [];
        if (! (bool) ($meta['snapshot_exhausted'] ?? false)) {
            $total = (int) ($counters['total_to_check'] ?? 0);
            $fetched = (int) ($counters['fetched'] ?? 0);
            if ($total > 0 && $fetched < $total) {
                return ['ok' => false, 'reason' => "fetched {$fetched}/{$total}"];
            }
        }

        $batchIds = is_array($meta['batch_ids'] ?? null) ? $meta['batch_ids'] : [];
        $pending = 0;
        foreach ($batchIds as $batchId) {
            $batch = SeoSiteSyncBatch::query()->find((int) $batchId);
            if ($batch !== null && $batch->applied_at === null) {
                $pending++;
            }
        }
        if ($pending > 0) {
            return ['ok' => false, 'reason' => "pending_staging_batches={$pending}"];
        }

        $total = (int) ($counters['total_to_check'] ?? 0);
        $reconciled = (int) ($counters['reconciled'] ?? $counters['articles'] ?? 0);
        if ($total > 0 && $reconciled > 0 && $reconciled < $total) {
            return ['ok' => false, 'reason' => "reconciled {$reconciled}/{$total}"];
        }

        return ['ok' => true, 'reason' => null];
    }
}
