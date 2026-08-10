<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

final class ArticleImproveExecutionResult
{
    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $historyMetadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?int $articleId = null,
        public readonly ?int $promptId = null,
        public readonly ?string $persistStatus = null,
        public readonly array $steps = [],
        public readonly array $historyMetadata = [],
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
            'prompt_id' => $this->promptId,
            'hook_key' => 'article.content.improve',
        ];
    }
}
