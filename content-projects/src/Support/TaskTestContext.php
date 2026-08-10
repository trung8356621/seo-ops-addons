<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;

final class TaskTestContext
{
    /**
     * @param  array<string, string>  $variables
     */
    public function __construct(
        public readonly ?SeoArticle $article,
        public readonly bool $isNewArticle,
        public readonly ?string $matchedBy,
        public readonly array $variables,
        public readonly string $summary,
        public readonly ?int $siteId = null,
        public readonly ?string $postType = null,
        public readonly ?string $projectTaskType = null,
        public readonly ?string $rewriteMode = null,
        public readonly ?string $rewriteNotes = null,
    ) {}

    public function withProjectTaskType(string $projectTaskType): self
    {
        return new self(
            article: $this->article,
            isNewArticle: $this->isNewArticle,
            matchedBy: $this->matchedBy,
            variables: $this->variables,
            summary: $this->summary,
            siteId: $this->siteId,
            postType: $this->postType,
            projectTaskType: $projectTaskType,
            rewriteMode: $this->rewriteMode,
            rewriteNotes: $this->rewriteNotes,
        );
    }

    public function withRewriteOptions(?string $rewriteMode, ?string $rewriteNotes = null): self
    {
        return new self(
            article: $this->article,
            isNewArticle: $this->isNewArticle,
            matchedBy: $this->matchedBy,
            variables: $this->variables,
            summary: $this->summary,
            siteId: $this->siteId,
            postType: $this->postType,
            projectTaskType: $this->projectTaskType,
            rewriteMode: $rewriteMode,
            rewriteNotes: $rewriteNotes,
        );
    }

    public function withVariables(array $variables): self
    {
        return new self(
            article: $this->article,
            isNewArticle: $this->isNewArticle,
            matchedBy: $this->matchedBy,
            variables: $variables,
            summary: $this->summary,
            siteId: $this->siteId,
            postType: $this->postType,
            projectTaskType: $this->projectTaskType,
            rewriteMode: $this->rewriteMode,
            rewriteNotes: $this->rewriteNotes,
        );
    }

    public function withSiteId(int $siteId): self
    {
        return new self(
            article: $this->article,
            isNewArticle: $this->isNewArticle,
            matchedBy: $this->matchedBy,
            variables: $this->variables,
            summary: $this->summary,
            siteId: $siteId,
            postType: $this->postType,
            projectTaskType: $this->projectTaskType,
            rewriteMode: $this->rewriteMode,
            rewriteNotes: $this->rewriteNotes,
        );
    }

    public function withPostType(?string $postType): self
    {
        return new self(
            article: $this->article,
            isNewArticle: $this->isNewArticle,
            matchedBy: $this->matchedBy,
            variables: $this->variables,
            summary: $this->summary,
            siteId: $this->siteId,
            postType: $postType,
            projectTaskType: $this->projectTaskType,
            rewriteMode: $this->rewriteMode,
            rewriteNotes: $this->rewriteNotes,
        );
    }

    public function withArticle(?SeoArticle $article, bool $isNewArticle = false, ?string $matchedBy = null): self
    {
        return new self(
            article: $article,
            isNewArticle: $isNewArticle,
            matchedBy: $matchedBy ?? $this->matchedBy,
            variables: $this->variables,
            summary: $this->summary,
            siteId: $this->siteId,
            postType: $this->postType,
            projectTaskType: $this->projectTaskType,
            rewriteMode: $this->rewriteMode,
            rewriteNotes: $this->rewriteNotes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'article_id' => $this->article?->id,
            'is_new_article' => $this->isNewArticle,
            'matched_by' => $this->matchedBy,
            'summary' => $this->summary,
            'variables' => $this->variables,
            'site_id' => $this->siteId,
            'post_type' => $this->postType,
            'project_task_type' => $this->projectTaskType,
            'rewrite_mode' => $this->rewriteMode,
            'rewrite_notes' => $this->rewriteNotes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $article = null;
        $articleId = $data['article_id'] ?? null;

        if (is_numeric($articleId) && (int) $articleId > 0) {
            $article = SeoArticle::query()->find((int) $articleId);
        }

        $variables = is_array($data['variables'] ?? null) ? $data['variables'] : [];
        $normalizedVariables = [];
        foreach ($variables as $key => $value) {
            $normalizedVariables[(string) $key] = is_string($value) ? $value : (string) $value;
        }

        return new self(
            article: $article,
            isNewArticle: (bool) ($data['is_new_article'] ?? false),
            matchedBy: is_string($data['matched_by'] ?? null) ? $data['matched_by'] : null,
            variables: $normalizedVariables,
            summary: (string) ($data['summary'] ?? ''),
            siteId: is_numeric($data['site_id'] ?? null) ? (int) $data['site_id'] : null,
            postType: is_string($data['post_type'] ?? null) ? $data['post_type'] : null,
            projectTaskType: is_string($data['project_task_type'] ?? null) ? $data['project_task_type'] : null,
            rewriteMode: is_string($data['rewrite_mode'] ?? null) ? $data['rewrite_mode'] : null,
            rewriteNotes: is_string($data['rewrite_notes'] ?? null) ? $data['rewrite_notes'] : null,
        );
    }
}
