<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications\Publishers;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;
use Omnichannel\Addons\SiteSync\Contracts\SiteHealthNotificationCapability;
use Omnichannel\Addons\SiteSync\Models\SiteHealthIncident;

final class SiteHealthNotificationPublisher implements SiteHealthNotificationCapability
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
    ) {}

    public function incidentActive(Site $site, SiteHealthIncident $incident): void
    {
        $event = $incident->status === 'down'
            ? OperationalNotificationEventCode::SiteHealthDown
            : OperationalNotificationEventCode::SiteHealthDegraded;
        $severity = match ($incident->severity) {
            'critical' => NotificationSeverity::Critical,
            'warning' => NotificationSeverity::Warning,
            default => NotificationSeverity::Info,
        };

        $this->notifications->notify(
            eventCode: $event,
            severity: $severity,
            recipients: $this->recipients->forWordPressConnection((int) $site->user_id),
            title: __('site_health.notification.active_title', ['site' => $site->domain]),
            message: (string) $incident->reason,
            context: ['site_id' => (int) $site->id, 'incident_id' => (int) $incident->id, 'error_code' => $incident->error_code, 'source' => 'site_health'],
            actionUrl: url('/seo'),
            dedupKey: 'site-health:incident:'.$incident->id,
            groupKey: 'site-health:site:'.$site->id,
            resolvable: true,
        );
    }

    public function incidentResolved(Site $site, SiteHealthIncident $incident): void
    {
        $this->notifications->resolve(
            dedupKey: 'site-health:incident:'.$incident->id,
            recoveryTitle: __('site_health.notification.recovered_title', ['site' => $site->domain]),
            recoveryMessage: __('site_health.notification.recovered_message'),
            recoveryEventCode: OperationalNotificationEventCode::SiteHealthRecovered,
            recoveryRecipients: $this->recipients->forWordPressConnection((int) $site->user_id),
            emitRecovery: true,
            recoveryContext: ['site_id' => (int) $site->id, 'incident_id' => (int) $incident->id, 'source' => 'site_health'],
        );
    }
}
