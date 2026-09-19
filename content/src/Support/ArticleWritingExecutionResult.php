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
            'warning_code' => $this->historyMetadata['warning_code'] ?? null,
            'warning_message' => $this->historyMetadata['warning_message'] ?? null,
            'length_validation' => is_array($this->historyMetadata['length_validation'] ?? null)
                ? $this->historyMetadata['length_validation']
                : array_filter([
                    'actual_words' => $this->historyMetadata['actual_words'] ?? $this->historyMetadata['actual_word_count'] ?? null,
                    'target_words' => $this->historyMetadata['target_words'] ?? $this->historyMetadata['target_article_length'] ?? null,
                    'hard_floor_words' => $this->historyMetadata['hard_floor_words'] ?? $this->historyMetadata['minimum_acceptable_words'] ?? null,
                    'length_validation_result' => $this->historyMetadata['length_validation_result'] ?? null,
                    'outcome' => $this->historyMetadata['outcome'] ?? null,
                    'warning_code' => $this->historyMetadata['warning_code'] ?? null,
                    'warning_message' => $this->historyMetadata['warning_message'] ?? null,
                ], static fn (mixed $v): bool => $v !== null && $v !== ''),
        ];
    }
}
