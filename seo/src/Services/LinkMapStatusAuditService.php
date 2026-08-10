<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;

final class LinkMapStatusAuditService
{
    public function __construct(
        private readonly LinkAuditCacheService $auditCache,
        private readonly KeywordLinkTargetResolver $targetResolver,
    ) {}

    public function queueLinkMap(SeoLinkMap $linkMap, int $siteId, ?string $resolvedTargetUrl = null): void
    {
        $linkMapId = (int) ($linkMap->id ?? 0);
        if ($linkMapId <= 0 || $siteId <= 0) {
            return;
        }

        $targetUrl = trim((string) ($resolvedTargetUrl ?? ''));
        if ($targetUrl === '') {
            AuditLinkStatusJob::dispatch($linkMapId, $siteId);

            return;
        }

        $cached = $this->auditCache->findFresh($siteId, $targetUrl);
        if ($cached !== null) {
            $this->auditCache->applyToLinkMap($linkMap, $cached);

            return;
        }

        AuditLinkStatusJob::dispatch($linkMapId, $siteId);
    }

    public function queueDomainAudit(int $siteId): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        $queued = 0;

        SeoLinkMap::query()
            ->whereHas('sourceArticle', static fn ($query) => $query->where('site_id', $siteId))
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(200, function ($maps) use ($siteId, &$queued): void {
                foreach ($maps as $map) {
                    if (! $map instanceof SeoLinkMap) {
                        continue;
                    }

                    AuditLinkStatusJob::dispatch((int) $map->id, $siteId);
                    $queued++;
                }
            });

        return $queued;
    }
}
