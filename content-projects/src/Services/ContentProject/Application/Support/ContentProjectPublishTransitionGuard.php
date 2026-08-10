<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use RuntimeException;

/**
 * Publish queue lifecycle — map ContentProjectPublishQueueStatus.
 *
 * none≈unscheduled, waiting≈scheduled, queued_for_delivery≈awaiting worker,
 * processing≈publisher started, retrying≈retry_wait.
 */
final class ContentProjectPublishTransitionGuard
{
    private const ALLOWED = [
        'none' => [
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::QueuedForDelivery,
            ContentProjectPublishQueueStatus::Processing,
        ],
        'waiting' => [
            ContentProjectPublishQueueStatus::QueuedForDelivery,
            ContentProjectPublishQueueStatus::Processing,
            ContentProjectPublishQueueStatus::Cancelled,
            ContentProjectPublishQueueStatus::Skipped,
            ContentProjectPublishQueueStatus::None,
        ],
        'queued_for_delivery' => [
            ContentProjectPublishQueueStatus::Processing,
            ContentProjectPublishQueueStatus::Published,
            ContentProjectPublishQueueStatus::Failed,
            ContentProjectPublishQueueStatus::Retrying,
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::None,
            ContentProjectPublishQueueStatus::Cancelled,
        ],
        'processing' => [
            ContentProjectPublishQueueStatus::Published,
            ContentProjectPublishQueueStatus::Failed,
            ContentProjectPublishQueueStatus::Retrying,
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::QueuedForDelivery,
            ContentProjectPublishQueueStatus::None,
        ],
        'failed' => [
            ContentProjectPublishQueueStatus::Retrying,
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::QueuedForDelivery,
            ContentProjectPublishQueueStatus::Cancelled,
            ContentProjectPublishQueueStatus::Skipped,
            ContentProjectPublishQueueStatus::None,
        ],
        'retrying' => [
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::QueuedForDelivery,
            ContentProjectPublishQueueStatus::Processing,
            ContentProjectPublishQueueStatus::Cancelled,
            ContentProjectPublishQueueStatus::None,
        ],
        'cancelled' => [
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::None,
        ],
        'skipped' => [
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::None,
        ],
        'published' => [
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::QueuedForDelivery,
        ],
    ];

    public function assertCanTransition(
        ContentProjectPublishQueueStatus|string|null $from,
        ContentProjectPublishQueueStatus|string $to,
    ): void {
        $fromStatus = $this->normalize($from);
        $toStatus = $this->normalize($to);

        if ($fromStatus === $toStatus) {
            return;
        }

        $fromKey = $fromStatus->value;
        $allowed = self::ALLOWED[$fromKey] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'lifecycle.invalid_transition: %s → %s',
                $fromKey,
                $toStatus->value,
            ));
        }
    }

    private function normalize(ContentProjectPublishQueueStatus|string|null $status): ContentProjectPublishQueueStatus
    {
        if ($status instanceof ContentProjectPublishQueueStatus) {
            return $status;
        }

        $raw = trim((string) ($status ?? ''));

        if ($raw === '') {
            return ContentProjectPublishQueueStatus::None;
        }

        return ContentProjectPublishQueueStatus::from($raw);
    }
}
