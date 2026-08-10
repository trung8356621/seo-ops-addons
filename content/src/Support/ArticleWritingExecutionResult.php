<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\AiPrompt\Enums\ArticleWritingPromptOwnerType;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;

final class ArticleWritingExecutionResult
{
    public const PERSIST_APPLIED = 'applied';

    public const PERSIST_IGNORED_STALE = 'ignored_stale';

    public const PERSIST_SKIPPED = 'skipped';

    public const PERSIST_FAILED = 'failed';

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $historyMetadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ArticleWritingSourceType $sourceType,
        public readonly ArticleWritingPromptOwnerType $promptOwnerType,
        public readonly string $hookKey,
        public readonly ?int $articleId = null,
        public readonly ?int $promptId = null,
        public readonly ?string $promptOwnerId = null,
        public readonly ?string $persistStatus = null,
        public readonly array $steps = [],
        public readonly array $historyMetadata = [],
        public readonly ?ArticleWritingInput $writing = null,
    ) {}

    /**
     * @return array{success: bool, article_id: ?int, message: string, steps: list<array<string, mixed>>}
     */
    public function toLegacyWorkflowArray(): array
    {
        return [
            'success' => $this->success,
            'article_id' => $this->articleId,
            'message' => $this->message,
            'steps' => $this->steps,
            'persist_status' => $this->persistStatus,
            'prompt_owner_type' => $this->promptOwnerType->value,
            'prompt_id' => $this->promptId,
            'hook_key' => $this->hookKey,
            'source_type' => $this->sourceType->value,
        ];
    }
}
