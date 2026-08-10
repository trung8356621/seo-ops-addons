<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos;

final class PublishingQueueItemDto
{
    public function __construct(
        public readonly string $itemRef,
        public readonly string $projectRef,
        public readonly string $queueStatus,
        public readonly ?string $scheduledPublishAt,
        public readonly int $retryCount,
        public readonly ?string $lastAttemptAt,
        public readonly ?string $lastError,
        public readonly ?string $publishedAt,
        public readonly string $lifecycle,
        public readonly ?string $title,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'item_ref' => $this->itemRef,
            'project_ref' => $this->projectRef,
            'queue_status' => $this->queueStatus,
            'scheduled_publish_at' => $this->scheduledPublishAt,
            'retry_count' => $this->retryCount,
            'last_attempt_at' => $this->lastAttemptAt,
            'last_error' => $this->lastError,
            'published_at' => $this->publishedAt,
            'lifecycle' => $this->lifecycle,
            'title' => $this->title,
        ];
    }
}
