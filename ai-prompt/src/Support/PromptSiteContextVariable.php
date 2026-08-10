<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptLanguageVariableService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use App\Models\Site;

/**
 * Biến gắn domain (Technical SEO + Prompt settings) — tự điền theo tên miền chọn trên header.
 */
final class PromptSiteContextVariable
{
    public const POST_TYPE_FIELD = '_test_post_type';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'site_domain',
            'site_company_short_identity',
            'site_website_type',
            'site_short_description',
            'site_cta',
            'site_links',
            'tone',
            'article_length',
            'keyword_density',
            'article_length_product',
            'article_length_default',
            'keyword_density_product',
            'keyword_density_default',
            'language',
        ];
    }

    public static function isName(string $name): bool
    {
        return in_array(trim($name), self::names(), true);
    }

    public static function usesInPrompt(SeoPrompt $prompt): bool
    {
        foreach (PromptResource::extractVariableNamesFromMarkdown((string) ($prompt->markdown_content ?? '')) as $name) {
            if (self::isName($name)) {
                return true;
            }
        }

        foreach (is_array($prompt->variables) ? $prompt->variables : [] as $row) {
            if (is_array($row) && self::isName((string) ($row['name'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function resolveForSite(?Site $site, ?string $postType = 'article', ?string $articleLanguageSlug = null): array
    {
        $postType = trim((string) $postType);
        if ($postType === '') {
            $postType = 'article';
        }

        $promptSettings = app(SeoPromptSettingsService::class);
        $siteContext = app(SiteDomainPromptContextService::class);

        $variables = array_merge(
            $promptSettings->promptVariables($postType),
            $siteContext->promptVariablesForSite($site),
        );
        $variables['tone'] = $siteContext->resolveToneForSite($site, $variables['tone'] ?? '');
        $variables[PromptLanguageVariableService::NAME] = app(PromptLanguageVariableService::class)->resolve(
            $site,
            $articleLanguageSlug,
        );

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, string>
     */
    public static function resolveForGlobalSite(?string $postType = null, array $variables = []): array
    {
        $siteId = SeoAccessControl::globalSiteId();
        $site = $siteId !== null && $siteId > 0
            ? Site::query()->find($siteId)
            : null;

        if ($postType === null) {
            $postType = 'article';
        }

        return self::resolveForSite(
            $site instanceof Site ? $site : null,
            $postType,
            self::resolveArticleLanguageSlug($variables),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private static function resolveArticleLanguageSlug(array $variables): ?string
    {
        $slug = trim((string) ($variables['article_language'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        $articleId = (int) ($variables['article_id'] ?? 0);
        if ($articleId <= 0) {
            return null;
        }

        $article = \Omnichannel\Addons\Content\Models\SeoArticle::query()->find($articleId);
        if (! $article instanceof \Omnichannel\Addons\Content\Models\SeoArticle) {
            return null;
        }

        $slug = trim((string) ($article->language ?? ''));

        return $slug !== '' ? $slug : null;
    }

    /**
     * Ghi đè biến site/settings — dùng khi chạy thử / compile.
     *
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    public static function mergeInto(array $variables, ?string $postType = null): array
    {
        if ($postType === null) {
            $postType = trim((string) ($variables[self::POST_TYPE_FIELD] ?? 'article'));
            if ($postType === '') {
                $postType = 'article';
            }
        }

        $resolved = self::resolveForGlobalSite($postType, $variables);

        foreach ($resolved as $key => $value) {
            if ($key === PromptLanguageVariableService::NAME && trim((string) ($variables[$key] ?? '')) !== '') {
                continue;
            }

            $variables[$key] = $value;
        }

        return $variables;
    }
}
