<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications\Publishers;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationSchedulerHeartbeat;
use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncHeartbeat;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\OperationalStatusParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

final class RunnerHealthNotificationPublisher
{
    /** @var array<string, array{label: string, interval_minutes: int, warning_cycles: int, critical_cycles: int}> */
    private const RUNNERS = [
        'publishing' => [
            'label' => 'Publishing runner',
            'interval_minutes' => 1,
            'warning_cycles' => 5,
            'critical_cycles' => 10,
        ],
        'automation_dispatch' => [
            'label' => 'Automation dispatcher',
            'interval_minutes' => 1,
            'warning_cycles' => 5,
            'critical_cycles' => 10,
        ],
        'agent_automation_dispatch' => [
            'label' => 'Agent automation dispatcher',
            'interval_minutes' => 1,
            'warning_cycles' => 5,
            'critical_cycles' => 10,
        ],
        'site_sync_reconciler' => [
            'label' => 'Site Sync reconciler',
            'interval_minutes' => 60,
            'warning_cycles' => 2,
            'critical_cycles' => 3,
        ],
    ];

    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
        private readonly OperationalNotificationDeepLinks $links,
        private readonly ContentProjectQueueHealthService $publishingHealth,
    ) {}

    /**
     * @return list<array{runner: string, status: string}>
     */
    public function checkAll(?int $tenantOwnerId = null, ?int $connectionId = null, int $pendingPublishing = 0): array
    {
        $results = [];
        foreach (array_keys(self::RUNNERS) as $runner) {
            $results[] = $this->checkRunner($runner, $tenantOwnerId, $connectionId, $pendingPublishing);
        }

        return $results;
    }

    /**
     * @return array{runner: string, status: string}
     */
    public function checkRunner(
        string $runnerName,
        ?int $tenantOwnerId = null,
        ?int $connectionId = null,
        int $pendingCount = 0,
    ): array {
        $config = self::RUNNERS[$runnerName] ?? null;
        if ($config === null) {
            return ['runner' => $runnerName, 'status' => 'unknown'];
        }

        $lastBeat = $this->lastBeatAt($runnerName, $connectionId);
        $ageMinutes = $lastBeat !== null ? $lastBeat->diffInMinutes(now()) : PHP_INT_MAX;
        $warningMinutes = $config['interval_minutes'] * $config['warning_cycles'];
        $criticalMinutes = $config['interval_minutes'] * $config['critical_cycles'];

        $tenantId = $tenantOwnerId ?? $this->defaultTenantOwnerId();
        $dedup = sprintf('runner-health:%d:%s', max(0, $tenantId), $runnerName);

        if ($ageMinutes < $warningMinutes) {
            $this->notifications->resolve(
                dedupKey: $dedup,
                recoveryTitle: $config['label'].' đã hoạt động trở lại',
                recoveryMessage: 'Các item tồn đọng đang được tiếp tục xử lý.',
                recoveryEventCode: OperationalNotificationEventCode::RunnerRecovered,
                recoveryRecipients: $tenantId > 0 ? $this->recipients->forRunnerHealth($tenantId) : collect(),
                emitRecovery: true,
                recoveryContext: [
                    'tenant_id' => $tenantId,
                    'runner_name' => $runnerName,
                    'source' => 'runner_recovered',
                ],
                recoveryActionUrl: $this->links->operationsCenter(),
            );

            return ['runner' => $runnerName, 'status' => 'healthy'];
        }

        if ($tenantId <= 0) {
            return ['runner' => $runnerName, 'status' => 'unhealthy_no_tenant'];
        }

        $severity = $ageMinutes >= $criticalMinutes
            ? NotificationSeverity::Critical
            : NotificationSeverity::Warning;

        $ageLabel = $ageMinutes === PHP_INT_MAX
            ? 'chưa ghi nhận lần chạy'
            : 'không ghi nhận lần chạy trong '.$ageMinutes.' phút';

        $message = ucfirst($ageLabel).'.';
        if ($runnerName === 'publishing' && $pendingCount > 0) {
            $message .= ' '.$pendingCount.' bài đang chờ xử lý.';
        }

        $this->notifications->notify(
            eventCode: OperationalNotificationEventCode::RunnerUnhealthy,
            severity: $severity,
            recipients: $this->recipients->forRunnerHealth($tenantId),
            title: $config['label'].' không hoạt động',
            message: $message,
            context: [
                'tenant_id' => $tenantId,
                'connection_id' => $connectionId,
                'runner_name' => $runnerName,
                'age_minutes' => $ageMinutes === PHP_INT_MAX ? null : $ageMinutes,
                'pending_count' => $pendingCount,
                'source' => 'runner_health_check',
            ],
            actionUrl: $this->links->operationsCenter(),
            actions: [
                ['label' => 'Mở Operation Center', 'url' => $this->links->operationsCenter(), 'name' => 'open_ops'],
                ['label' => 'Xem queue bị ảnh hưởng', 'url' => $this->links->publishingQueue(), 'name' => 'open_queue'],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );

        return ['runner' => $runnerName, 'status' => $severity->value];
    }

    private function lastBeatAt(string $runnerName, ?int $connectionId): ?Carbon
    {
        return match ($runnerName) {
            'publishing' => $this->publishingLastBeat($connectionId),
            'automation_dispatch' => $this->automationBeat('dispatch_scheduled'),
            'agent_automation_dispatch' => $this->automationBeat('agent_dispatch_due'),
            'site_sync_reconciler' => $this->siteSyncBeat(),
            default => null,
        };
    }

    private function publishingLastBeat(?int $connectionId): ?Carbon
    {
        $snapshot = $this->publishingHealth->snapshot(null, $connectionId);
        $raw = $snapshot['last_worker_run'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $occurredAt = OperationalStatusParser::occurredAt($raw);

        return $occurredAt;
    }

    private function automationBeat(string $name): ?Carbon
    {
        if (! Schema::hasTable('automation_scheduler_heartbeats')) {
            return null;
        }

        try {
            $row = AutomationSchedulerHeartbeat::query()->where('name', $name)->first();
        } catch (\Throwable) {
            return null;
        }

        return $row?->last_beat_at;
    }

    private function siteSyncBeat(): ?Carbon
    {
        if (! class_exists(SeoSiteSyncHeartbeat::class)) {
            return null;
        }

        try {
            $row = SeoSiteSyncHeartbeat::query()
                ->whereIn('channel', ['scheduler', 'queue'])
                ->orderByDesc('last_seen_at')
                ->first();
        } catch (\Throwable) {
            return null;
        }

        $beat = $row?->last_seen_at ?? null;

        return $beat instanceof Carbon ? $beat : ($beat !== null ? Carbon::parse($beat) : null);
    }

    private function defaultTenantOwnerId(): int
    {
        $owner = User::query()
            ->where('status', User::STATUS_NORMAL)
            ->where('seo_role', User::SEO_ROLE_MANAGER)
            ->whereNull('parent_id')
            ->orderBy('id')
            ->first();

        return $owner instanceof User ? (int) $owner->id : 0;
    }
}
