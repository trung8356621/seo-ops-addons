<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality;

use App\Models\Site;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;

/**
 * Official site language configuration.
 *
 * Two or more codes only when Polylang is active and those languages are synced.
 * A non-Polylang site contributes its single primary language, so mismatch stays off.
 */
class PolylangSiteContentLanguageCatalog
{
    /**
     * @return list<string>
     */
    public function configuredCodes(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        try {
            $site = Site::query()->find($siteId);
            if (! $site instanceof Site) {
                return [];
            }

            $languages = app(SitePrimaryLanguageService::class);
            if (! $languages->hasPolylang($site)) {
                $primary = ArticleLanguageCode::normalize((string) $languages->resolvePrimaryLanguage($site));

                return $primary !== '' ? [$primary] : [];
            }

            $codes = [];
            foreach (array_keys($languages->syncedLanguageOptions($site)) as $code) {
                $normalized = ArticleLanguageCode::normalize((string) $code);
                if ($normalized === '' || in_array($normalized, $codes, true)) {
                    continue;
                }
                $codes[] = $normalized;
            }

            return $codes;
        } catch (\Throwable) {
            return [];
        }
    }
}
