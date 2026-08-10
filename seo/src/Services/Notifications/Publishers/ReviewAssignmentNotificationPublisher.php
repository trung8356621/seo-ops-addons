<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications\Publishers;

use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;

final class ReviewAssignmentNotificationPublisher
{
    private const BUCKET_MINUTES = 10;

    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
        private readonly OperationalNotificationDeepLinks $links,
    ) {}

    public function notifyItemsAssigned(
        SeoProject $project,
        int $reviewerId,
        int $itemCount = 1,
        ?int $actorUserId = null,
    ): void {
        if ($itemCount <= 0) {
            return;
        }

        // Avoid notify when actor is the same reviewer (self-handoff / self-open).
        if ($actorUserId !== null && $actorUserId > 0 && $actorUserId === $reviewerId) {
            return;
        }

        $tenantId = $this->recipients->tenantOwnerIdForProject($project);
        $bucket = (int) floor(now()->timestamp / (self::BUCKET_MINUTES * 60));
        $dedup = sprintf(
            'review-assignment:%d:%d:%d:%d',
            $tenantId,
            (int) $project->getKey(),
            $reviewerId,
            $bucket,
        );

        $url = $this->links->contentProjectNeedsReview((int) $project->getKey());

        $this->notifications->notify(
            eventCode: OperationalNotificationEventCode::ReviewItemsAssigned,
            severity: NotificationSeverity::Info,
            recipients: $this->recipients->forReviewAssignment($reviewerId, $tenantId),
            title: sprintf('%d bài mới đang chờ bạn duyệt', $itemCount),
            message: sprintf('Project %s', (string) $project->name),
            context: [
                'tenant_id' => $tenantId,
                'project_id' => (int) $project->getKey(),
                'reviewer_id' => $reviewerId,
                'affected_item_count' => $itemCount,
                'source' => 'review_assignment',
            ],
            actionUrl: $url,
            actions: [
                ['label' => 'Mở Needs Review', 'url' => $url, 'name' => 'open_needs_review'],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: false,
        );
    }
}
