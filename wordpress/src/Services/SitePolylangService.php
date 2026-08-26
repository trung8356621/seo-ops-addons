<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;

final class SitePolylangService
{
    /**
     * @return array<string, string> slug => label
     */
    public function defaultLanguageOptions(): array
    {
        return ArticleLanguageCode::defaultLabels();
    }

    public function isPolylangEnabledForSite(?Site $site): bool
    {
        if (! $site instanceof Site) {
            return false;
        }

        $info = app(WordPressSiteInfoService::class)->getStoredSiteInfo($site);
        if (! is_array($info)) {
            return false;
        }

        $polylang = $info['polylang'] ?? null;

        return is_array($polylang) && ($polylang['active'] ?? false) === true;
    }

    /**
     * @return array<string, string> slug => label
     */
    public function languageOptionsForSite(?Site $site): array
    {
        if (! $this->isPolylangEnabledForSite($site)) {
            return $this->defaultLanguageOptions();
        }

        $info = app(WordPressSiteInfoService::class)->getStoredSiteInfo($site);
        $polylang = is_array($info) ? ($info['polylang'] ?? null) : null;
        $languages = is_array($polylang) && is_array($polylang['languages'] ?? null)
            ? $polylang['languages']
            : [];

        $options = [];
        foreach ($languages as $language) {
            if (! is_array($language)) {
                continue;
            }

            $slug = ArticleLanguageCode::normalize((string) ($language['slug'] ?? ''));
            if ($slug === '') {
                $slug = ArticleLanguageCode::fromWordpressLocale((string) ($language['locale'] ?? ''));
            }
            if ($slug === '') {
                continue;
            }

            if (isset($options[$slug])) {
                continue;
            }

            $name = trim((string) ($language['name'] ?? $slug));
            // Prefer Content default label for known codes; keep Polylang name only as fallback.
            $options[$slug] = ArticleLanguageCode::defaultLabels()[$slug]
                ?? ($name !== '' ? $name : $slug);
        }

        return $options !== [] ? $options : $this->defaultLanguageOptions();
    }

    public function languageLabel(string $slug, ?Site $site = null): string
    {
        $code = ArticleLanguageCode::normalize($slug);
        if ($code === '') {
            return '—';
        }

        $options = $site instanceof Site
            ? $this->languageOptionsForSite($site)
            : $this->defaultLanguageOptions();

        return $options[$code] ?? ArticleLanguageCode::label($code);
    }

    public function defaultLanguageSlugForSite(?Site $site): string
    {
        if (! $site instanceof Site) {
            return 'vi';
        }

        $info = app(WordPressSiteInfoService::class)->getStoredSiteInfo($site);
        $polylang = is_array($info) ? ($info['polylang'] ?? null) : null;
        if (! is_array($polylang)) {
            return 'vi';
        }

        $default = ArticleLanguageCode::normalize((string) ($polylang['default'] ?? ''));
        if ($default === '') {
            $default = ArticleLanguageCode::fromWordpressLocale((string) ($polylang['default'] ?? ''));
        }

        return $default !== '' ? $default : 'vi';
    }

    public function isDefaultLanguage(string $slug, ?Site $site = null): bool
    {
        $code = ArticleLanguageCode::normalize($slug);
        if ($code === '') {
            return false;
        }

        return $code === $this->defaultLanguageSlugForSite($site);
    }

    public function languageEnglishName(string $slug): string
    {
        $slug = ArticleLanguageCode::normalize($slug);
        if ($slug === '') {
            return 'Unknown';
        }

        return match ($slug) {
            'vi' => 'Vietnamese',
            'en' => 'English',
            'en-gb' => 'English (United Kingdom)',
            'fr' => 'French',
            'de' => 'German',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt', 'pt-br' => 'Portuguese',
            'pt-pt' => 'Portuguese (Portugal)',
            'ja', 'jp' => 'Japanese',
            'ko', 'kr' => 'Korean',
            'zh', 'zh-cn', 'cn' => 'Chinese (Simplified)',
            'zh-tw' => 'Chinese (Traditional)',
            'th' => 'Thai',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'ru' => 'Russian',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'tr' => 'Turkish',
            default => ucfirst(str_replace(['-', '_'], ' ', $slug)),
        };
    }

    public function languageFlagEmoji(string $slug): string
    {
        return match (ArticleLanguageCode::normalize($slug)) {
            'en', 'en-us', 'en-gb' => '🇺🇸',
            'vi' => '🇻🇳',
            'fr' => '🇫🇷',
            'de' => '🇩🇪',
            'ja', 'jp' => '🇯🇵',
            'ko', 'kr' => '🇰🇷',
            'zh', 'zh-cn', 'cn' => '🇨🇳',
            default => '🌐',
        };
    }

    public function anyAccessibleSiteHasPolylang(): bool
    {
        $query = Site::query()->whereHas('metas', function ($metaQuery): void {
            $metaQuery
                ->where('meta_key', WordPressSiteInfoService::META_PLUGIN_INFO)
                ->where('meta_value', 'like', '%"polylang"%')
                ->where('meta_value', 'like', '%"active":true%');
        });

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query->exists();
    }
}
