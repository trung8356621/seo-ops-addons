<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos;

/**
 * @phpstan-type ItemStateArray array<string, mixed>
 */
final class ContentProjectItemDto
{
    /**
     * @param  ItemStateArray|null  $state
     */
    public function __construct(
        public readonly string $itemRef,
        public readonly string $projectRef,
        public readonly ?string $articleRef,
        public readonly string $lifecycle,
        public readonly string $publishQueueStatus,
        public readonly ?string $scheduledPublishAt,
        public readonly int $publishRetryCount,
        public readonly ?string $lastPublishAttemptAt,
        public readonly ?string $lastPublishError,
        public readonly ?string $publishPublishedAt,
        public readonly ?string $title,
        public readonly ?array $state = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $row = [
            'item_ref' => $this->itemRef,
            'project_ref' => $this->projectRef,
            'article_ref' => $this->articleRef,
            'lifecycle' => $this->lifecycle,
            'publish_queue_status' => $this->publishQueueStatus,
            'scheduled_publish_at' => $this->scheduledPublishAt,
            'publish_retry_count' => $this->publishRetryCount,
            'last_publish_attempt_at' => $this->lastPublishAttemptAt,
            'last_publish_error' => $this->lastPublishError,
            'publish_published_at' => $this->publishPublishedAt,
            'title' => $this->title,
        ];

        if ($this->state !== null) {
            $row['state'] = $this->state;
            $row['current_error'] = $this->state['current_error'] ?? null;
            $row['current_error_source'] = $this->state['current_error_source'] ?? null;
            $row['available_actions'] = $this->state['available_actions'] ?? [];
        }

        return $row;
    }
}
