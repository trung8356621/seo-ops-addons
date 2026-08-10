<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

/**
 * Quyết định Generate-pending cho một project item.
 */
final class ContentProjectItemGenerationDecision
{
    public const ACTION_RUN = 'run';

    public const ACTION_SKIP = 'skip';

    public const ACTION_ANOMALY = 'anomaly';

    /**
     * @param  list<string>  $evidence
     */
    public function __construct(
        public readonly int $taskId,
        public readonly string $action,
        public readonly string $reason,
        public readonly string $taskStatus,
        public readonly string $itemType,
        public readonly array $evidence = [],
        public readonly ?string $keyword = null,
        public readonly ?int $articleId = null,
    ) {}

    public function shouldRun(): bool
    {
        return $this->action === self::ACTION_RUN;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'action' => $this->action,
            'reason' => $this->reason,
            'task_status' => $this->taskStatus,
            'item_type' => $this->itemType,
            'evidence' => $this->evidence,
            'keyword' => $this->keyword,
            'article_id' => $this->articleId,
        ];
    }
}
