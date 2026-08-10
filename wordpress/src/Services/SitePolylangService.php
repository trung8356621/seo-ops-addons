<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;

final class SitePolylangService
{
    /**
     * @return array<string, string> slug => label
     */
    public function defaultLanguageOptions(): array
    {
        return [
            'vi' => 'Tiếng Việt',
            'en' => 'English',
        ];
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

            $slug = trim((string) ($language['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $name = trim((string) ($language['name'] ?? $slug));
            $options[$slug] = $name !== '' ? $name : $slug;
        }

        return $options !== [] ? $options : $this->defaultLanguageOptions();
    }

    public function languageLabel(string $slug, ?Site $site = null): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '—';
        }

        $options = $site instanceof Site
            ? $this->languageOptionsForSite($site)
            : $this->defaultLanguageOptions();

        return $options[$slug] ?? strtoupper($slug);
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

        $default = trim((string) ($polylang['default'] ?? ''));

        return $default !== '' ? $default : 'vi';
    }

    public function isDefaultLanguage(string $slug, ?Site $site = null): bool
    {
        $slug = trim($slug);
        if ($slug === '') {
            return false;
        }

        return $slug === $this->defaultLanguageSlugForSite($site);
    }

    public function languageEnglishName(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return 'Unknown';
        }

        return match ($slug) {
            'vi', 'vn' => 'Vietnamese',
            'en', 'en-us' => 'English',
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
        return match (strtolower(trim($slug))) {
            'en', 'en-us', 'en-gb' => '🇺🇸',
            'vi', 'vn' => '🇻🇳',
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
