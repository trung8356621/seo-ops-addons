<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Omnichannel\Addons\Seo\Enums\NotificationDisplaySurface;
use Omnichannel\Addons\Seo\Enums\NotificationSeverity;

/**
 * Read-side provider for the Operational Alert Hook UI surface.
 *
 * Returns active, unresolved operational notifications that publishers
 * explicitly promoted via NotificationDisplaySurface::OperationalAlert.
 */
final class OperationalAlertHookService
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
    ) {}

    /**
     * @return list<OperationalAlert>
     */
    public function forUser(User $user): array
    {
        if (! $this->notifications->tableReady()) {
            return [];
        }

        /** @var Collection<int, DatabaseNotification> $rows */
        $rows = $user->notifications()
            ->whereNotNull('event_code')
            ->whereNotNull('dedup_key')
            ->whereNull('resolved_at')
            ->orderByDesc('last_occurred_at')
            ->orderByDesc('created_at')
            ->get();

        return $this->fromActiveRows($rows);
    }

    /**
     * Normalize / filter / order active notification rows for the hook.
     * Exposed for focused unit tests without full notification persistence.
     *
     * @param  Collection<int, DatabaseNotification>|iterable<DatabaseNotification>  $rows
     * @return list<OperationalAlert>
     */
    public function fromActiveRows(iterable $rows): array
    {
        /** @var list<array{alert: OperationalAlert, last: int}> $candidates */
        $candidates = [];
        foreach ($rows as $row) {
            if (! $row instanceof DatabaseNotification) {
                continue;
            }

            if ($row->getAttribute('resolved_at') !== null) {
                continue;
            }

            $data = is_array($row->data) ? $row->data : [];
            $surfaces = NotificationDisplaySurface::fromNotificationData($data);
            if (! NotificationDisplaySurface::includesOperationalAlertHook($surfaces)) {
                continue;
            }

            $alert = $this->normalize($row, $data);
            if (! $alert instanceof OperationalAlert) {
                continue;
            }

            $last = $row->getAttribute('last_occurred_at');
            $candidates[] = [
                'alert' => $alert,
                'last' => $last instanceof \DateTimeInterface ? $last->getTimestamp() : (is_string($last) ? (int) strtotime($last) : 0),
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            $rank = static fn (NotificationSeverity $s): int => match ($s) {
                NotificationSeverity::Critical => 0,
                NotificationSeverity::Danger => 1,
                NotificationSeverity::Warning => 2,
                NotificationSeverity::Info => 3,
            };

            $bySeverity = $rank($a['alert']->severity) <=> $rank($b['alert']->severity);
            if ($bySeverity !== 0) {
                return $bySeverity;
            }

            return $b['last'] <=> $a['last'];
        });

        return array_map(static fn (array $row): OperationalAlert => $row['alert'], $candidates);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalize(DatabaseNotification $row, array $data): ?OperationalAlert
    {
        $title = trim((string) ($data['title'] ?? ''));
        $message = trim((string) ($data['body'] ?? ''));
        if ($title === '' || $message === '') {
            return null;
        }

        $ops = is_array($data['operational'] ?? null) ? $data['operational'] : [];
        $severity = NotificationSeverity::tryFrom((string) ($row->getAttribute('severity') ?? $ops['severity'] ?? ''))
            ?? NotificationSeverity::Info;

        $occurrence = max(
            1,
            (int) ($row->getAttribute('occurrence_count') ?? $ops['occurrence_count'] ?? 1),
        );

        [$actionUrl, $actionLabel] = $this->extractAction($data);

        return new OperationalAlert(
            id: (string) $row->getKey(),
            title: $title,
            message: $message,
            severity: $severity,
            occurrenceCount: $occurrence,
            eventCode: (string) ($row->getAttribute('event_code') ?? $ops['event_code'] ?? ''),
            dedupKey: (string) ($row->getAttribute('dedup_key') ?? ''),
            actionUrl: $actionUrl,
            actionLabel: $actionLabel,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string}
     */
    private function extractAction(array $data): array
    {
        $actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $url = trim((string) ($action['url'] ?? ''));
            $label = trim((string) ($action['label'] ?? ''));
            if ($url === '') {
                continue;
            }

            return [$url, $label !== '' ? $label : 'Mở'];
        }

        return [null, null];
    }
}
