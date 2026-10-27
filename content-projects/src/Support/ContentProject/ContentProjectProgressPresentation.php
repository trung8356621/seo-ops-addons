<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Shared presentation contract for Content Project Progress & Global Monthly Generator Progress.
 *
 * Terminal runs:
 * - Lifecycle Progress = 100%
 * - Prominent Status Heading (approx 18-20px font, semibold/bold):
 *   - Success: "ĐÃ HOÀN TẤT" (green)
 *   - Completed with errors: "ĐÃ KẾT THÚC — CÓ LỖI" (amber/red)
 *   - Cancelled / Stopped: "ĐÃ DỪNG" (neutral/warning)
 *   - Failed: "TIẾN TRÌNH THẤT BẠI" (red)
 * - Counters: always preserve real numbers (succeeded, failed, skipped, pending "chưa thực hiện", total snapshot).
 * - Never label as "30/30 bài đã xử lý".
 *
 * Active runs:
 * - Actual progress percentage.
 * - Keep Now Playing.
 * - Do not show 100% prematurely.
 */
final class ContentProjectProgressPresentation
{
    public const STATE_ACTIVE = 'active';
    public const STATE_SUCCESS = 'success';
    public const STATE_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_FAILED = 'failed';
    public const STATE_IDLE = 'idle';

    public const HEADING_SUCCESS = 'ĐÃ HOÀN TẤT';
    public const HEADING_COMPLETED_WITH_ERRORS = 'ĐÃ KẾT THÚC — CÓ LỖI';
    public const HEADING_CANCELLED = 'ĐÃ DỪNG';
    public const HEADING_FAILED = 'TIẾN TRÌNH THẤT BẠI';

    /**
     * @return array{
     *     is_terminal: bool,
     *     state: string,
     *     status_heading: ?string,
     *     status_heading_class: string,
     *     progress_bar_class: string,
     *     lifecycle_percent: int,
     *     raw_percent: int,
     *     display_percent: int,
     *     summary_text: string,
     *     pending_label: string,
     *     headline_left: string,
     *     headline_right: string,
     * }
     */
    public static function present(
        bool $hasRun,
        ?string $runStatus,
        int $snapshotTotal,
        int $succeeded,
        int $failed,
        int $skipped,
        int $pending,
        int $processing = 0,
        int $cancelled = 0,
        bool $isTerminal = false,
    ): array {
        $snapshotTotal = max(0, $snapshotTotal);
        $succeeded = max(0, $succeeded);
        $failed = max(0, $failed);
        $skipped = max(0, min($skipped, $snapshotTotal));
        $pending = max(0, $pending);
        $processing = max(0, $processing);
        $cancelled = max(0, $cancelled);

        $statusStr = strtolower(trim((string) $runStatus));

        $terminalStatuses = ['completed', 'completed_with_errors', 'failed', 'cancelled', 'stopped'];
        $resolvedTerminal = $hasRun && ($isTerminal || in_array($statusStr, $terminalStatuses, true));

        $math = ContentProjectProgressPercent::compute(
            $snapshotTotal,
            $succeeded,
            $failed,
            $skipped,
            $resolvedTerminal,
        );

        if (! $hasRun) {
            return [
                'is_terminal' => false,
                'state' => self::STATE_IDLE,
                'status_heading' => null,
                'status_heading_class' => '',
                'progress_bar_class' => 'bg-primary-600',
                'lifecycle_percent' => 0,
                'raw_percent' => 0,
                'display_percent' => 0,
                'summary_text' => '',
                'pending_label' => 'Chờ xử lý',
                'headline_left' => 'Chưa có tiến trình nào',
                'headline_right' => '0%',
            ];
        }

        if (! $resolvedTerminal) {
            $rawPercent = (int) ($math['raw_percent'] ?? $math['percent']);
            $eligibleTotal = (int) $math['eligible_total'];
            $processed = (int) $math['processed'];

            $headlineLeft = $math['no_eligible_work']
                ? 'Không có bài eligible'
                : "{$processed}/{$eligibleTotal} bài đã xử lý";

            return [
                'is_terminal' => false,
                'state' => self::STATE_ACTIVE,
                'status_heading' => null,
                'status_heading_class' => '',
                'progress_bar_class' => 'bg-primary-600',
                'lifecycle_percent' => $rawPercent,
                'raw_percent' => $rawPercent,
                'display_percent' => $rawPercent,
                'summary_text' => self::buildSummaryText($succeeded, $failed, $skipped, $pending, $processing, $cancelled, false),
                'pending_label' => 'Chờ xử lý',
                'headline_left' => $headlineLeft,
                'headline_right' => "{$rawPercent}%",
            ];
        }

        // Terminal states resolution
        if (in_array($statusStr, ['cancelled', 'stopped'], true)) {
            $state = self::STATE_CANCELLED;
            $heading = self::HEADING_CANCELLED;
            $headingClass = 'text-amber-700 dark:text-amber-400';
            $barClass = 'bg-amber-500 dark:bg-amber-600';
        } elseif ($statusStr === 'failed') {
            $state = self::STATE_FAILED;
            $heading = self::HEADING_FAILED;
            $headingClass = 'text-danger-600 dark:text-danger-400';
            $barClass = 'bg-danger-600 dark:bg-danger-500';
        } elseif ($statusStr === 'completed_with_errors' || $failed > 0 || $pending > 0) {
            $state = self::STATE_COMPLETED_WITH_ERRORS;
            $heading = self::HEADING_COMPLETED_WITH_ERRORS;
            $headingClass = 'text-amber-600 dark:text-amber-400';
            $barClass = 'bg-amber-500 dark:bg-amber-600';
        } else {
            $state = self::STATE_SUCCESS;
            $heading = self::HEADING_SUCCESS;
            $headingClass = 'text-success-600 dark:text-success-400';
            $barClass = 'bg-success-600 dark:bg-success-500';
        }

        $summaryText = self::buildSummaryText($succeeded, $failed, $skipped, $pending, 0, $cancelled, true);

        return [
            'is_terminal' => true,
            'state' => $state,
            'status_heading' => $heading,
            'status_heading_class' => $headingClass,
            'progress_bar_class' => $barClass,
            'lifecycle_percent' => 100,
            'raw_percent' => (int) ($math['raw_percent'] ?? 0),
            'display_percent' => 100,
            'summary_text' => $summaryText,
            'pending_label' => 'chưa thực hiện',
            'headline_left' => $heading,
            'headline_right' => 'Lifecycle Progress: 100%',
        ];
    }

    private static function buildSummaryText(
        int $succeeded,
        int $failed,
        int $skipped,
        int $pending,
        int $processing,
        int $cancelled,
        bool $isTerminal,
    ): string {
        $parts = [];
        $parts[] = "{$succeeded} thành công";
        $parts[] = "{$failed} thất bại";
        $parts[] = "{$skipped} bỏ qua";

        if ($isTerminal) {
            $parts[] = "{$pending} chưa thực hiện";
            if ($cancelled > 0) {
                $parts[] = "{$cancelled} đã dừng";
            }
        } else {
            if ($processing > 0) {
                $parts[] = "{$processing} đang chạy";
            }
            $parts[] = "{$pending} chờ xử lý";
            if ($cancelled > 0) {
                $parts[] = "{$cancelled} đã hủy";
            }
        }

        return implode(' · ', $parts);
    }
}
