<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Maps business-audit action keys → human-readable Dashboard activity copy.
 * Never expose raw command/event keys in UI.
 */
final class OperationalLandingDashboardActivityPresenter
{
    /**
     * @param  array{
     *     action?: string|null,
     *     result?: string|null,
     *     actor_type?: string|null,
     *     occurred_at?: string|null,
     *     project_ref?: string|null,
     *     item_ref?: string|null,
     * }  $row
     * @return array{time: string, message: string, context: string|null, tone: string}
     */
    public static function present(array $row): array
    {
        $action = self::normalizeAction((string) ($row['action'] ?? ''));
        $failed = strtolower(trim((string) ($row['result'] ?? ''))) === 'failed';
        $message = self::labelFor($action, $failed);

        $contextParts = [];
        $projectRef = trim((string) ($row['project_ref'] ?? ''));
        $itemRef = trim((string) ($row['item_ref'] ?? ''));
        if ($projectRef !== '') {
            $friendly = self::friendlyRef($projectRef);
            if ($friendly !== '') {
                $contextParts[] = $friendly;
            }
        }
        if ($itemRef !== '') {
            $friendly = self::friendlyRef($itemRef);
            if ($friendly !== '') {
                $contextParts[] = $friendly;
            }
        }

        return [
            'time' => self::formatTime($row['occurred_at'] ?? null),
            'message' => $message,
            'context' => $contextParts === [] ? null : implode(' · ', $contextParts),
            'tone' => $failed ? 'danger' : 'info',
        ];
    }

    public static function labelFor(string $action, bool $failed = false): string
    {
        $normalized = self::normalizeAction($action);
        $map = self::labels();

        $base = $map[$normalized] ?? null;
        if ($base === null) {
            $base = $failed
                ? 'Một thao tác vận hành không thành công'
                : 'Đã ghi nhận một thao tác vận hành';
        }

        if ($failed && isset($map[$normalized])) {
            return $base.' (không thành công)';
        }

        return $base;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'content_project.send_to_publishing_queue' => 'Đã chuyển bài sang hàng chờ xuất bản',
            'content_projects.send_to_publishing_queue' => 'Đã chuyển bài sang hàng chờ xuất bản',
            'content_project.return_to_content_project' => 'Đã trả bài về Content Project',
            'content_project.schedule' => 'Đã lên lịch xuất bản bài viết',
            'content_project.unschedule' => 'Đã hủy lịch xuất bản',
            'content_project.publish_now' => 'Đã xuất bản bài viết ngay',
            'content_project.cancel_publish' => 'Đã hủy xuất bản',
            'content_project.retry_publish' => 'Đã thử lại xuất bản',
            'content_project.recover_stuck_publishing' => 'Đã khôi phục hàng chờ xuất bản bị kẹt',
            'content_project.generate' => 'AI đã tạo nội dung bài viết',
            'content_project.rerun' => 'Đã chạy lại tạo nội dung',
            'content_project.archive' => 'Đã lưu trữ dự án',
            'content_project.archive_items' => 'Đã lưu trữ item dự án',
            'content_project.restore' => 'Đã khôi phục dự án',
            'content_project.auto_schedule' => 'Đã tự động lên lịch xuất bản',
            'content_project.start_review' => 'Đã bắt đầu review bài viết',
            'content_project.approve' => 'Đã duyệt bài viết',
            'send_to_publishing_queue' => 'Đã chuyển bài sang hàng chờ xuất bản',
            'schedule' => 'Đã lên lịch xuất bản bài viết',
            'publish_now' => 'Đã xuất bản bài viết ngay',
            'archive' => 'Đã lưu trữ dự án',
            'restore' => 'Đã khôi phục dự án',
        ];
    }

    public static function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));
        if ($action === '') {
            return '';
        }

        // Strip accidental prefixes like "content_projects." vs "content_project."
        return $action;
    }

    private static function formatTime(mixed $occurredAt): string
    {
        if (! is_string($occurredAt) || trim($occurredAt) === '') {
            return '—';
        }

        try {
            return \Carbon\Carbon::parse($occurredAt)->format('H:i');
        } catch (\Throwable) {
            return '—';
        }
    }

    private static function friendlyRef(string $ref): string
    {
        if (preg_match('/^project:(\d+)$/', $ref, $m) === 1) {
            return 'Dự án #'.$m[1];
        }
        if (preg_match('/^item:(\d+)$/', $ref, $m) === 1) {
            return 'Item #'.$m[1];
        }

        // Never echo raw technical-looking keys with dots/underscores as primary copy.
        if (str_contains($ref, '.') || str_contains($ref, '_')) {
            return '';
        }

        return $ref;
    }
}
