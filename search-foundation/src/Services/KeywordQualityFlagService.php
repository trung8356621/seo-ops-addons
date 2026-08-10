<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;

/**
 * @deprecated Auto quality flags removed — user-driven keyword review only.
 */
final class KeywordQualityFlagService
{
    public function recomputeForLinkMap(SeoLinkMap $linkMap): void
    {
        // Intentionally no-op: review_status is user-managed only.
    }

    public function recomputeForKeywordFromMaps(int $keywordId): void
    {
        // Intentionally no-op.
    }

    public function recomputeForSite(int $siteId): int
    {
        return 0;
    }

    public function isDangerPhrase(string $phrase): bool
    {
        return false;
    }

    public function isWarningPhrase(string $phrase): bool
    {
        return false;
    }

    public function isDangerContextBefore(string $contextBefore): bool
    {
        return false;
    }
}
