<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use RuntimeException;

/**
 * Tenant / site isolation cho Keyword Intelligence — mirror ContentProjectTenantGuard.
 */
final class KeywordIntelligenceTenantGuard
{
    public function assertCanAccessWorkspace(SeoKeywordWorkspace $workspace, ActorContext $actor): void
    {
        $siteId = (int) ($workspace->site_id ?? 0);
        if ($siteId <= 0) {
            throw new RuntimeException('Workspace thiếu site_id.');
        }

        if ($actor->siteId !== null && $actor->siteId > 0 && $actor->siteId !== $siteId) {
            throw new RuntimeException('Workspace không thuộc site hiện tại.');
        }

        if (in_array($actor->actorType, ['user', 'api', 'agent'], true) && ! SeoAccessControl::canAccessSite($siteId)) {
            throw new RuntimeException('Không có quyền truy cập workspace.');
        }
    }
}
