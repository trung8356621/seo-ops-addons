<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

/**
 * Domain-level primary content language (site meta seo_primary_language).
 *
 * Canonical effective language precedence:
 * 1. Explicit saved primary when valid for current Polylang languages
 * 2. Polylang default (when Polylang active)
 * 3. Sole Polylang language (when Polylang active with one language)
 * 4. WordPress site locale (when Polylang absent)
 * 5. unresolved
 */
final class SitePrimaryLanguageService
{
    public const META_KEY = 'seo_primary_language';

    public function hasPolylang(Site $site): bool
    {
        return app(SitePolylangService::class)->isPolylangEnabledForSite($site);
    }

    /**
     * @return array<string, string> slug => label — synced Polylang languages only; empty if none
     */
    public function syncedLanguageOptions(Site $site): array
    {
        $info = app(WordPressSiteInfoService::class)->getStoredSiteInfo($site);
        if (! is_array($info)) {
            return [];
        }

        $polylang = $info['polylang'] ?? null;
        if (! is_array($polylang) || ($polylang['active'] ?? false) !== true) {
            return [];
        }

        $languages = is_array($polylang['languages'] ?? null)
            ? $polylang['languages']
            : [];

        $options = [];
        foreach ($languages as $language) {
            if (! is_array($language)) {
                continue;
            }

            $slug = ArticleLanguageCode::normalize((string) ($language['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $name = trim((string) ($language['name'] ?? $slug));
            $options[$slug] = ArticleLanguageCode::defaultLabels()[$slug]
                ?? ($name !== '' ? $name : $slug);
        }

        return $options;
    }

    /**
     * Options for Domain Edit select: Polylang languages, or a single WP-locale option when no Polylang.
     *
     * @return array<string, string>
     */
    public function formLanguageOptions(Site $site): array
    {
        if ($this->hasPolylang($site)) {
            return $this->syncedLanguageOptions($site);
        }

        $code = $this->languageFromWordpressLocale($site);
        if ($code === null) {
            return [];
        }

        $label = ArticleLanguageCode::label($code);
        $suffix = (string) __('seo-content-ai::filament.domain.primary_language_wordpress_suffix');

        return [
            $code => trim($label.($suffix !== '' ? ' '.$suffix : '')),
        ];
    }

    public function getStoredPrimaryLanguage(Site $site): ?string
    {
        $raw = $site->getMeta(self::META_KEY);
        $normalized = ArticleLanguageCode::normalize(is_string($raw) ? $raw : '');

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Raw WordPress locale from synced site_info (e.g. vi_VN), or null.
     */
    public function wordpressLocale(Site $site): ?string
    {
        $info = app(WordPressSiteInfoService::class)->getStoredSiteInfo($site);
        if (! is_array($info)) {
            return null;
        }

        $locale = trim((string) ($info['locale'] ?? $info['wordpress_locale'] ?? ''));

        return $locale !== '' ? $locale : null;
    }

    public function languageFromWordpressLocale(Site $site): ?string
    {
        $code = ArticleLanguageCode::fromWordpressLocale($this->wordpressLocale($site));

        return $code !== '' ? $code : null;
    }

    /**
     * Canonical effective primary language for planners / AI / SEO Audit.
     */
    public function resolvePrimaryLanguage(Site $site): ?string
    {
        if ($this->hasPolylang($site)) {
            $options = $this->syncedLanguageOptions($site);
            $stored = $this->getStoredPrimaryLanguage($site);
            if ($stored !== null && isset($options[$stored])) {
                return $stored;
            }

            return $this->polylangFallbackLanguage($site, $options);
        }

        // Backward compatibility: keep an explicit stored code on non-Polylang sites when present.
        $stored = $this->getStoredPrimaryLanguage($site);
        if ($stored !== null) {
            return $stored;
        }

        return $this->languageFromWordpressLocale($site);
    }

    /**
     * Candidate when stored is empty: Polylang default if synced, else sole synced language.
     * Does not write. Does not apply WP locale (non-Polylang sites must not seed meta).
     */
    public function seedCandidate(Site $site): ?string
    {
        if (! $this->hasPolylang($site)) {
            return null;
        }

        if ($this->getStoredPrimaryLanguage($site) !== null) {
            return null;
        }

        return $this->polylangFallbackLanguage($site, $this->syncedLanguageOptions($site));
    }

    /**
     * Write meta once when empty and a Polylang seed candidate exists. Never overwrites stored value.
     * Never seeds from WordPress locale.
     */
    public function seedIfEmpty(Site $site): ?string
    {
        if ($this->getStoredPrimaryLanguage($site) !== null) {
            return $this->getStoredPrimaryLanguage($site);
        }

        $candidate = $this->seedCandidate($site);
        if ($candidate === null) {
            return null;
        }

        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => $candidate],
        );

        return $candidate;
    }

    /**
     * @throws ValidationException when non-empty code is not in synced options
     */
    public function setPrimaryLanguage(Site $site, ?string $code): void
    {
        if (! $this->hasPolylang($site)) {
            // Non-Polylang sites derive language from WP locale — do not persist a fake primary.
            return;
        }

        if ($code === null || trim($code) === '') {
            $site->metas()->updateOrCreate(
                ['meta_key' => self::META_KEY],
                ['meta_value' => ''],
            );

            return;
        }

        $normalized = ArticleLanguageCode::normalize($code);
        $options = $this->syncedLanguageOptions($site);

        if ($normalized === '' || ! isset($options[$normalized])) {
            throw ValidationException::withMessages([
                'seo_primary_language' => __('seo-content-ai::filament.domain.primary_language_invalid'),
            ]);
        }

        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => $normalized],
        );
    }

    public function primaryLanguageLabel(Site $site, ?string $code = null): ?string
    {
        $resolved = $code ?? $this->resolvePrimaryLanguage($site);
        if ($resolved === null || $resolved === '') {
            return null;
        }

        $options = $this->hasPolylang($site)
            ? $this->syncedLanguageOptions($site)
            : $this->formLanguageOptions($site);

        return $options[$resolved] ?? ArticleLanguageCode::label($resolved);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function polylangFallbackLanguage(Site $site, array $options): ?string
    {
        if ($options === []) {
            return null;
        }

        $info = app(WordPressSiteInfoService::class)->getStoredSiteInfo($site);
        $polylang = is_array($info) ? ($info['polylang'] ?? null) : null;
        $default = is_array($polylang)
            ? ArticleLanguageCode::normalize((string) ($polylang['default'] ?? ''))
            : '';

        if ($default !== '' && isset($options[$default])) {
            return $default;
        }

        if (count($options) === 1) {
            return (string) array_key_first($options);
        }

        return null;
    }
}
