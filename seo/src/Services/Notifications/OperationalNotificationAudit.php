<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications;

use App\Support\RuntimeLogger;

/**
 * Aggregate audit for operational notification lifecycle (one event per notify/resolve, not per recipient).
 */
final class OperationalNotificationAudit
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function recorded(
        string $action,
        string $eventCode,
        string $dedupKey,
        int $recipientCount,
        array $context = [],
    ): void {
        RuntimeLogger::info('seo.operational_notification.'.$action, array_filter([
            'event_code' => $eventCode,
            'dedup_key' => $dedupKey,
            'recipient_count' => $recipientCount,
            'module' => $context['module'] ?? null,
            'operation_id' => $context['operation_id'] ?? null,
            'project_id' => $context['project_id'] ?? null,
            'connection_id' => $context['connection_id'] ?? ($context['domain_id'] ?? null),
            'source' => $context['source'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
