<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Trạng thái Publishing Queue trên Content Project Item (SaaS-owned).
 *
 * queued_for_delivery = scanner claimed + downstream dispatched, WP worker not started.
 * processing = publisher worker started (owns active lease).
 */
enum ContentProjectPublishQueueStatus: string
{
    case None = 'none';
    case Waiting = 'waiting';
    case QueuedForDelivery = 'queued_for_delivery';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Published = 'published';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function isActiveQueue(): bool
    {
        return in_array($this, [
            self::Waiting,
            self::QueuedForDelivery,
            self::Processing,
            self::Retrying,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Published,
            self::Skipped,
            self::Cancelled,
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [
            self::Waiting->value,
            self::QueuedForDelivery->value,
            self::Processing->value,
            self::Retrying->value,
        ];
    }
}
