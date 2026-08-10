<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application;

use Filament\Notifications\Notification;

/**
 * Filament map ContentProjectActionResult → Notification.
 * Publishing Queue: success toast ON for bulk publish/retry/schedule/recover summaries.
 */
final class ContentProjectActionResultNotifier
{
    public function send(ContentProjectActionResult $result, bool $allowSuccessToast = false): void
    {
        $isBulkSummary = $this->isBulkSummary($result);
        if ($result->success && ! $allowSuccessToast && ! $isBulkSummary) {
            return;
        }

        $message = $this->mapBusinessMessage($result);
        $notification = Notification::make()
            ->title($this->mapTitle($result))
            ->body($message);

        if ($result->success) {
            $notification->success();
        } elseif ($result->code === ContentProjectActionCodes::CONFIRMATION_REQUIRED
            || $result->code === ContentProjectActionCodes::PREVIEW_READY
        ) {
            $notification->warning();
        } else {
            $notification->danger();
        }

        $notification->send();
    }

    private function isBulkSummary(ContentProjectActionResult $result): bool
    {
        return in_array($result->code, [
            ContentProjectActionCodes::ITEMS_PUBLISH_RETRIED,
            ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED,
            ContentProjectActionCodes::ITEMS_PUBLISH_RECOVERED,
            ContentProjectActionCodes::ITEMS_SCHEDULED,
        ], true)
            || str_contains($result->message, 'Đã đổi lịch')
            || str_contains($result->message, 'Đã thử lại')
            || str_contains($result->message, 'Publish now:')
            || str_contains($result->message, 'Đã khôi phục')
            || str_contains($result->message, 'Không có bài nào cần khôi phục');
    }

    private function mapTitle(ContentProjectActionResult $result): string
    {
        if (str_contains($result->message, 'Đã đổi lịch')
            || isset($result->metadata['scheduled'], $result->metadata['skipped_active'])
        ) {
            return 'Đổi lịch';
        }
        if (str_contains($result->message, 'lifecycle.invalid_transition: processing')
            || str_contains($result->message, 'publishing.busy_cannot_reschedule')
        ) {
            return 'Không thể đổi lịch';
        }
        if (str_contains($result->message, 'stale_processing')
            || str_contains($result->code, 'recover')
            || $result->code === ContentProjectActionCodes::ITEMS_PUBLISH_RECOVERED
        ) {
            return 'Khôi phục Publishing';
        }
        if ($result->code === ContentProjectActionCodes::ITEMS_PUBLISH_RETRIED) {
            return 'Thử lại xuất bản';
        }
        if ($result->code === ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED) {
            return 'Xuất bản ngay';
        }

        return $result->code;
    }

    private function mapBusinessMessage(ContentProjectActionResult $result): string
    {
        $message = $result->message;

        if (isset($result->metadata['scheduled'], $result->metadata['skipped_active'])
            && ! str_contains($message, 'Đã đổi lịch')
        ) {
            return sprintf(
                'Đã đổi lịch %d bài. Bỏ qua %d bài đang xuất bản.',
                (int) $result->metadata['scheduled'],
                (int) $result->metadata['skipped_active'],
            );
        }

        if (str_contains($message, 'lifecycle.invalid_transition: processing → cancelled')
            || str_contains($message, 'lifecycle.invalid_transition: processing')
        ) {
            return 'Bài đang được xuất bản nên không thể đổi lịch.';
        }
        if (str_contains($message, 'publishing.busy_cannot_reschedule')) {
            return 'Bài đang được xuất bản nên không thể đổi lịch.';
        }
        if (str_contains($message, 'stale_processing')) {
            return 'Tiến trình xuất bản đã quá hạn. Hãy khôi phục trạng thái trước.';
        }
        if (str_contains($message, 'Không có bài chưa lên lịch phù hợp')) {
            return 'Không có bài chưa lên lịch phù hợp';
        }

        if ($result->code === ContentProjectActionCodes::ITEMS_PUBLISH_RECOVERED
            || str_contains($result->message, 'Không có bài nào cần khôi phục')
            || str_contains($result->message, 'Không có bài phù hợp để khôi phục')
        ) {
            if ((int) ($result->metadata['recovered'] ?? count($result->affectedItemIds)) === 0
                && ! str_contains($message, 'Đã khôi phục')
            ) {
                return 'Không có bài phù hợp để khôi phục.';
            }
        }

        if ($result->code === ContentProjectActionCodes::ITEMS_PUBLISH_RETRIED
            && isset($result->metadata['retried'], $result->metadata['skipped'])
        ) {
            return \Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueItemActionsPresenter::bulkSummary(
                'Đã thử lại',
                [
                    'succeeded' => (int) $result->metadata['retried'],
                    'skipped' => (int) $result->metadata['skipped'],
                    'failed' => (int) ($result->metadata['failed'] ?? 0),
                ],
            );
        }

        if ($result->code === ContentProjectActionCodes::ITEMS_SCHEDULED
            && isset($result->metadata['scheduled'])
            && ! str_contains($message, 'Đã đổi lịch')
            && ! str_contains($message, 'Đã lên lịch')
        ) {
            $skipped = (int) ($result->metadata['skipped_active'] ?? $result->metadata['skipped'] ?? 0);
            if ($skipped > 0) {
                return sprintf(
                    'Đã đổi lịch %d bài. Bỏ qua %d bài đang xuất bản.',
                    (int) $result->metadata['scheduled'],
                    $skipped,
                );
            }

            return sprintf('Đã lên lịch %d bài.', (int) $result->metadata['scheduled']);
        }

        if (preg_match('/^publishing\.[a-z_]+:\s*(.+)$/u', $message, $m) === 1) {
            return $m[1];
        }

        return $message;
    }
}
