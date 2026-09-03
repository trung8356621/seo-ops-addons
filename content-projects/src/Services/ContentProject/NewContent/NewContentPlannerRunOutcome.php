<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;

/**
 * Terminal + continuing status contract for AI New Content planner runs.
 *
 * completed IFF acceptedUniqueCount >= requestedQty.
 * Partial only after bounded automatic recovery is exhausted.
 */
final class NewContentPlannerRunOutcome
{
    public const STOP_TARGET_MET = 'target_met';

    /** @deprecated Recovery signal — prefer NewContentAutoContinuationPolicy::RECOVERY_REASON_NO_PROGRESS */
    public const STOP_CONSECUTIVE_NO_PROGRESS = 'consecutive_no_progress';

    public const STOP_PROVIDER_BATCH_FAILED = 'provider_batch_failed';

    public const STOP_MAX_ROUNDS = 'max_rounds_reached';

    public const STOP_EMPTY_BATCHES = 'empty_batch_plan';

    public const STOP_AUTO_RECOVERY_EXHAUSTED = 'auto_recovery_exhausted';

    public const MAX_CONSECUTIVE_NO_PROGRESS = 2;

    /**
     * @return array{
     *   status: string,
     *   remaining: int,
     *   completion_kind: string,
     *   stop_reason: string|null,
     *   message: string,
     *   user_message: string,
     *   needs_continuation: bool
     * }
     */
    public static function resolve(
        int $added,
        int $requested,
        ?string $stopReason,
        int $duplicateSkipped = 0,
        int $rejectedSkipped = 0,
        int $invalid = 0,
    ): array {
        $requested = max(0, $requested);
        $added = max(0, $added);
        $remaining = max(0, $requested - $added);

        if ($requested > 0 && $added >= $requested) {
            $message = sprintf(
                '%d / %d · %d duplicates skipped · %d rejected skipped · %d invalid',
                $added,
                $requested,
                max(0, $duplicateSkipped),
                max(0, $rejectedSkipped),
                max(0, $invalid),
            );

            return [
                'status' => SeoContentProjectPlannerRun::STATUS_COMPLETED,
                'remaining' => 0,
                'completion_kind' => 'full',
                'stop_reason' => self::STOP_TARGET_MET,
                'message' => $message,
                'user_message' => sprintf('%d / %d ý tưởng', $added, $requested),
                'needs_continuation' => false,
            ];
        }

        if ($added > 0) {
            $reason = $stopReason !== null && $stopReason !== ''
                ? $stopReason
                : self::STOP_AUTO_RECOVERY_EXHAUSTED;

            return [
                'status' => SeoContentProjectPlannerRun::STATUS_PARTIAL,
                'remaining' => $remaining,
                'completion_kind' => 'partial',
                'stop_reason' => $reason,
                'message' => sprintf(
                    'Chưa hoàn tất %d/%d · còn %d · %d duplicates skipped',
                    $added,
                    $requested,
                    $remaining,
                    max(0, $duplicateSkipped),
                ),
                'user_message' => sprintf(
                    'Chưa hoàn tất %d/%d · còn %d',
                    $added,
                    $requested,
                    $remaining,
                ),
                'needs_continuation' => false,
            ];
        }

        $reason = $stopReason !== null && $stopReason !== ''
            ? $stopReason
            : self::STOP_PROVIDER_BATCH_FAILED;

        return [
            'status' => SeoContentProjectPlannerRun::STATUS_FAILED,
            'remaining' => $remaining,
            'completion_kind' => 'failed',
            'stop_reason' => $reason,
            'message' => sprintf('Tạo thất bại · 0/%d', $requested),
            'user_message' => sprintf('Tạo thất bại · 0/%d', $requested),
            'needs_continuation' => false,
        ];
    }

    /**
     * Non-terminal: automatic continuation still owns the remaining work.
     *
     * @return array{
     *   status: string,
     *   remaining: int,
     *   completion_kind: string,
     *   stop_reason: null,
     *   recovery_reason: string,
     *   message: string,
     *   user_message: string,
     *   needs_continuation: bool
     * }
     */
    public static function continuing(
        int $added,
        int $requested,
        string $phase,
        string $recoveryReason,
        int $duplicateSkipped = 0,
    ): array {
        $requested = max(0, $requested);
        $added = max(0, $added);
        $remaining = max(0, $requested - $added);
        $status = $phase === NewContentAutoContinuationPolicy::PHASE_WAITING_RETRY
            ? SeoContentProjectPlannerRun::STATUS_WAITING_RETRY
            : SeoContentProjectPlannerRun::STATUS_RECOVERING;

        $userMessage = match ($recoveryReason) {
            NewContentAutoContinuationPolicy::RECOVERY_REASON_NO_PROGRESS => sprintf(
                'Đang tránh ý tưởng trùng · %d/%d · còn %d',
                $added,
                $requested,
                $remaining,
            ),
            NewContentAutoContinuationPolicy::RECOVERY_REASON_TRUNCATED => sprintf(
                'Đang tự động tạo tiếp · %d/%d · còn %d',
                $added,
                $requested,
                $remaining,
            ),
            NewContentAutoContinuationPolicy::RECOVERY_REASON_PROVIDER_TRANSIENT => sprintf(
                'Sẽ tự động thử lại · %d/%d · còn %d',
                $added,
                $requested,
                $remaining,
            ),
            default => sprintf(
                'Đang tự động tạo tiếp · %d/%d · còn %d',
                $added,
                $requested,
                $remaining,
            ),
        };

        return [
            'status' => $status,
            'remaining' => $remaining,
            'completion_kind' => 'continuing',
            'stop_reason' => null,
            'recovery_reason' => $recoveryReason,
            'message' => $userMessage.(
                $duplicateSkipped > 0 ? sprintf(' · %d duplicates skipped', $duplicateSkipped) : ''
            ),
            'user_message' => $userMessage,
            'needs_continuation' => true,
        ];
    }

    public static function isSuccessfulTerminal(string $status): bool
    {
        return $status === SeoContentProjectPlannerRun::STATUS_COMPLETED;
    }

    public static function preservesPartialItems(string $status): bool
    {
        return in_array($status, [
            SeoContentProjectPlannerRun::STATUS_COMPLETED,
            SeoContentProjectPlannerRun::STATUS_PARTIAL,
            SeoContentProjectPlannerRun::STATUS_RECOVERING,
            SeoContentProjectPlannerRun::STATUS_WAITING_RETRY,
            SeoContentProjectPlannerRun::STATUS_RUNNING,
        ], true);
    }
}
