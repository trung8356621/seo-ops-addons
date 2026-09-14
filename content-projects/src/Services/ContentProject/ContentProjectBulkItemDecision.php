<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

/**
 * JIT decision for one bulk-membership item at execution time.
 * Not an execution plan — decided only when the item becomes current.
 */
final class ContentProjectBulkItemDecision
{
    public const OP_GENERATE_NEW = 'generate_new';

    public const OP_RESUME_FROM_FAILED_STEP = 'resume_from_failed_step';

    public const OP_RESTART_WITH_KEYWORD = 'restart_with_keyword';

    public const OP_SKIP = 'skip';

    /**
     * @param  array<string, mixed>  $executionSettings  Ephemeral run-setting overlays for this item only.
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly int $taskId,
        public readonly string $operation,
        public readonly string $reason,
        public readonly array $executionSettings = [],
        public readonly array $meta = [],
    ) {}

    public function shouldExecute(): bool
    {
        return $this->operation !== self::OP_SKIP;
    }

    public function isSkip(): bool
    {
        return $this->operation === self::OP_SKIP;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'operation' => $this->operation,
            'reason' => $this->reason,
            'execution_settings' => $this->executionSettings,
            'meta' => $this->meta,
        ];
    }
}
