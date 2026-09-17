<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use RuntimeException;

/**
 * Site isolation for Search Intelligence handlers (workspace model retired).
 */
final class KeywordIntelligenceTenantGuard
{
    public function assertCanAccessSite(int $siteId, ActorContext $actor): void
    {
        if ($siteId <= 0) {
            throw new RuntimeException('Thiếu site_id.');
        }

        if ($actor->siteId !== null && $actor->siteId > 0 && $actor->siteId !== $siteId) {
            throw new RuntimeException('Không thuộc site hiện tại.');
        }

        if (in_array($actor->actorType, ['user', 'api', 'agent'], true) && ! SeoAccessControl::canAccessSite($siteId)) {
            throw new RuntimeException('Không có quyền truy cập site.');
        }
    }

    /**
     * @param  object{site_id?: int|null}  $context
     */
    public function assertCanAccessWorkspace(object $context, ActorContext $actor): void
    {
        $this->assertCanAccessSite((int) ($context->site_id ?? 0), $actor);
    }
}
