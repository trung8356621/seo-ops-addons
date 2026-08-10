<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

/**
 * Kết quả chuẩn cho mọi hành động Article AI History (preview/apply/delete/undo/commit).
 *
 * `metadata` nên mang các khoá agent-friendly khi có: artifact_id, artifact_type, run_id,
 * attempt, article_id, action, applied_target, draft_dirty, provenance, operation_id.
 */
final class ArticleAiHistoryActionResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $articleId = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function ok(
        string $code,
        string $message,
        ?int $articleId = null,
        array $metadata = [],
    ): self {
        return new self(
            success: true,
            code: $code,
            message: $message,
            articleId: $articleId,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function fail(
        string $code,
        string $message,
        ?int $articleId = null,
        array $metadata = [],
    ): self {
        return new self(
            success: false,
            code: $code,
            message: $message,
            articleId: $articleId,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'code' => $this->code,
            'message' => $this->message,
            'article_id' => $this->articleId,
            'metadata' => $this->metadata,
        ];
    }
}
