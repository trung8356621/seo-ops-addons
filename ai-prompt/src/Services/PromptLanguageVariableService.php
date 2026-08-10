<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\WordPress\Services\SitePolylangService;

/**
 * Biến global {{language}} — tên ngôn ngữ tiếng Anh (Vietnamese, English, …).
 */
final class PromptLanguageVariableService
{
    public const NAME = 'language';

    /**
     * @return array<string, string> slug => label
     */
    public static function defaultLanguageSlugOptions(): array
    {
        $polylang = app(SitePolylangService::class);
        $slugs = ['vi', 'en', 'en-gb', 'fr', 'de', 'es', 'ja', 'ko', 'zh', 'th', 'id'];

        $options = [];
        foreach ($slugs as $slug) {
            $options[$slug] = $polylang->languageEnglishName($slug).' ('.$slug.')';
        }

        return $options;
    }

    public function resolve(?Site $site, ?string $articleLanguageSlug = null): string
    {
        $polylang = app(SitePolylangService::class);
        $slug = trim((string) $articleLanguageSlug);

        if ($slug !== '') {
            return $polylang->languageEnglishName($slug);
        }

        if ($site instanceof Site && $polylang->isPolylangEnabledForSite($site)) {
            return $polylang->languageEnglishName($polylang->defaultLanguageSlugForSite($site));
        }

        return $polylang->languageEnglishName(
            app(SeoPromptSettingsService::class)->getDefaultPromptLanguageSlug(),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function mergeInto(array $variables): array
    {
        if (trim((string) ($variables[self::NAME] ?? '')) !== '') {
            return $variables;
        }

        $site = null;
        $articleSlug = trim((string) ($variables['article_language'] ?? ''));

        $articleId = (int) ($variables['article_id'] ?? 0);
        if ($articleId > 0) {
            $article = SeoArticle::query()->with('site')->find($articleId);
            if ($article instanceof SeoArticle) {
                if ($articleSlug === '') {
                    $articleSlug = trim((string) ($article->language ?? ''));
                }
                $site = $article->site;
            }
        }

        if (! $site instanceof Site) {
            $siteId = SeoAccessControl::globalSiteId();
            if ($siteId !== null && $siteId > 0) {
                $site = Site::query()->find($siteId);
            }
        }

        $variables[self::NAME] = $this->resolve(
            $site instanceof Site ? $site : null,
            $articleSlug !== '' ? $articleSlug : null,
        );

        return $variables;
    }
}
