<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\V3;

use App\Models\Site;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;

/**
 * Resolves primary/secondary language scope for Site Sync V3 runs.
 * Reuses SitePrimaryLanguageService — no second primary-language SSOT.
 */
final class SiteSyncV3LanguageScope
{
    public function __construct(
        private readonly SitePrimaryLanguageService $primaryLanguage = new SitePrimaryLanguageService(),
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{language_scope: string, language_role: string, multilingual: bool}
     */
    public function resolveForStart(Site $site, array $options = []): array
    {
        $multilingual = $this->primaryLanguage->hasPolylang($site);
        $requested = ArticleLanguageCode::normalize((string) ($options['language'] ?? $options['language_scope'] ?? ''));
        $roleHint = strtolower(trim((string) ($options['language_role'] ?? '')));

        if (! $multilingual) {
            return [
                'language_scope' => '',
                'language_role' => SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY,
                'multilingual' => false,
            ];
        }

        $primary = ArticleLanguageCode::normalize(
            (string) ($this->primaryLanguage->resolvePrimaryLanguage($site) ?? '')
        );
        if ($primary === '') {
            $primary = 'vi';
        }

        if ($requested === '') {
            return [
                'language_scope' => $primary,
                'language_role' => SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY,
                'multilingual' => true,
            ];
        }

        $isPrimary = $requested === $primary
            || $roleHint === SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY;

        return [
            'language_scope' => $requested,
            'language_role' => $isPrimary
                ? SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY
                : SiteSyncV3Schema::LANGUAGE_ROLE_SECONDARY,
            'multilingual' => true,
        ];
    }

    public function fromRun(SeoSiteSyncRun $run): string
    {
        $meta = is_array($run->meta) ? $run->meta : [];

        return ArticleLanguageCode::normalize((string) ($meta[SiteSyncV3Schema::META_LANGUAGE_SCOPE] ?? ''));
    }

    public function roleFromRun(SeoSiteSyncRun $run): string
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $role = strtolower(trim((string) ($meta[SiteSyncV3Schema::META_LANGUAGE_ROLE] ?? '')));

        return in_array($role, [
            SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY,
            SiteSyncV3Schema::LANGUAGE_ROLE_SECONDARY,
        ], true) ? $role : SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY;
    }

    public function isPrimaryRun(SeoSiteSyncRun $run): bool
    {
        return $this->roleFromRun($run) === SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY;
    }

    /**
     * @return list<string>
     */
    public function syncedLanguageCodes(Site $site): array
    {
        return array_keys($this->primaryLanguage->syncedLanguageOptions($site));
    }

    public function primaryLanguage(Site $site): ?string
    {
        $code = ArticleLanguageCode::normalize(
            (string) ($this->primaryLanguage->resolvePrimaryLanguage($site) ?? '')
        );

        return $code !== '' ? $code : null;
    }

    public function isMultilingual(Site $site): bool
    {
        return $this->primaryLanguage->hasPolylang($site);
    }
}
