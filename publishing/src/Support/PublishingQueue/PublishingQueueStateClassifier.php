<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

/**
 * Publishing Queue presentation states — every queue row maps to exactly one.
 *
 * unscheduled | scheduled | awaiting_delivery | publishing | retry_wait | published | failed | needs_attention
 */
final class PublishingQueueStateClassifier
{
    public const UNSCHEDULED = 'unscheduled';

    public const SCHEDULED = 'scheduled';

    public const AWAITING_DELIVERY = 'awaiting_delivery';

    /** @deprecated use AWAITING_DELIVERY */
    public const AWAITING_WORKER = self::AWAITING_DELIVERY;

    public const PUBLISHING = 'publishing';

    public const RETRY_WAIT = 'retry_wait';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';

    public const NEEDS_ATTENTION = 'needs_attention';

    /**
     * @param  array<string, mixed>  $row
     * @return array{state: string, label: string}
     */
    public static function classify(array $row): array
    {
        if (PublishingQueuePublishedDefinition::matches($row)) {
            return ['state' => self::PUBLISHED, 'label' => 'Đã xuất bản'];
        }
        if (PublishingQueueFailedDefinition::matches($row)) {
            return ['state' => self::FAILED, 'label' => PublishingQueueStatusLabelBuilder::label(array_merge($row, ['publish_state' => self::FAILED]))];
        }
        if (PublishingQueuePublishingDefinition::matches($row)) {
            $label = PublishingQueueStatusLabelBuilder::label(array_merge($row, ['publish_state' => self::PUBLISHING]));

            return ['state' => self::PUBLISHING, 'label' => $label];
        }
        if (PublishingQueueAwaitingWorkerDefinition::matches($row)) {
            return ['state' => self::AWAITING_DELIVERY, 'label' => 'Đang chuẩn bị'];
        }
        if (PublishingQueueRetryWaitDefinition::matches($row)) {
            $label = PublishingQueueStatusLabelBuilder::label(array_merge($row, ['publish_state' => self::RETRY_WAIT]));

            return ['state' => self::RETRY_WAIT, 'label' => $label];
        }
        if (PublishingQueueScheduledDefinition::matches($row)) {
            return ['state' => self::SCHEDULED, 'label' => 'Đã lên lịch'];
        }
        // Unknown/cancelled/skipped raw statuses before unscheduled catch-all.
        if (PublishingQueueNeedsAttentionDefinition::matches($row)) {
            return ['state' => self::NEEDS_ATTENTION, 'label' => 'Cần xử lý'];
        }
        if (self::isExplicitUnscheduled($row)) {
            return ['state' => self::UNSCHEDULED, 'label' => 'Chưa lên lịch'];
        }
        if (self::hasQueueMembership($row)) {
            return ['state' => self::NEEDS_ATTENTION, 'label' => 'Cần xử lý'];
        }

        return ['state' => self::UNSCHEDULED, 'label' => 'Chưa lên lịch'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public static function countSummary(array $rows): array
    {
        $counts = [
            'unscheduled' => 0,
            'scheduled' => 0,
            'awaiting_delivery' => 0,
            'awaiting_worker' => 0, // alias mirror for older readers
            'publishing' => 0,
            'retry_wait' => 0,
            'published' => 0,
            'failed' => 0,
            'needs_attention' => 0,
            'total' => count($rows),
            'projected_sum' => 0,
            'invariant_ok' => true,
        ];
        foreach ($rows as $row) {
            $state = self::classify($row)['state'];
            if ($state === self::AWAITING_DELIVERY) {
                $counts['awaiting_delivery']++;
                $counts['awaiting_worker']++;
            } elseif (isset($counts[$state])) {
                $counts[$state]++;
            } else {
                $counts['needs_attention']++;
            }
        }

        $counts['projected_sum'] = $counts['unscheduled']
            + $counts['scheduled']
            + $counts['awaiting_delivery']
            + $counts['publishing']
            + $counts['retry_wait']
            + $counts['published']
            + $counts['failed']
            + $counts['needs_attention'];
        $counts['invariant_ok'] = $counts['projected_sum'] === $counts['total'];

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matchesFilter(array $row, string $filter): bool
    {
        $filter = strtolower(trim($filter));
        if ($filter === '' || $filter === 'all') {
            return true;
        }
        // Compat: old filter key.
        if ($filter === 'awaiting_worker') {
            $filter = self::AWAITING_DELIVERY;
        }

        return self::classify($row)['state'] === $filter;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function isExplicitUnscheduled(array $row): bool
    {
        $queued = $row['publishing_queued_at'] ?? null;
        $hasQueue = $queued !== null && $queued !== '';
        $at = PublishingQueueScheduledDefinition::scheduledAt($row);
        $queue = strtolower(trim((string) ($row['publish_queue_status'] ?? $row['queue_status'] ?? '')));

        // Only known idle statuses qualify as unscheduled — unknown raw → needs_attention.
        return $hasQueue
            && $at === null
            && in_array($queue, ['none', ''], true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function hasQueueMembership(array $row): bool
    {
        $queued = $row['publishing_queued_at'] ?? null;

        return ($queued !== null && $queued !== '')
            || trim((string) ($row['publish_queue_status'] ?? '')) !== '';
    }
}
