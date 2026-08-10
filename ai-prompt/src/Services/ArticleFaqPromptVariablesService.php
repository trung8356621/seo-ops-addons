<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Illuminate\Support\Str;

/**
 * Biến prompt FAQ: input = tiêu đề bài, site_short_description = mô tả bài, tone = site.
 */
final class ArticleFaqPromptVariablesService
{
    public function __construct(
        private readonly SiteDomainPromptContextService $sitePromptContext,
        private readonly SeoPromptSettingsService $promptSettings,
    ) {
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public function buildForArticle(SeoArticle $article, array $extra = []): array
    {
        $article->loadMissing(['site', 'articleMetas', 'faqs']);

        $postTitle = trim((string) ($article->title ?? ''));
        $bodyPlain = trim(strip_tags((string) ($article->body ?? '')));
        $postType = ArticlePostTypeResolver::resolve($article);
        $promptVars = $this->promptSettings->promptVariables($postType);

        $variables = array_merge(
            [
                'input' => $postTitle,
                'post_title' => $postTitle,
                'post_content' => Str::limit($bodyPlain, 8000),
                'site_domain' => trim((string) ($article->site?->domain ?? '')),
                'site_short_description' => $this->resolveArticleDescription($article),
            ],
            $promptVars,
            $this->sitePromptContext->promptVariablesForSite($article->site),
            $extra,
        );

        // Mô tả bài viết (không dùng mô tả ngắn domain từ promptVariablesForSite).
        $variables['site_short_description'] = $this->resolveArticleDescription($article);
        $variables['input'] = $postTitle;
        $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
            $article->site,
            $promptVars['tone'] ?? '',
        );

        return $variables;
    }

    private function resolveArticleDescription(SeoArticle $article): string
    {
        $article->loadMissing('articleMetas');

        foreach (['meta_description', 'seo_meta_description', '_yoast_wpseo_metadesc', 'rank_math_description'] as $key) {
            $value = trim((string) ($article->articleMetas->firstWhere('meta_key', $key)?->meta_value ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) ($article->excerpt ?? ''));
    }
}
