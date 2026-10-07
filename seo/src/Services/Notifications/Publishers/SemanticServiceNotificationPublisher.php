<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications\Publishers;

use App\Models\User;
use Illuminate\Support\Collection;
use Omnichannel\Addons\SearchIntelligence\Contracts\SemanticServiceNotificationCapability;
use Omnichannel\Addons\Seo\Enums\NotificationDisplaySurface;
use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;

/**
 * Publishes seo-ops-semantic availability into Notification Center + Operational Alert Hook.
 * Dedup key is shared across unavailable/degraded so one outage = one active hook item.
 */
final class SemanticServiceNotificationPublisher implements SemanticServiceNotificationCapability
{
    public const DEDUP_KEY = 'semantic-service:availability';

    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
    ) {}

    public function serviceUnavailable(array $context = []): void
    {
        $this->publishActive(
            event: OperationalNotificationEventCode::SemanticServiceUnavailable,
            severity: NotificationSeverity::Critical,
            title: 'Semantic Service không hoạt động',
            message: 'Các chức năng SEO phụ thuộc semantic tạm thời không khả dụng. Hệ thống không dùng fallback heuristic giả lập.',
            context: $context,
        );
    }

    public function serviceDegraded(array $context = []): void
    {
        $this->publishActive(
            event: OperationalNotificationEventCode::SemanticServiceDegraded,
            severity: NotificationSeverity::Danger,
            title: 'Semantic Service chưa sẵn sàng',
            message: 'Semantic API phản hồi nhưng /health/ready chưa ready. Các thao tác semantic có thể thất bại; không có fallback Laravel heuristic.',
            context: $context,
        );
    }

    public function serviceRecovered(array $context = []): void
    {
        $users = $this->operatorRecipients();
        $this->notifications->resolve(
            dedupKey: self::DEDUP_KEY,
            recoveryTitle: 'Semantic Service đã hoạt động trở lại',
            recoveryMessage: 'Kết nối seo-ops-semantic đã phục hồi. Các chức năng semantic có thể dùng lại.',
            recoveryEventCode: OperationalNotificationEventCode::SemanticServiceRecovered,
            recoveryRecipients: $users,
            emitRecovery: true,
            recoveryContext: array_merge(['source' => 'semantic_service'], $context),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function publishActive(
        OperationalNotificationEventCode $event,
        NotificationSeverity $severity,
        string $title,
        string $message,
        array $context,
    ): void {
        $users = $this->operatorRecipients();
        if ($users->isEmpty()) {
            return;
        }

        $safe = $this->sanitizeContext($context);
        $detail = trim((string) ($safe['reason'] ?? ''));
        if ($detail !== '' && ! str_contains($message, $detail)) {
            $message .= ' '.$detail;
        }

        $this->notifications->notify(
            eventCode: $event,
            severity: $severity,
            recipients: $users,
            title: $title,
            message: $message,
            context: $safe,
            actionUrl: url('/seo'),
            dedupKey: self::DEDUP_KEY,
            groupKey: self::DEDUP_KEY,
            resolvable: true,
            displaySurfaces: NotificationDisplaySurface::withOperationalAlertHook(),
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function operatorRecipients(): Collection
    {
        $ownerIds = User::query()
            ->where('status', User::STATUS_NORMAL)
            ->whereNull('parent_id')
            ->orderBy('id')
            ->pluck('id');

        $all = collect();
        foreach ($ownerIds as $ownerId) {
            $all = $all->merge($this->recipients->forRunnerHealth((int) $ownerId));
        }

        return $all
            ->filter(static fn (mixed $user): bool => $user instanceof User)
            ->unique(static fn (User $user): int => (int) $user->id)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $allowed = [
            'source', 'health_status', 'error_code', 'semantic_host',
            'http_status', 'latency_ms', 'ready', 'status', 'reason',
        ];
        $out = ['source' => 'semantic_service'];
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
