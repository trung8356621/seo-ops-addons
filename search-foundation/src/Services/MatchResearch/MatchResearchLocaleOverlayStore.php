<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\MatchResearch;

use Omnichannel\Addons\SearchFoundation\Models\MatchResearchLocaleOverlay;

final class MatchResearchLocaleOverlayStore
{
    public const GLOBAL_SITE_ID = 0;

    /**
     * @return array<string, array<string, mixed>> locale => payload
     */
    public function overlaysFor(string $resourceKey, ?int $siteId = null): array
    {
        $siteIds = [self::GLOBAL_SITE_ID];
        if ($siteId !== null && $siteId > 0) {
            $siteIds[] = $siteId;
        }

        $out = [];
        $rows = MatchResearchLocaleOverlay::query()
            ->where('resource_key', $resourceKey)
            ->whereIn('site_id', $siteIds)
            ->orderBy('site_id')
            ->get();

        foreach ($rows as $row) {
            /** @var MatchResearchLocaleOverlay $row */
            $locale = (string) $row->locale;
            // Site-specific overlay wins over global (site_id=0)
            if (! isset($out[$locale]) || (int) $row->site_id > 0) {
                $out[$locale] = (array) $row->payload;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $payload */
    public function put(string $resourceKey, string $locale, array $payload, ?int $siteId = null): void
    {
        $locale = trim($locale);
        if ($locale === '') {
            throw new \InvalidArgumentException('locale is required.');
        }

        $resolvedSiteId = ($siteId !== null && $siteId > 0) ? $siteId : self::GLOBAL_SITE_ID;

        MatchResearchLocaleOverlay::query()->updateOrCreate(
            [
                'site_id' => $resolvedSiteId,
                'resource_key' => $resourceKey,
                'locale' => $locale,
            ],
            ['payload' => $payload],
        );
    }
}
