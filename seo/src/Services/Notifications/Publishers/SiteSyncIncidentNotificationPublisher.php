<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications\Publishers;

use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;

final class SiteSyncIncidentNotificationPublisher
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
        private readonly OperationalNotificationDeepLinks $links,
    ) {}

    public function notifyPartialFailure(
        SeoSiteSyncRun $run,
        int $tenantOwnerId,
        int $synced,
        int $total,
        int $failed,
        ?int $initiatorUserId = null,
        ?string $siteLabel = null,
    ): void {
        if ($failed <= 0) {
            return;
        }

        $connectionId = (int) ($run->site_id ?? 0);
        $runId = (int) $run->getKey();
        $dedup = sprintf('site-sync-run:%d:%d:%d', $tenantOwnerId, $connectionId, $runId);
        $url = $this->links->siteSyncRun($runId);

        $this->notifications->notify(
            eventCode: OperationalNotificationEventCode::SiteSyncPartialFailed,
            severity: NotificationSeverity::Warning,
            recipients: $this->recipients->forSiteSync($tenantOwnerId, $initiatorUserId),
            title: 'Site Sync hoàn tất nhưng có lỗi',
            message: sprintf('%d/%d nội dung được đồng bộ · %d item cần kiểm tra.', $synced, $total, $failed),
            context: [
                'tenant_id' => $tenantOwnerId,
                'connection_id' => $connectionId,
                'run_id' => $runId,
                'site_label' => $siteLabel,
                'source' => 'site_sync_partial',
            ],
            actionUrl: $url,
            actions: [
                ['label' => 'Mở Sync Run', 'url' => $url, 'name' => 'open_run'],
                ['label' => 'Mở Operation', 'url' => $this->links->operationsCenter(), 'name' => 'open_ops'],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );
    }

    public function notifyStuck(
        SeoSiteSyncRun $run,
        int $tenantOwnerId,
        string $stepName,
        int $minutesWithoutCallback = 15,
        ?int $initiatorUserId = null,
    ): void {
        $connectionId = (int) ($run->site_id ?? 0);
        $dedup = sprintf('site-sync-stuck:%d:%d:%s', $tenantOwnerId, $connectionId, $this->slug($stepName));
        $url = $this->links->siteSyncRun((int) $run->getKey());

        $this->notifications->notify(
            eventCode: OperationalNotificationEventCode::SiteSyncStuck,
            severity: NotificationSeverity::Danger,
            recipients: $this->recipients->forSiteSync($tenantOwnerId, $initiatorUserId),
            title: sprintf('Site Sync bị dừng ở bước %s', $stepName),
            message: sprintf('Không nhận được callback trong %d phút.', $minutesWithoutCallback),
            context: [
                'tenant_id' => $tenantOwnerId,
                'connection_id' => $connectionId,
                'run_id' => (int) $run->getKey(),
                'step_name' => $stepName,
                'source' => 'site_sync_stuck',
            ],
            actionUrl: $url,
            actions: [
                ['label' => 'Mở Sync Run', 'url' => $url, 'name' => 'open_run'],
                ['label' => 'Xem bước lỗi', 'url' => $url, 'name' => 'open_step'],
                ['label' => 'Kiểm tra kết nối', 'url' => $this->links->operationsCenter(), 'name' => 'check_connection'],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );
    }

    public function notifyFailed(
        SeoSiteSyncRun $run,
        int $tenantOwnerId,
        string $message,
        ?int $initiatorUserId = null,
        ?string $siteLabel = null,
    ): void {
        $connectionId = (int) ($run->site_id ?? 0);
        $runId = (int) $run->getKey();
        $dedup = sprintf('site-sync-run:%d:%d:%d', $tenantOwnerId, $connectionId, $runId);
        $url = $this->links->siteSyncRun($runId);

        $this->notifications->notify(
            eventCode: OperationalNotificationEventCode::SiteSyncFailed,
            severity: NotificationSeverity::Danger,
            recipients: $this->recipients->forSiteSync($tenantOwnerId, $initiatorUserId),
            title: 'Site Sync thất bại',
            message: sprintf(
                'Không thể hoàn tất snapshot cho website %s. %s',
                $siteLabel !== null && $siteLabel !== '' ? $siteLabel : '#'.$connectionId,
                mb_substr(trim($message), 0, 240),
            ),
            context: [
                'tenant_id' => $tenantOwnerId,
                'connection_id' => $connectionId,
                'run_id' => $runId,
                'source' => 'site_sync_failed',
            ],
            actionUrl: $url,
            actions: [
                ['label' => 'Mở Sync Run', 'url' => $url, 'name' => 'open_run'],
                ['label' => 'Mở Operation', 'url' => $this->links->operationsCenter(), 'name' => 'open_ops'],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );
    }

    public function resolveStuck(int $tenantOwnerId, int $connectionId, string $stepName): void
    {
        $dedup = sprintf('site-sync-stuck:%d:%d:%s', $tenantOwnerId, $connectionId, $this->slug($stepName));
        $this->notifications->resolve(
            dedupKey: $dedup,
            recoveryTitle: 'Site Sync đã tiếp tục',
            recoveryMessage: sprintf('Bước %s đã khôi phục.', $stepName),
            recoveryEventCode: OperationalNotificationEventCode::SiteSyncRecovered,
            recoveryRecipients: $this->recipients->forSiteSync($tenantOwnerId),
            emitRecovery: true,
            recoveryContext: [
                'tenant_id' => $tenantOwnerId,
                'connection_id' => $connectionId,
                'step_name' => $stepName,
                'source' => 'site_sync_recovered',
            ],
        );
    }

    public function resolveRun(int $tenantOwnerId, int $connectionId, int $runId): void
    {
        $dedup = sprintf('site-sync-run:%d:%d:%d', $tenantOwnerId, $connectionId, $runId);
        $this->notifications->resolve($dedup);
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? 'unknown');

        return $slug !== '' ? $slug : 'unknown';
    }
}
