<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;

/**
 * Typed contract cho capability article.content.generate (outline / existing_article / brief).
 * Caller phải truyền sourceType rõ — không suy đoán từ nội dung.
 */
final class ArticleWritingInput
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly ArticleWritingSourceType $sourceType,
        public readonly string $input,
        public readonly string $title = '',
        public readonly string $keyword = '',
        public readonly string $description = '',
        public readonly ?int $articleId = null,
        public readonly ?int $runId = null,
        public readonly ?int $runItemId = null,
        public readonly ?int $sourcePromptResultId = null,
        public readonly array $metadata = [],
    ) {}

    public function sourceHash(): string
    {
        return hash('sha256', implode("\n", [
            $this->sourceType->value,
            $this->input,
            $this->title,
            $this->keyword,
            $this->description,
        ]));
    }

    /**
     * Metadata lưu PromptResult / retry snapshot.
     *
     * @return array<string, mixed>
     */
    public function historyMetadata(): array
    {
        return [
            'article_writing_source_type' => $this->sourceType->value,
            'source_type' => $this->sourceType->value,
            'source_hash' => $this->sourceHash(),
            'source_run_id' => $this->runId,
            'source_run_item_id' => $this->runItemId,
            'source_prompt_result_id' => $this->sourcePromptResultId,
            'title' => $this->title !== '' ? $this->title : null,
            'keyword' => $this->keyword !== '' ? $this->keyword : null,
            'description_present' => $this->description !== '',
            'article_id' => $this->articleId,
            ...$this->metadata,
        ];
    }

    public static function fromOutlineArtifact(
        string $rawArtifact,
        string $title = '',
        string $keyword = '',
        string $description = '',
        ?int $articleId = null,
        ?int $runId = null,
        ?int $runItemId = null,
        ?int $sourcePromptResultId = null,
        array $metadata = [],
    ): self {
        return new self(
            sourceType: ArticleWritingSourceType::Outline,
            input: $rawArtifact,
            title: $title,
            keyword: $keyword,
            description: $description,
            articleId: $articleId,
            runId: $runId,
            runItemId: $runItemId,
            sourcePromptResultId: $sourcePromptResultId,
            metadata: $metadata,
        );
    }

    public static function fromExistingArticleBody(
        string $bodyMarkdown,
        string $title = '',
        string $keyword = '',
        string $description = '',
        ?int $articleId = null,
        array $metadata = [],
    ): self {
        return new self(
            sourceType: ArticleWritingSourceType::ExistingArticle,
            input: $bodyMarkdown,
            title: $title,
            keyword: $keyword,
            description: $description,
            articleId: $articleId,
            metadata: $metadata,
        );
    }

    public static function fromBrief(
        string $title = '',
        string $keyword = '',
        string $description = '',
        ?int $articleId = null,
        array $metadata = [],
        string $freeInput = '',
    ): self {
        return new self(
            sourceType: ArticleWritingSourceType::Brief,
            input: trim($freeInput),
            title: $title,
            keyword: $keyword,
            description: $description,
            articleId: $articleId,
            metadata: $metadata,
        );
    }
}
