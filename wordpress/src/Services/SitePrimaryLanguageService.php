<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\Content\Support\ContentLanguageCodeNormalizer;
use Omnichannel\Addons\Content\Support\ContentLanguageRegistry;
use Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

/**
 * Domain-level primary content language (site meta seo_primary_language).
 *
 * Canonical effective language precedence:
 * 1. Explicit saved primary (normalized ISO 639-1), valid for source
 * 2. Polylang default / sole language (when Polylang active)
 * 3. Global Default Content Language (Settings → General)
 * 4. unresolved
 *
 * WordPress locale is metadata only — never used as content-language fallback.
 */
final class SitePrimaryLanguageService
{
    public const META_KEY = 'seo_primary_language';

    public function hasPolylang(Site $site): bool
    {
        return app(SitePolylangService::class)->isPolylangEnabledForSite($site);
    }

    /**
     * Synced Polylang languages → canonical code => label (deduped).
     * Allows Polylang-managed codes even when outside ContentLanguageRegistry.
     *
     * @return array<string, string>
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

            $code = $this->normalizePolylangLanguageEntry($language);
            if ($code === null) {
                continue;
            }

            if (isset($options[$code])) {
                continue;
            }

            $name = trim((string) ($language['name'] ?? ''));
            $options[$code] = ContentLanguageRegistry::isSupported($code)
                ? ContentLanguageRegistry::label($code)
                : (ArticleLanguageCode::defaultLabels()[$code]
                    ?? ($name !== '' ? $name : $code));
        }

        return $options;
    }

    /**
     * Domain Edit select options.
     * Polylang → synced only. Non-Polylang → ContentLanguageRegistry.
     *
     * @return array<string, string>
     */
    public function formLanguageOptions(Site $site): array
    {
        if ($this->hasPolylang($site)) {
            return $this->syncedLanguageOptions($site);
        }

        return ContentLanguageRegistry::selectOptions();
    }

    public function getStoredPrimaryLanguage(Site $site): ?string
    {
        $raw = $site->getMeta(self::META_KEY);
        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }

        return ContentLanguageCodeNormalizer::normalize((string) $raw);
    }

    /**
     * Raw WordPress locale from synced site_info (e.g. vi_VN), or null.
     * External metadata only — not content-language identity.
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

    /**
     * Map WP locale → language code for display/metadata helpers.
     * Not used as resolvePrimaryLanguage fallback.
     */
    public function languageFromWordpressLocale(Site $site): ?string
    {
        return ContentLanguageCodeNormalizer::fromLocale($this->wordpressLocale($site));
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

            $fallback = $this->polylangFallbackLanguage($site, $options);
            if ($fallback !== null) {
                return $fallback;
            }

            return $this->globalDefaultContentLanguage();
        }

        $stored = $this->getStoredPrimaryLanguage($site);
        if ($stored !== null && ContentLanguageRegistry::isSupported($stored)) {
            return $stored;
        }

        // Legacy explicit code outside registry still honored if structurally canonical.
        if ($stored !== null && ContentLanguageCodeNormalizer::isCanonical($stored)) {
            return $stored;
        }

        return $this->globalDefaultContentLanguage();
    }

    /**
     * Candidate when stored is empty: Polylang default if synced, else sole synced language.
     * Does not write. Does not apply WP locale.
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
     * Persist canonical primary language for Polylang and non-Polylang domains.
     *
     * @throws ValidationException when code is not allowed for the domain source
     */
    public function setPrimaryLanguage(Site $site, ?string $code): void
    {
        if ($code === null || trim($code) === '') {
            $site->metas()->updateOrCreate(
                ['meta_key' => self::META_KEY],
                ['meta_value' => ''],
            );

            return;
        }

        $normalized = ContentLanguageCodeNormalizer::normalize($code);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'seo_primary_language' => __('seo-content-ai::filament.domain.primary_language_invalid'),
            ]);
        }

        if ($this->hasPolylang($site)) {
            $options = $this->syncedLanguageOptions($site);
            if (! isset($options[$normalized])) {
                throw ValidationException::withMessages([
                    'seo_primary_language' => __('seo-content-ai::filament.domain.primary_language_invalid'),
                ]);
            }
        } elseif (! ContentLanguageRegistry::isSupported($normalized)) {
            throw ValidationException::withMessages([
                'seo_primary_language' => __('seo-content-ai::filament.domain.primary_language_invalid_registry'),
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

        $options = $this->formLanguageOptions($site);

        return $options[$resolved]
            ?? ContentLanguageRegistry::label($resolved);
    }

    public function globalDefaultContentLanguage(): ?string
    {
        try {
            $code = app(SeoContentLanguageSettingsService::class)->getDefaultContentLanguage();
        } catch (\Throwable) {
            $code = ContentLanguageRegistry::defaultCode();
        }

        $normalized = ContentLanguageCodeNormalizer::normalize($code);

        return $normalized !== null && ContentLanguageRegistry::isSupported($normalized)
            ? $normalized
            : ContentLanguageRegistry::defaultCode();
    }

    /**
     * @param  array<string, mixed>  $language
     */
    private function normalizePolylangLanguageEntry(array $language): ?string
    {
        $fromSlug = ContentLanguageCodeNormalizer::normalize(
            isset($language['slug']) ? (string) $language['slug'] : null,
        );
        if ($fromSlug !== null && ContentLanguageCodeNormalizer::isCanonical($fromSlug)) {
            return $fromSlug;
        }

        $fromLocale = ContentLanguageCodeNormalizer::fromLocale(
            isset($language['locale']) ? (string) $language['locale'] : null,
        );
        if ($fromLocale !== null && ContentLanguageCodeNormalizer::isCanonical($fromLocale)) {
            return $fromLocale;
        }

        // Prefer slug-normalized short code even when not yet in registry (e.g. ja).
        if ($fromSlug !== null && preg_match('/^[a-z]{2}$/', $fromSlug) === 1) {
            return $fromSlug;
        }

        if ($fromLocale !== null && preg_match('/^[a-z]{2}$/', $fromLocale) === 1) {
            return $fromLocale;
        }

        return null;
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
        $rawDefault = is_array($polylang) ? (string) ($polylang['default'] ?? '') : '';

        $default = ContentLanguageCodeNormalizer::normalize($rawDefault)
            ?? ContentLanguageCodeNormalizer::fromLocale($rawDefault);

        if ($default !== null && isset($options[$default])) {
            return $default;
        }

        if (count($options) === 1) {
            return (string) array_key_first($options);
        }

        return null;
    }
}
