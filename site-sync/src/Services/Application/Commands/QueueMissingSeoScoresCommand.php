<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/** Queue Workspace SEO scores for articles missing / stale score. */
final class QueueMissingSeoScoresCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly ?int $runId = null,
        public readonly ?string $operationId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.score_missing';
    }
}
