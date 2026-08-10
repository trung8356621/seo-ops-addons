<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications\Publishers;

use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;

final class GenerationStuckNotificationPublisher
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
        private readonly OperationalNotificationDeepLinks $links,
    ) {}

    /**
     * @param  list<int>  $recoveredTaskIds
     * @param  list<int>  $manualTaskIds
     */
    public function notifyRecoveryBatch(
        SeoProject $project,
        string $recoveryBatchId,
        array $recoveredTaskIds,
        array $manualTaskIds = [],
        bool $exhausted = false,
    ): void {
        $stuckCount = count($recoveredTaskIds) + count($manualTaskIds);
        if ($stuckCount <= 0) {
            return;
        }

        $tenantId = $this->recipients->tenantOwnerIdForProject($project);
        $dedup = sprintf(
            'generation-stuck:%d:%d:%s',
            $tenantId,
            (int) $project->getKey(),
            $recoveryBatchId,
        );

        $recovered = count($recoveredTaskIds);
        $manual = count($manualTaskIds);
        $failedUrl = $this->links->contentProjectFailed((int) $project->getKey());

        if ($exhausted || $manual > 0) {
            $this->notifications->notify(
                eventCode: $exhausted
                    ? OperationalNotificationEventCode::GenerationRetryExhausted
                    : OperationalNotificationEventCode::GenerationStuck,
                severity: $exhausted || $manual > 0 ? NotificationSeverity::Danger : NotificationSeverity::Warning,
                recipients: $this->recipients->forGenerationBatch($project),
                title: sprintf('%d bài tạo nội dung bị gián đoạn', $stuckCount),
                message: $manual > 0
                    ? sprintf('Đã khôi phục %d/%d bài. %d bài cần xử lý thủ công.', $recovered, $stuckCount, $manual)
                    : 'Hệ thống đang tự khôi phục các execution bị kẹt.',
                context: [
                    'tenant_id' => $tenantId,
                    'project_id' => (int) $project->getKey(),
                    'recovery_batch_id' => $recoveryBatchId,
                    'recovered_count' => $recovered,
                    'manual_count' => $manual,
                    'source' => 'generation_stuck_recovery',
                ],
                actionUrl: $failedUrl,
                actions: [
                    ['label' => 'Xem item thất bại', 'url' => $failedUrl, 'name' => 'open_failed'],
                    ['label' => 'Mở Content Project', 'url' => $this->links->contentProject((int) $project->getKey()), 'name' => 'open_project'],
                ],
                dedupKey: $dedup,
                groupKey: $dedup,
                resolvable: true,
            );

            return;
        }

        $this->notifications->notify(
            eventCode: OperationalNotificationEventCode::GenerationStuck,
            severity: NotificationSeverity::Warning,
            recipients: $this->recipients->forGenerationBatch($project),
            title: sprintf('%d bài tạo nội dung bị gián đoạn', $stuckCount),
            message: 'Hệ thống đang tự khôi phục các execution bị kẹt.',
            context: [
                'tenant_id' => $tenantId,
                'project_id' => (int) $project->getKey(),
                'recovery_batch_id' => $recoveryBatchId,
                'recovered_count' => $recovered,
                'source' => 'generation_stuck',
            ],
            actionUrl: $failedUrl,
            actions: [
                ['label' => 'Xem item thất bại', 'url' => $failedUrl, 'name' => 'open_failed'],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );

        if ($recovered > 0 && $manual === 0) {
            $this->notifications->resolve(
                dedupKey: $dedup,
                recoveryTitle: 'Đã khôi phục generation bị kẹt',
                recoveryMessage: sprintf('Đã khôi phục %d/%d bài.', $recovered, $stuckCount),
                recoveryEventCode: OperationalNotificationEventCode::GenerationRecovered,
                recoveryRecipients: $this->recipients->forGenerationBatch($project),
                emitRecovery: true,
                recoveryContext: [
                    'tenant_id' => $tenantId,
                    'project_id' => (int) $project->getKey(),
                    'source' => 'generation_recovered',
                ],
                recoveryActionUrl: $this->links->contentProject((int) $project->getKey()),
            );
        }
    }
}
