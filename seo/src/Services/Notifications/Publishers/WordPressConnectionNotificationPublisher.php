<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications\Publishers;

use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;

final class WordPressConnectionNotificationPublisher
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
        private readonly OperationalNotificationDeepLinks $links,
    ) {}

    public function notifyConnectionFailed(
        int $tenantOwnerId,
        int $connectionId,
        string $errorCode,
        string $siteLabel,
        string $message,
        bool $permanent = true,
        int $retryAttempt = 0,
        ?int $domainId = null,
    ): void {
        if (! $permanent && $retryAttempt < 1) {
            return;
        }

        $code = strtoupper($errorCode);
        $dedup = sprintf('wordpress-connection:%d:%d:%s', $tenantOwnerId, $connectionId, $this->slug($code));
        $domainUrl = $domainId !== null && $domainId > 0
            ? $this->links->domainConnection($domainId)
            : $this->links->operationsCenter();

        $this->notifications->notify(
            eventCode: OperationalNotificationEventCode::WordpressConnectionFailed,
            severity: NotificationSeverity::Critical,
            recipients: $this->recipients->forWordPressConnection($tenantOwnerId),
            title: sprintf('Không thể xác thực website %s', $siteLabel !== '' ? $siteLabel : '#'.$connectionId),
            message: $this->sanitizeMessage($message),
            context: [
                'tenant_id' => $tenantOwnerId,
                'connection_id' => $connectionId,
                'domain_id' => $domainId,
                'error_code' => $code,
                'source' => 'wordpress_connection',
            ],
            actionUrl: $domainUrl,
            actions: [
                ['label' => 'Kiểm tra kết nối', 'url' => $domainUrl, 'name' => 'check_connection'],
                ['label' => 'Mở Operation', 'url' => $this->links->operationsCenter(), 'name' => 'open_ops'],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );
    }

    public function notifyCapabilityMissing(
        int $tenantOwnerId,
        int $connectionId,
        string $capabilityName,
        string $message,
        ?int $domainId = null,
    ): void {
        $dedup = sprintf('wordpress-capability:%d:%s', $connectionId, $this->slug($capabilityName));
        $domainUrl = $domainId !== null && $domainId > 0
            ? $this->links->domainConnection($domainId)
            : $this->links->operationsCenter();

        $this->notifications->notify(
            eventCode: OperationalNotificationEventCode::WordpressCapabilityMissing,
            severity: NotificationSeverity::Danger,
            recipients: $this->recipients->forWordPressConnection($tenantOwnerId),
            title: 'WordPress plugin thiếu capability',
            message: $this->sanitizeMessage($message !== '' ? $message : 'Phiên bản hiện tại chưa hỗ trợ '.$capabilityName.'.'),
            context: [
                'tenant_id' => $tenantOwnerId,
                'connection_id' => $connectionId,
                'domain_id' => $domainId,
                'capability_name' => $capabilityName,
                'source' => 'wordpress_capability',
            ],
            actionUrl: $domainUrl,
            actions: [
                ['label' => 'Kiểm tra kết nối', 'url' => $domainUrl, 'name' => 'check_connection'],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );
    }

    public function notifyCallbackRejected(
        int $tenantOwnerId,
        int $connectionId,
        string $message,
        ?int $domainId = null,
    ): void {
        $dedup = sprintf('wordpress-connection:%d:%d:callback_rejected', $tenantOwnerId, $connectionId);
        $this->notifications->notify(
            eventCode: OperationalNotificationEventCode::WordpressCallbackRejected,
            severity: NotificationSeverity::Danger,
            recipients: $this->recipients->forWordPressConnection($tenantOwnerId),
            title: 'WordPress callback bị từ chối',
            message: $this->sanitizeMessage($message),
            context: [
                'tenant_id' => $tenantOwnerId,
                'connection_id' => $connectionId,
                'domain_id' => $domainId,
                'error_code' => 'callback_rejected',
                'source' => 'wordpress_callback',
            ],
            actionUrl: $this->links->operationsCenter(),
            actions: [
                ['label' => 'Mở Operation', 'url' => $this->links->operationsCenter(), 'name' => 'open_ops'],
            ],
            dedupKey: $dedup,
            resolvable: true,
        );
    }

    public function resolveConnection(int $tenantOwnerId, int $connectionId, string $errorCode, string $siteLabel = ''): void
    {
        $dedup = sprintf('wordpress-connection:%d:%d:%s', $tenantOwnerId, $connectionId, $this->slug($errorCode));
        $this->notifications->resolve(
            dedupKey: $dedup,
            recoveryTitle: 'Kết nối WordPress đã khôi phục',
            recoveryMessage: sprintf('Website %s đã hoạt động trở lại.', $siteLabel !== '' ? $siteLabel : '#'.$connectionId),
            recoveryEventCode: OperationalNotificationEventCode::WordpressConnectionRecovered,
            recoveryRecipients: $this->recipients->forWordPressConnection($tenantOwnerId),
            emitRecovery: true,
            recoveryContext: [
                'tenant_id' => $tenantOwnerId,
                'connection_id' => $connectionId,
                'source' => 'wordpress_recovered',
            ],
        );
    }

    public function resolveCapability(int $connectionId, string $capabilityName): void
    {
        $dedup = sprintf('wordpress-capability:%d:%s', $connectionId, $this->slug($capabilityName));
        $this->notifications->resolve($dedup);
    }

    private function sanitizeMessage(string $message): string
    {
        $clean = preg_replace('/Bearer\s+\S+/i', '[redacted]', $message) ?? $message;
        $clean = preg_replace('/(token|signature|secret|password)\s*[:=]\s*\S+/i', '$1=[redacted]', $clean) ?? $clean;

        return mb_substr(trim($clean), 0, 500);
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? 'unknown');

        return $slug !== '' ? $slug : 'unknown';
    }
}
