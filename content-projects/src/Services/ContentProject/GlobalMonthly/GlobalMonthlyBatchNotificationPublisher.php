<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use App\Models\User;
use App\Support\RuntimeLogger;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * One terminal notification per Global Monthly batch outcome (deduped).
 */
final class GlobalMonthlyBatchNotificationPublisher
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
    ) {}

    public function notifyBatchTerminal(SeoContentGlobalBatch $batch): void
    {
        $batchId = (int) $batch->getKey();
        $status = (string) $batch->status;
        $settings = is_array($batch->settings) ? $batch->settings : [];
        $breaker = is_array($settings[GlobalBatchCircuitBreakerState::SETTINGS_KEY] ?? null)
            ? $settings[GlobalBatchCircuitBreakerState::SETTINGS_KEY]
            : [];

        $succeeded = (int) ($batch->succeeded ?? 0);
        $failed = (int) ($batch->failed ?? 0);
        $skipped = (int) ($batch->skipped ?? 0);
        $month = $batch->planning_month?->format('Y-m') ?? 'unknown';

        $dedup = sprintf('generation-global-batch:%d:%s', $batchId, $status);
        $event = match ($status) {
            SeoContentGlobalBatch::STATUS_COMPLETED => OperationalNotificationEventCode::GenerationBatchPartialFailed,
            SeoContentGlobalBatch::STATUS_STOPPED_CIRCUIT_BREAKER => OperationalNotificationEventCode::GenerationBatchFailed,
            SeoContentGlobalBatch::STATUS_FAILED => OperationalNotificationEventCode::GenerationBatchFailed,
            SeoContentGlobalBatch::STATUS_STOPPED_AI_UNAVAILABLE => OperationalNotificationEventCode::GenerationBatchPartialFailed,
            SeoContentGlobalBatch::STATUS_CANCELLED => OperationalNotificationEventCode::GenerationBatchPartialFailed,
            default => OperationalNotificationEventCode::GenerationBatchPartialFailed,
        };
        $severity = in_array($status, [
            SeoContentGlobalBatch::STATUS_STOPPED_CIRCUIT_BREAKER,
            SeoContentGlobalBatch::STATUS_FAILED,
        ], true) ? NotificationSeverity::Danger : NotificationSeverity::Warning;

        if ($status === SeoContentGlobalBatch::STATUS_COMPLETED && $failed <= 0) {
            return;
        }

        $title = match ($status) {
            SeoContentGlobalBatch::STATUS_STOPPED_CIRCUIT_BREAKER => sprintf(
                'Global Generate %s dừng — circuit breaker',
                $month,
            ),
            SeoContentGlobalBatch::STATUS_COMPLETED => sprintf('Global Generate %s hoàn tất', $month),
            SeoContentGlobalBatch::STATUS_CANCELLED => sprintf('Global Generate %s đã dừng', $month),
            SeoContentGlobalBatch::STATUS_STOPPED_AI_UNAVAILABLE => sprintf(
                'Global Generate %s dừng — AI unavailable',
                $month,
            ),
            default => sprintf('Global Generate %s kết thúc (%s)', $month, $status),
        };

        $reason = (string) ($breaker['reason'] ?? $settings['stop_reason'] ?? $settings['failure_message'] ?? '');
        $message = trim(sprintf(
            '%d thành công · %d thất bại · %d bỏ qua%s',
            $succeeded,
            $failed,
            $skipped,
            $reason !== '' ? ' — '.$reason : '',
        ));

        $recipients = collect();
        $ownerId = (int) ($batch->user_id ?? 0);
        if ($ownerId <= 0) {
            $ownerId = (int) (SeoAccessControl::accountOwnerId() ?? 0);
        }
        if ($ownerId > 0) {
            $user = User::query()->find($ownerId);
            if ($user instanceof User) {
                $recipients = collect([$user]);
            }
        }

        if ($recipients->isEmpty()) {
            RuntimeLogger::info('global_monthly_batch.terminal_notification_skipped_no_recipients', [
                'batch_id' => $batchId,
                'notification_key' => $dedup,
            ]);

            return;
        }

        $this->notifications->notify(
            eventCode: $event,
            severity: $severity,
            recipients: $recipients,
            title: $title,
            message: $message,
            context: [
                'batch_id' => $batchId,
                'status' => $status,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'skipped' => $skipped,
                'source' => 'global_monthly_batch_terminal',
                'circuit_breaker' => $breaker,
            ],
            actionUrl: '/seo/global-monthly-generator?month='.urlencode($month),
            actions: [
                [
                    'label' => 'Mở Global Generator',
                    'url' => '/seo/global-monthly-generator?month='.urlencode($month),
                    'name' => 'open_global_generator',
                ],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );

        RuntimeLogger::info('global_monthly_batch.terminal_notification_sent', [
            'batch_id' => $batchId,
            'notification_key' => $dedup,
            'status' => $status,
        ]);
    }
}
