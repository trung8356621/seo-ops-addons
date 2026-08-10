<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Snapshot execution đang active cho một task/article trong run.
 */
final class ContentProjectActiveExecution
{
    public function __construct(
        public readonly int $runItemId,
        public readonly int $runId,
        public readonly int $taskId,
        public readonly ?int $articleId,
        public readonly string $action,
        public readonly ?string $nodeId,
        public readonly string $status,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?string $lockKey = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_item_id' => $this->runItemId,
            'execution_id' => $this->runItemId,
            'run_id' => $this->runId,
            'task_id' => $this->taskId,
            'article_id' => $this->articleId,
            'action' => $this->action,
            'node_id' => $this->nodeId,
            'status' => $this->status,
            'active_flag' => null,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'lock_key' => $this->lockKey,
        ];
    }
}
