<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications\Publishers;

use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;
use Illuminate\Support\Collection;

/**
 * Facade for Publishing Queue auto-retry patch — reuse canonical service, do not fork.
 */
final class PublishingOperationalNotificationPublisher
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
        private readonly OperationalNotificationDeepLinks $links,
    ) {}

    /**
     * @param  Collection<int, \App\Models\User>|iterable<\App\Models\User>|null  $recipients
     */
    public function notify(
        OperationalNotificationEventCode $eventCode,
        NotificationSeverity $severity,
        int $tenantOwnerId,
        string $dedupKey,
        string $title,
        string $message,
        array $context = [],
        ?int $projectId = null,
        ?string $queueStatus = null,
        iterable $recipients = [],
    ): void {
        $users = collect($recipients);
        if ($users->isEmpty()) {
            $users = $this->recipients->forPublishing($tenantOwnerId, isset($context['initiator_user_id']) ? (int) $context['initiator_user_id'] : null);
        }

        $url = $this->links->publishingQueue($projectId, $queueStatus);

        $this->notifications->notify(
            eventCode: $eventCode,
            severity: $severity,
            recipients: $users,
            title: $title,
            message: $message,
            context: $context + [
                'tenant_id' => $tenantOwnerId,
                'project_id' => $projectId,
                'module' => 'publishing',
            ],
            actionUrl: $url,
            actions: [
                ['label' => 'Mở Publishing Queue', 'url' => $url, 'name' => 'open_queue'],
                ['label' => 'Mở Operation', 'url' => $this->links->operationsCenter(), 'name' => 'open_ops'],
            ],
            dedupKey: $dedupKey,
            groupKey: $dedupKey,
            resolvable: true,
        );
    }

    public function resolve(string $dedupKey): void
    {
        $this->notifications->resolve($dedupKey);
    }
}
