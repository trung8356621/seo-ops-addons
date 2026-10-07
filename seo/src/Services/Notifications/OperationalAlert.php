<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications;

use Omnichannel\Addons\Seo\Enums\NotificationSeverity;

/**
 * Normalized view-model for one Operational Alert Hook item.
 */
final readonly class OperationalAlert
{
    public function __construct(
        public string $id,
        public string $title,
        public string $message,
        public NotificationSeverity $severity,
        public int $occurrenceCount,
        public string $eventCode,
        public string $dedupKey,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     message: string,
     *     severity: string,
     *     occurrence_count: int,
     *     event_code: string,
     *     dedup_key: string,
     *     action_url: ?string,
     *     action_label: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'severity' => $this->severity->value,
            'occurrence_count' => $this->occurrenceCount,
            'event_code' => $this->eventCode,
            'dedup_key' => $this->dedupKey,
            'action_url' => $this->actionUrl,
            'action_label' => $this->actionLabel,
        ];
    }
}
