<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Reconciliation;

use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkExclusion;
use Omnichannel\Addons\SiteSync\Models\SeoSiteManualLink;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use App\Models\Site;

final class SiteLinkCatalogReconciler
{
    /**
     * @param  list<array<string, mixed>>  $links
     * @return array{upserted: int, skipped: int}
     */
    public function reconcileWordPressLinks(Site $site, array $links): array
    {
        $upserted = 0;
        $skipped = 0;
        $siteId = (int) $site->id;

        foreach ($links as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '') {
                $skipped++;
                continue;
            }

            $urlHash = hash('sha256', mb_strtolower($url));
            SeoSiteLinkCatalog::query()->updateOrCreate(
                [
                    'site_id' => $siteId,
                    'url_hash' => $urlHash,
                    'source' => SiteSyncSchema::SOURCE_WORDPRESS,
                ],
                [
                    'wordpress_id' => isset($link['wordpress_id']) ? (int) $link['wordpress_id'] : null,
                    'url' => $url,
                    'canonical' => isset($link['canonical']) ? (string) $link['canonical'] : null,
                    'slug' => isset($link['slug']) ? (string) $link['slug'] : null,
                    'title' => isset($link['title']) ? (string) $link['title'] : null,
                    'status' => (string) ($link['status'] ?? 'publish'),
                    'type' => (string) ($link['type'] ?? 'article'),
                    'content_hash' => isset($link['content_hash']) ? (string) $link['content_hash'] : null,
                    'updated_at_wp' => isset($link['updated_at']) ? $link['updated_at'] : null,
                    'meta' => is_array($link['meta'] ?? null) ? $link['meta'] : null,
                ],
            );
            $upserted++;
        }

        return ['upserted' => $upserted, 'skipped' => $skipped];
    }

    /**
     * Persist manual links from domain settings without keyword sync side effects.
     *
     * @param  list<array{keyword?: string, link?: string}>  $links
     */
    public function syncManualLinksFromSettings(Site $site, array $links): int
    {
        $siteId = (int) $site->id;
        $keptHashes = [];
        $count = 0;

        foreach ($links as $row) {
            $url = trim((string) ($row['link'] ?? ''));
            if ($url === '') {
                continue;
            }
            $urlHash = hash('sha256', mb_strtolower($url));
            $keptHashes[] = $urlHash;
            SeoSiteManualLink::query()->updateOrCreate(
                ['site_id' => $siteId, 'url_hash' => $urlHash],
                [
                    'keyword' => trim((string) ($row['keyword'] ?? '')) ?: null,
                    'url' => $url,
                    'is_locked' => true,
                ],
            );
            // Mirror into catalog as manual source (never overwritten by wordpress source rows).
            SeoSiteLinkCatalog::query()->updateOrCreate(
                [
                    'site_id' => $siteId,
                    'url_hash' => $urlHash,
                    'source' => SiteSyncSchema::SOURCE_MANUAL,
                ],
                [
                    'url' => $url,
                    'title' => trim((string) ($row['keyword'] ?? '')) ?: null,
                    'status' => 'manual',
                    'type' => 'manual',
                    'content_hash' => null,
                ],
            );
            $count++;
        }

        if ($keptHashes !== []) {
            SeoSiteManualLink::query()
                ->where('site_id', $siteId)
                ->whereNotIn('url_hash', $keptHashes)
                ->delete();
        } else {
            SeoSiteManualLink::query()->where('site_id', $siteId)->delete();
        }

        return $count;
    }

    /**
     * Effective = WordPress ∪ Manual − Excluded.
     *
     * @return list<array<string, mixed>>
     */
    public function effectiveLinks(int $siteId): array
    {
        $excludedHashes = SeoSiteLinkExclusion::query()
            ->where('site_id', $siteId)
            ->whereNotNull('url_hash')
            ->pluck('url_hash')
            ->all();

        $excludedWpIds = SeoSiteLinkExclusion::query()
            ->where('site_id', $siteId)
            ->whereNotNull('wordpress_id')
            ->pluck('wordpress_id')
            ->all();

        $rows = SeoSiteLinkCatalog::query()
            ->forSite($siteId)
            ->whereIn('source', [SiteSyncSchema::SOURCE_WORDPRESS, SiteSyncSchema::SOURCE_MANUAL])
            ->orderBy('source')
            ->orderBy('id')
            ->get();

        $byHash = [];
        foreach ($rows as $row) {
            if (in_array($row->url_hash, $excludedHashes, true)) {
                continue;
            }
            if ($row->wordpress_id !== null && in_array((int) $row->wordpress_id, array_map('intval', $excludedWpIds), true)) {
                continue;
            }
            // Manual wins same url_hash over wordpress.
            if (isset($byHash[$row->url_hash]) && $row->source !== SiteSyncSchema::SOURCE_MANUAL) {
                continue;
            }
            $byHash[$row->url_hash] = [
                'id' => (int) $row->id,
                'wordpress_id' => $row->wordpress_id !== null ? (int) $row->wordpress_id : null,
                'url' => (string) $row->url,
                'canonical' => $row->canonical,
                'slug' => $row->slug,
                'title' => $row->title,
                'status' => (string) $row->status,
                'type' => (string) $row->type,
                'content_hash' => $row->content_hash,
                'source' => (string) $row->source,
                'updated_at' => optional($row->updated_at_wp ?? $row->updated_at)?->toIso8601String(),
            ];
        }

        return array_values($byHash);
    }
}
