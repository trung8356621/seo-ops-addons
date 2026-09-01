<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use App\Models\Site;

/**
 * Canonical export Domain: item.site_id → Site domain.
 * Never falls back to project.site_id / archive.site_id.
 */
final class ContentProjectItemDomainResolver
{
    public const UNKNOWN = 'Unknown';

    /**
     * @param  array<int, string>  $domainsBySiteId
     */
    public function label(?int $siteId, array $domainsBySiteId): string
    {
        $id = (int) ($siteId ?? 0);
        if ($id <= 0) {
            return self::UNKNOWN;
        }

        $domain = trim((string) ($domainsBySiteId[$id] ?? ''));

        return $domain !== '' ? $domain : self::UNKNOWN;
    }

    public function isUnresolved(string $label): bool
    {
        return $label === self::UNKNOWN || $label === '';
    }

    public function itemSiteId(SeoProjectArchiveItem|SeoProjectTask $item): ?int
    {
        if ($item instanceof SeoProjectTask) {
            $siteId = (int) ($item->site_id ?? 0);

            return $siteId > 0 ? $siteId : null;
        }

        $siteId = (int) ($item->task?->site_id ?? 0);

        return $siteId > 0 ? $siteId : null;
    }

    /**
     * @param  array<int, string>  $domainsBySiteId
     */
    public function labelForItem(SeoProjectArchiveItem|SeoProjectTask $item, array $domainsBySiteId): string
    {
        return $this->label($this->itemSiteId($item), $domainsBySiteId);
    }

    /**
     * Batch-load Site.domain. One query, no N+1, no project.site_id fallback.
     *
     * @param  iterable<mixed>  $items
     * @return array{0: array<int, string>, 1: int}
     */
    public function preloadDomains(iterable $items): array
    {
        $siteIds = [];
        foreach ($items as $item) {
            if (! $item instanceof SeoProjectArchiveItem && ! $item instanceof SeoProjectTask) {
                continue;
            }
            $id = $this->itemSiteId($item);
            if ($id !== null) {
                $siteIds[$id] = $id;
            }
        }

        $domains = [];
        if ($siteIds !== []) {
            foreach (Site::query()->whereIn('id', array_values($siteIds))->get(['id', 'domain']) as $site) {
                $domains[(int) $site->getKey()] = trim((string) ($site->domain ?? ''));
            }
        }

        $unresolved = 0;
        foreach ($items as $item) {
            if (! $item instanceof SeoProjectArchiveItem && ! $item instanceof SeoProjectTask) {
                continue;
            }
            if ($this->isUnresolved($this->labelForItem($item, $domains))) {
                $unresolved++;
            }
        }

        return [$domains, $unresolved];
    }
}
