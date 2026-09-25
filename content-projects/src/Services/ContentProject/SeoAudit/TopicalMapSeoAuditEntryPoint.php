<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Throwable;

/**
 * Stable cross-addon navigation capability for the Topical Map shell.
 */
final class TopicalMapSeoAuditEntryPoint
{
    public function resolveUrl(int $siteId): ?string
    {
        if ($siteId <= 0) {
            return null;
        }

        try {
            if (! ContentProjectSeoAuditPlanner::canAccess()) {
                return null;
            }

            return app(DomainContextResolver::class)->appendSiteToUrl(
                ContentProjectSeoAuditPlanner::getUrl(),
                $siteId,
            );
        } catch (Throwable) {
            // Optional navigation must never break the standalone shell.
            return null;
        }
    }
}
