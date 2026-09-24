<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support;

use App\Models\Site;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Site-scoped access gate for Topical Map shell + JSON APIs.
 */
final class TopicalMapAccess
{
    public function canAccessApp(): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public function assertCanAccessApp(): void
    {
        if (! $this->canAccessApp()) {
            throw new AccessDeniedHttpException('Topical Map access denied.');
        }
    }

    public function canAccessSite(int $siteId): bool
    {
        return $siteId > 0
            && SeoAccessControl::canAccessPlannerFeatures()
            && SeoAccessControl::canAccessSite($siteId);
    }

    public function assertCanAccessSite(int $siteId): void
    {
        if ($siteId <= 0) {
            throw new NotFoundHttpException('Site not found.');
        }

        if (! SeoAccessControl::canAccessPlannerFeatures() || ! SeoAccessControl::canAccessSite($siteId)) {
            throw new NotFoundHttpException('Site not found.');
        }
    }

    public function canMutateSite(int $siteId): bool
    {
        return $this->canAccessSite($siteId) && SeoAccessControl::canMutateInSeoPanel();
    }

    public function assertCanMutateSite(int $siteId): void
    {
        $this->assertCanAccessSite($siteId);
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            throw new AccessDeniedHttpException('Topical Map mutation denied.');
        }
    }

    public function resolveSiteId(?int $requested = null): int
    {
        $candidate = ($requested !== null && $requested > 0)
            ? $requested
            : (int) (SeoAccessControl::globalSiteId() ?? 0);

        if ($candidate > 0 && $this->canAccessSite($candidate)) {
            return $candidate;
        }

        $first = SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->value('id');

        return is_numeric($first) ? (int) $first : 0;
    }

    public function siteDomain(int $siteId): string
    {
        if ($siteId <= 0) {
            return '';
        }

        $site = Site::query()->find($siteId);

        return $site instanceof Site ? (string) ($site->domain ?? '') : '';
    }
}
