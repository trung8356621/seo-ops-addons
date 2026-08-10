<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Quotas;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;

/**
 * Quota hooks — chưa hard-code billing; luôn allow mặc định.
 */
final class ContentProjectQuotaGuard
{
    public function canCreateProject(ActorContext $actor, ?int $siteId = null): bool
    {
        return true;
    }

    public function canGenerateItems(ActorContext $actor, SeoProject $project, int $itemCount = 0): bool
    {
        return true;
    }

    public function canPublishItems(ActorContext $actor, SeoProject $project, int $itemCount = 0): bool
    {
        return true;
    }

    public function canAgentRequest(ActorContext $actor): bool
    {
        return true;
    }

    public function canAgentArchive(ActorContext $actor, SeoProject $project): bool
    {
        return true;
    }
}
