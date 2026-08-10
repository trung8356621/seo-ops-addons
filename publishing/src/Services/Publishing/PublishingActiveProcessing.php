<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Canonical "actively publishing" = publisher worker started + non-expired lease.
 *
 * Scanner dispatch / queued_for_delivery / historical Operation alone ≠ active.
 * After publisher_started_at column exists: processing without that stamp is NOT active
 * (covers legacy scanner-claimed rows that never reached WordPress).
 */
final class PublishingActiveProcessing
{
    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    public function isActivelyPublishing(SeoProjectTask|array $item, ?DateTimeInterface $nowUtc = null): bool
    {
        $now = $this->normalizeNow($nowUtc);
        $status = $this->queueStatus($item);

        if ($status !== ContentProjectPublishQueueStatus::Processing) {
            return false;
        }

        // New model: publisher worker must have started.
        if ($this->hasAttr($item, 'publisher_started_at')) {
            if ($this->publisherStartedAt($item) === null) {
                return false;
            }
        }

        $lease = $this->leaseExpiresAt($item);
        if ($lease === null) {
            if ($this->hasAttr($item, 'publish_lease_expires_at')) {
                return false;
            }

            $started = $this->publisherStartedAt($item)
                ?? $this->lastAttemptAt($item)
                ?? $this->publishingStartedAt($item);
            if ($started === null) {
                return false;
            }

            return $started->gt($now->subMinutes(PublishingRetryPolicy::LEASE_MINUTES));
        }

        return $lease->gt($now);
    }

    /**
     * Dispatched to automation but WP worker not started.
     *
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    public function isQueuedAwaitingWorker(SeoProjectTask|array $item, ?DateTimeInterface $nowUtc = null): bool
    {
        $status = $this->queueStatus($item);
        if ($status === ContentProjectPublishQueueStatus::QueuedForDelivery) {
            return $this->publisherStartedAt($item) === null;
        }

        // Legacy: processing without publisher_started_at = dispatch-only.
        if ($status === ContentProjectPublishQueueStatus::Processing
            && $this->hasAttr($item, 'publisher_started_at')
            && $this->publisherStartedAt($item) === null
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    public function isDeliveryWorkerStalled(SeoProjectTask|array $item, ?DateTimeInterface $nowUtc = null): bool
    {
        if (! $this->isQueuedAwaitingWorker($item, $nowUtc)) {
            return false;
        }

        $now = $this->normalizeNow($nowUtc);
        $dispatched = $this->deliveryDispatchedAt($item) ?? $this->lastAttemptAt($item);
        if ($dispatched === null) {
            return false;
        }

        return $dispatched->lte($now->subMinutes(PublishingRetryPolicy::AWAITING_WORKER_MINUTES));
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    public function hasStaleProcessingMarkers(SeoProjectTask|array $item, ?DateTimeInterface $nowUtc = null): bool
    {
        $status = $this->queueStatus($item);
        $lease = $this->leaseExpiresAt($item);
        $now = $this->normalizeNow($nowUtc);

        if ($status === ContentProjectPublishQueueStatus::Processing) {
            return ! $this->isActivelyPublishing($item, $now);
        }

        if ($status === ContentProjectPublishQueueStatus::QueuedForDelivery) {
            return $this->isDeliveryWorkerStalled($item, $now)
                || ($lease !== null && $lease->lte($now));
        }

        if ($lease !== null && $lease->gt($now)) {
            return in_array($status, [
                ContentProjectPublishQueueStatus::Waiting,
                ContentProjectPublishQueueStatus::Retrying,
                ContentProjectPublishQueueStatus::None,
                ContentProjectPublishQueueStatus::Failed,
                ContentProjectPublishQueueStatus::Cancelled,
                ContentProjectPublishQueueStatus::Skipped,
            ], true);
        }

        if ($lease !== null && $lease->lte($now)) {
            return in_array($status, [
                ContentProjectPublishQueueStatus::Waiting,
                ContentProjectPublishQueueStatus::Retrying,
                ContentProjectPublishQueueStatus::None,
            ], true);
        }

        if (in_array($status, [
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::Retrying,
            ContentProjectPublishQueueStatus::None,
        ], true) && $this->publishingStartedAt($item) !== null) {
            return true;
        }

        $hasClaimToken = trim((string) $this->attr($item, 'publish_claim_token')) !== ''
            || trim((string) $this->attr($item, 'publish_attempt_token')) !== '';
        $hasClaimedAt = $this->attr($item, 'publish_claimed_at') !== null
            && $this->attr($item, 'publish_claimed_at') !== '';

        return ($hasClaimToken || $hasClaimedAt)
            && in_array($status, [
                ContentProjectPublishQueueStatus::Waiting,
                ContentProjectPublishQueueStatus::Retrying,
                ContentProjectPublishQueueStatus::None,
            ], true);
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    public function classifyStaleReason(SeoProjectTask|array $item, ?DateTimeInterface $nowUtc = null): ?string
    {
        $status = $this->queueStatus($item);
        $now = $this->normalizeNow($nowUtc);

        if ($this->isActivelyPublishing($item, $now)) {
            return 'active_real_publisher';
        }

        if ($this->isDeliveryWorkerStalled($item, $now)) {
            return 'queued_worker_stalled';
        }

        if ($this->isQueuedAwaitingWorker($item, $now)) {
            return 'queued_awaiting_worker';
        }

        if ($status === ContentProjectPublishQueueStatus::Processing) {
            return 'expired_publisher_lease';
        }

        if (! $this->hasStaleProcessingMarkers($item, $now)) {
            return null;
        }

        return match ($status) {
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::None => 'scheduled_with_stale_claim',
            ContentProjectPublishQueueStatus::Retrying => 'retry_wait_with_stale_claim',
            default => 'status_operation_mismatch',
        };
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    private function queueStatus(SeoProjectTask|array $item): ContentProjectPublishQueueStatus
    {
        $raw = (string) $this->attr($item, 'publish_queue_status', $this->attr($item, 'queue_status', ''));

        return ContentProjectPublishQueueStatus::tryFrom($raw) ?? ContentProjectPublishQueueStatus::None;
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    private function leaseExpiresAt(SeoProjectTask|array $item): ?CarbonImmutable
    {
        return $this->parseTimestamp($this->attr($item, 'publish_lease_expires_at'));
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    private function publisherStartedAt(SeoProjectTask|array $item): ?CarbonImmutable
    {
        return $this->parseTimestamp($this->attr($item, 'publisher_started_at'));
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    private function deliveryDispatchedAt(SeoProjectTask|array $item): ?CarbonImmutable
    {
        return $this->parseTimestamp($this->attr($item, 'delivery_dispatched_at'));
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    private function lastAttemptAt(SeoProjectTask|array $item): ?CarbonImmutable
    {
        return $this->parseTimestamp($this->attr($item, 'last_publish_attempt_at'));
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    private function publishingStartedAt(SeoProjectTask|array $item): ?CarbonImmutable
    {
        return $this->parseTimestamp($this->attr($item, 'publishing_started_at'));
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    private function hasAttr(SeoProjectTask|array $item, string $key): bool
    {
        if ($item instanceof SeoProjectTask) {
            return array_key_exists($key, $item->getAttributes());
        }

        return array_key_exists($key, $item);
    }

    /**
     * @param  SeoProjectTask|array<string, mixed>  $item
     */
    private function attr(SeoProjectTask|array $item, string $key, mixed $default = null): mixed
    {
        if ($item instanceof SeoProjectTask) {
            $attrs = $item->getAttributes();
            if (array_key_exists($key, $attrs)) {
                return $attrs[$key];
            }

            return $item->{$key} ?? $default;
        }

        return $item[$key] ?? $default;
    }

    private function parseTimestamp(mixed $raw): ?CarbonImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof DateTimeInterface) {
            return CarbonImmutable::instance($raw)->utc();
        }

        try {
            return CarbonImmutable::parse((string) $raw, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeNow(?DateTimeInterface $nowUtc): CarbonImmutable
    {
        if ($nowUtc instanceof CarbonImmutable) {
            return $nowUtc->utc();
        }
        if ($nowUtc instanceof CarbonInterface) {
            return CarbonImmutable::instance($nowUtc)->utc();
        }
        if ($nowUtc instanceof DateTimeInterface) {
            return CarbonImmutable::instance($nowUtc)->utc();
        }

        return CarbonImmutable::now('UTC');
    }
}
