<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Shared progress % semantics for Content Project Run + Global Monthly Batch.
 *
 * Skipped items are excluded from the processing denominator.
 * Failed counts as processed but never as succeeded.
 */
final class ContentProjectProgressPercent
{
    /**
     * @return array{
     *     snapshot_total: int,
     *     skipped: int,
     *     succeeded: int,
     *     failed: int,
     *     eligible_total: int,
     *     processed: int,
     *     percent: int,
     *     no_eligible_work: bool
     * }
     */
    public static function compute(
        int $snapshotTotal,
        int $succeeded,
        int $failed,
        int $skipped,
        bool $runTerminal = false,
    ): array {
        $snapshotTotal = max(0, $snapshotTotal);
        $succeeded = max(0, $succeeded);
        $failed = max(0, $failed);
        $skipped = max(0, min($skipped, $snapshotTotal));

        $eligibleTotal = max(0, $snapshotTotal - $skipped);
        $processed = $succeeded + $failed;
        if ($eligibleTotal > 0) {
            $processed = min($processed, $eligibleTotal);
        }

        $noEligibleWork = $eligibleTotal === 0 && ($runTerminal || $snapshotTotal === 0);
        $percent = $eligibleTotal > 0
            ? (int) min(100, (int) floor(($processed / $eligibleTotal) * 100))
            : 0;

        return [
            'snapshot_total' => $snapshotTotal,
            'skipped' => $skipped,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'eligible_total' => $eligibleTotal,
            'processed' => $processed,
            'percent' => $percent,
            'no_eligible_work' => $noEligibleWork,
        ];
    }
}
