<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Entities;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptHookEntityResolverContract;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Normalize article fields cho Prompt Hook — ẩn chi tiết article_meta / keyword join.
 */
final class ArticlePromptHookEntityResolver implements PromptHookEntityResolverContract
{
    private ?SeoArticle $lastArticle = null;

    public function __construct(
        private readonly SeoAnalyzerService $seoAnalyzer,
    ) {}

    public function key(): string
    {
        return 'article';
    }

    public function lastArticle(): ?SeoArticle
    {
        return $this->lastArticle;
    }

    public function loadAuthorized(int $articleId): SeoArticle
    {
        if ($articleId <= 0) {
            throw new PromptHookException(
                PromptHookErrorCode::HookInputInvalid,
                'article_id is required.',
            );
        }

        $article = SeoArticle::query()->find($articleId);
        if ($article === null) {
            throw new PromptHookException(
                PromptHookErrorCode::HookArticleNotFound,
                'Article not found.',
            );
        }

        if (! SeoAccessControl::canAccessArticle($article)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookArticleForbidden,
                'You do not have access to this article.',
            );
        }

        $article->loadMissing(['articleMetas', 'site']);
        $this->lastArticle = $article;

        return $article;
    }

    public function resolveContext(int $entityId): array
    {
        $article = $this->loadAuthorized($entityId);

        return $this->buildContext($article);
    }

    /**
     * @return array{article: array<string, mixed>}
     */
    public function buildContext(SeoArticle $article): array
    {
        $article->loadMissing(['articleMetas']);

        $title = trim((string) ($article->title ?? ''));
        $focusKeyword = $this->seoAnalyzer->resolveFocusKeywordForArticle($article);
        $focusKeyword = is_string($focusKeyword) ? trim($focusKeyword) : '';
        if ($focusKeyword === '') {
            $focusKeyword = null;
        }

        $description = trim($this->seoAnalyzer->resolveMetaDescriptionForArticle($article));
        if ($description === '') {
            $description = null;
        }

        return [
            'article' => [
                'id' => (int) $article->id,
                'title' => $title !== '' ? $title : null,
                'focus_keyword' => $focusKeyword,
                'keyword' => $focusKeyword,
                'description' => $description,
            ],
        ];
    }
}
