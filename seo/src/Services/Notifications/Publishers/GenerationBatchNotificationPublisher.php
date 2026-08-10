<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications\Publishers;

use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;

final class GenerationBatchNotificationPublisher
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
        private readonly OperationalNotificationDeepLinks $links,
    ) {}

    public function notifyRunCompleted(SeoProject $project, SeoProjectRun $run, ?int $initiatorUserId = null): void
    {
        $succeeded = (int) ($run->succeeded ?? 0);
        $failed = (int) ($run->failed ?? 0);
        $total = (int) ($run->total ?? ($succeeded + $failed));
        $skipped = max(0, $total - $succeeded - $failed);

        $batchId = (int) $run->getKey();
        $tenantId = $this->recipients->tenantOwnerIdForProject($project);
        $dedup = sprintf('generation-batch:%d:%d:%d', $tenantId, (int) $project->getKey(), $batchId);

        if ($failed <= 0) {
            // 100% success: no Notification Center spam (toast/UI already shows result).
            return;
        }

        $allFailed = $succeeded <= 0 && $failed > 0;
        $event = $allFailed
            ? OperationalNotificationEventCode::GenerationBatchFailed
            : OperationalNotificationEventCode::GenerationBatchPartialFailed;
        $severity = $allFailed ? NotificationSeverity::Danger : NotificationSeverity::Warning;

        $failedUrl = $this->links->contentProjectFailed((int) $project->getKey());
        $opsUrl = $this->links->operationsCenter($batchId);
        $projectUrl = $this->links->contentProject((int) $project->getKey());

        $this->notifications->notify(
            eventCode: $event,
            severity: $severity,
            recipients: $this->recipients->forGenerationBatch($project, $initiatorUserId),
            title: sprintf('Hoàn tất tạo nội dung %s', (string) $project->name),
            message: sprintf('%d thành công · %d thất bại · %d đã bỏ qua', $succeeded, $failed, $skipped),
            context: [
                'tenant_id' => $tenantId,
                'project_id' => (int) $project->getKey(),
                'run_id' => $batchId,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'skipped' => $skipped,
                'source' => 'generation_batch_complete',
            ],
            actionUrl: $failedUrl,
            actions: [
                ['label' => 'Xem item thất bại', 'url' => $failedUrl, 'name' => 'open_failed'],
                ['label' => 'Mở Operation', 'url' => $opsUrl, 'name' => 'open_operation'],
                ['label' => 'Mở Content Project', 'url' => $projectUrl, 'name' => 'open_project'],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );
    }
}
