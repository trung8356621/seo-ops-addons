<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/** Rebuild Workspace SEO scores for all eligible articles (Advanced). */
final class RequeueAllSeoScoresCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly bool $confirmed = false,
        public readonly ?string $operationId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.score_requeue_all';
    }
}
