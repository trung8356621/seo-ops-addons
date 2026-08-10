<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications;

use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical operational notification service — single write path for Notification Center incidents.
 */
final class OperationalNotificationService
{
    public function __construct(
        private readonly OperationalNotificationAudit $audit,
    ) {}

    /**
     * @param  Collection<int, User>|iterable<User>  $recipients
     * @param  array<string, mixed>  $context
     * @param  list<array{label: string, url: string, name?: string, open_in_new_tab?: bool}>  $actions
     * @return array{created: int, updated: int, skipped: int}
     */
    public function notify(
        OperationalNotificationEventCode|string $eventCode,
        NotificationSeverity|string $severity,
        iterable $recipients,
        string $title,
        string $message,
        array $context = [],
        ?string $actionUrl = null,
        array $actions = [],
        string $dedupKey = '',
        ?string $groupKey = null,
        bool $resolvable = true,
    ): array {
        if (! $this->tableReady()) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $code = $eventCode instanceof OperationalNotificationEventCode
            ? $eventCode
            : OperationalNotificationEventCode::tryFrom((string) $eventCode);

        if (! $code instanceof OperationalNotificationEventCode) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $sev = $severity instanceof NotificationSeverity
            ? $severity
            : (NotificationSeverity::tryFrom((string) $severity) ?? NotificationSeverity::Info);

        $dedupKey = trim($dedupKey);
        if ($dedupKey === '') {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $title = trim($title);
        $message = trim($message);
        if ($title === '' || $message === '') {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $users = $this->normalizeRecipients($recipients);
        if ($users->isEmpty()) {
            $this->audit->recorded('skipped_no_recipients', $code->value, $dedupKey, 0, $context);

            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $context = $this->normalizeContext($context, $code);
        $filamentActions = $this->buildActions($actions, $actionUrl);
        $now = now();

        $created = 0;
        $updated = 0;

        foreach ($users as $user) {
            $existing = $this->findActive($dedupKey, $user);
            if ($existing instanceof DatabaseNotification) {
                $this->updateExisting(
                    $existing,
                    $code,
                    $sev,
                    $title,
                    $message,
                    $context,
                    $filamentActions,
                    $groupKey,
                    $resolvable,
                    $now,
                );
                $updated++;
                continue;
            }

            $this->createNew(
                $user,
                $code,
                $sev,
                $title,
                $message,
                $context,
                $filamentActions,
                $dedupKey,
                $groupKey,
                $resolvable,
                $now,
            );
            $created++;
        }

        $this->audit->recorded(
            $updated > 0 && $created === 0 ? 'updated' : 'created',
            $code->value,
            $dedupKey,
            $users->count(),
            $context + ['created' => $created, 'updated' => $updated],
        );

        return ['created' => $created, 'updated' => $updated, 'skipped' => 0];
    }

    /**
     * Resolve active incidents by dedup key. Optionally emit one recovery info notification.
     *
     * @param  Collection<int, User>|iterable<User>|null  $recoveryRecipients
     * @return array{resolved: int, recovery_created: int}
     */
    public function resolve(
        string $dedupKey,
        ?string $recoveryTitle = null,
        ?string $recoveryMessage = null,
        ?OperationalNotificationEventCode $recoveryEventCode = null,
        iterable $recoveryRecipients = [],
        bool $emitRecovery = false,
        array $recoveryContext = [],
        ?string $recoveryActionUrl = null,
    ): array {
        if (! $this->tableReady() || trim($dedupKey) === '') {
            return ['resolved' => 0, 'recovery_created' => 0];
        }

        $query = DatabaseNotification::query()
            ->where('dedup_key', $dedupKey)
            ->whereNull('resolved_at');

        $active = $query->get();
        $hadDangerOrCritical = false;
        $now = now();

        foreach ($active as $row) {
            if (! $row instanceof DatabaseNotification) {
                continue;
            }
            $sev = (string) ($row->getAttribute('severity') ?? '');
            if (in_array($sev, [NotificationSeverity::Danger->value, NotificationSeverity::Critical->value], true)) {
                $hadDangerOrCritical = true;
            }

            $data = is_array($row->data) ? $row->data : [];
            $ops = is_array($data['operational'] ?? null) ? $data['operational'] : [];
            $ops['resolved'] = true;
            $ops['resolved_at'] = $now->toIso8601String();
            $data['operational'] = $ops;
            if (is_string($data['title'] ?? null) && ! str_starts_with((string) $data['title'], '[Đã khôi phục]')) {
                // Keep title; body may be updated by caller via recovery notify.
            }
            $row->forceFill([
                'data' => $data,
                'resolved_at' => $now,
                'read_at' => $row->read_at ?? $now,
            ])->save();
        }

        $resolved = $active->count();
        if ($resolved > 0) {
            $this->audit->recorded('resolved', (string) ($active->first()?->getAttribute('event_code') ?? ''), $dedupKey, $resolved, $recoveryContext);
        }

        $recoveryCreated = 0;
        if (
            $emitRecovery
            && $hadDangerOrCritical
            && $recoveryTitle !== null
            && $recoveryMessage !== null
            && $recoveryEventCode instanceof OperationalNotificationEventCode
        ) {
            $recoveryDedup = 'recovery:'.$dedupKey;
            $already = DatabaseNotification::query()
                ->where('dedup_key', $recoveryDedup)
                ->exists();

            if (! $already) {
                $result = $this->notify(
                    eventCode: $recoveryEventCode,
                    severity: NotificationSeverity::Info,
                    recipients: $recoveryRecipients,
                    title: $recoveryTitle,
                    message: $recoveryMessage,
                    context: $recoveryContext + ['source' => 'recovery'],
                    actionUrl: $recoveryActionUrl,
                    actions: $recoveryActionUrl !== null ? [[
                        'label' => 'Mở',
                        'url' => $recoveryActionUrl,
                    ]] : [],
                    dedupKey: $recoveryDedup,
                    resolvable: false,
                );
                $recoveryCreated = (int) ($result['created'] ?? 0);
            }
        }

        return ['resolved' => $resolved, 'recovery_created' => $recoveryCreated];
    }

    public function unreadOperationalCount(User $user): int
    {
        if (! $this->tableReady()) {
            return (int) $user->unreadNotifications()->count();
        }

        return (int) $user->unreadNotifications()
            ->where(function ($query): void {
                $query->whereNull('resolved_at')
                    ->orWhereNull('event_code');
            })
            ->count();
    }

    public function tableReady(): bool
    {
        if (! Schema::hasTable('notifications')) {
            return false;
        }

        return Schema::hasColumn('notifications', 'dedup_key')
            && Schema::hasColumn('notifications', 'resolved_at');
    }

    private function findActive(string $dedupKey, User $user): ?DatabaseNotification
    {
        /** @var DatabaseNotification|null $row */
        $row = DatabaseNotification::query()
            ->where('dedup_key', $dedupKey)
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->whereNull('resolved_at')
            ->orderByDesc('last_occurred_at')
            ->orderByDesc('created_at')
            ->first();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<Action>  $filamentActions
     */
    private function updateExisting(
        DatabaseNotification $row,
        OperationalNotificationEventCode $code,
        NotificationSeverity $severity,
        string $title,
        string $message,
        array $context,
        array $filamentActions,
        ?string $groupKey,
        bool $resolvable,
        mixed $now,
    ): void {
        $count = max(1, (int) ($row->getAttribute('occurrence_count') ?? 1)) + 1;
        $data = $this->filamentData($code, $severity, $title, $message, $context, $filamentActions, $count, $resolvable);

        $row->forceFill([
            'type' => FilamentNotification::class,
            'data' => $data,
            'event_code' => $code->value,
            'severity' => $severity->value,
            'group_key' => $groupKey,
            'occurrence_count' => $count,
            'last_occurred_at' => $now,
            'read_at' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<Action>  $filamentActions
     */
    private function createNew(
        User $user,
        OperationalNotificationEventCode $code,
        NotificationSeverity $severity,
        string $title,
        string $message,
        array $context,
        array $filamentActions,
        string $dedupKey,
        ?string $groupKey,
        bool $resolvable,
        mixed $now,
    ): void {
        $data = $this->filamentData($code, $severity, $title, $message, $context, $filamentActions, 1, $resolvable);

        $notification = FilamentNotification::make()
            ->title($title)
            ->body($this->formatBody($message, $code, 1))
            ->icon($severity->filamentIcon())
            ->iconColor($severity->filamentIconColor())
            ->status($severity->filamentStatus())
            ->persistent();

        if ($filamentActions !== []) {
            $notification->actions($filamentActions);
        }

        $notification->sendToDatabase($user);

        /** @var DatabaseNotification|null $row */
        $row = $user->notifications()->latest()->first();
        if (! $row instanceof DatabaseNotification) {
            return;
        }

        // Ensure operational payload + columns (Filament may overwrite data shape).
        $row->forceFill([
            'data' => $data,
            'event_code' => $code->value,
            'severity' => $severity->value,
            'dedup_key' => $dedupKey,
            'group_key' => $groupKey,
            'occurrence_count' => 1,
            'first_occurred_at' => $now,
            'last_occurred_at' => $now,
            'resolved_at' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<Action>  $filamentActions
     * @return array<string, mixed>
     */
    private function filamentData(
        OperationalNotificationEventCode $code,
        NotificationSeverity $severity,
        string $title,
        string $message,
        array $context,
        array $filamentActions,
        int $occurrenceCount,
        bool $resolvable,
    ): array {
        $actionsPayload = array_map(static function (Action $action): array {
            return $action->toArray();
        }, $filamentActions);

        return [
            'format' => 'filament',
            'title' => $title,
            'body' => $this->formatBody($message, $code, $occurrenceCount),
            'icon' => $severity->filamentIcon(),
            'iconColor' => $severity->filamentIconColor(),
            'status' => $severity->filamentStatus(),
            'duration' => 'persistent',
            'actions' => $actionsPayload,
            'operational' => [
                'event_code' => $code->value,
                'severity' => $severity->value,
                'module' => $code->module(),
                'occurrence_count' => $occurrenceCount,
                'resolvable' => $resolvable,
                'context' => $context,
            ],
        ];
    }

    private function formatBody(string $message, OperationalNotificationEventCode $code, int $occurrenceCount): string
    {
        $prefix = '['.$code->module().'] ';
        $suffix = $occurrenceCount > 1 ? ' · '.$occurrenceCount.' lần' : '';

        return $prefix.$message.$suffix;
    }

    /**
     * @param  list<array{label: string, url: string, name?: string, open_in_new_tab?: bool}>  $actions
     * @return list<Action>
     */
    private function buildActions(array $actions, ?string $actionUrl): array
    {
        $built = [];
        foreach ($actions as $index => $action) {
            $label = trim((string) ($action['label'] ?? ''));
            $url = trim((string) ($action['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            $name = (string) ($action['name'] ?? ('action_'.$index));
            $filament = Action::make($name)
                ->label($label)
                ->url($url, shouldOpenInNewTab: (bool) ($action['open_in_new_tab'] ?? false))
                ->button();
            $built[] = $filament;
        }

        if ($built === [] && $actionUrl !== null && trim($actionUrl) !== '') {
            $built[] = Action::make('open')
                ->label('Mở')
                ->url($actionUrl)
                ->button();
        }

        return $built;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context, OperationalNotificationEventCode $code): array
    {
        $context['module'] = $context['module'] ?? $code->module();
        $context['event_code'] = $code->value;

        return $context;
    }

    /**
     * @param  iterable<User>  $recipients
     * @return Collection<int, User>
     */
    private function normalizeRecipients(iterable $recipients): Collection
    {
        return collect($recipients)
            ->filter(static fn (mixed $user): bool => $user instanceof User)
            ->filter(static fn (User $user): bool => (string) $user->status === User::STATUS_NORMAL)
            ->unique(static fn (User $user): int => (int) $user->id)
            ->values();
    }
}
