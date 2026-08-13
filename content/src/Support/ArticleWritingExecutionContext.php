<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Enums\ArticleWritingExecutionMode;
use Omnichannel\Addons\AiPrompt\Enums\ArticleWritingPromptOwnerType;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;

/**
 * Context cho ArticleWritingExecutionService — không chứa Livewire/HTTP/queue.
 */
final class ArticleWritingExecutionContext
{
    /**
     * @param  array<string, mixed>  $baseVariables
     * @param  array<string, mixed>  $retrySnapshot  Snapshot retry same execution (source/prompt/length).
     */
    public function __construct(
        public readonly ArticleWritingExecutionMode $mode,
        public readonly ArticleWritingPromptOwnerType $promptOwnerType,
        public readonly int $siteId = 0,
        public readonly ?int $promptId = null,
        public readonly ?string $promptOwnerId = null,
        public readonly ?SeoTask $workflowTask = null,
        public readonly ?string $contentNodeId = null,
        public readonly ?TaskTestContext $taskContext = null,
        public readonly bool $useRetrySnapshot = false,
        public readonly array $retrySnapshot = [],
        public readonly ?string $expectedUpdatedAt = null,
        public readonly ?string $executionToken = null,
        public readonly array $baseVariables = [],
        public readonly bool $persistArticle = true,
    ) {}

    public function assertOwnerXor(): void
    {
        if ($this->promptOwnerType === ArticleWritingPromptOwnerType::SettingsBinding
            && $this->promptId !== null
            && $this->promptId > 0
        ) {
            throw new \InvalidArgumentException(
                'Settings-owned execution không được truyền workflow prompt_id.',
            );
        }

        // WorkflowNode: prompt_id bắt buộc với ContentNode/Direct; PublishGraph có thể resolve sau từ content node.
        if ($this->promptOwnerType === ArticleWritingPromptOwnerType::WorkflowNode
            && $this->mode !== ArticleWritingExecutionMode::PublishGraph
            && ($this->promptId === null || $this->promptId <= 0)
            && trim((string) $this->contentNodeId) === ''
        ) {
            throw new \InvalidArgumentException(
                'Workflow-owned execution cần prompt_id hoặc contentNodeId.',
            );
        }
    }
}
