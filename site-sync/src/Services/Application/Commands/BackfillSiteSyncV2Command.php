<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class BackfillSiteSyncV2Command implements ContentProjectCommand
{
    /**
     * @param  list<string>  $only
     */
    public function __construct(
        public readonly int $siteId,
        public readonly bool $dryRun = true,
        public readonly array $only = ['all'],
        public readonly int $batch = 200,
        public readonly ?int $resumeId = null,
        public readonly bool $force = false,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.backfill_v2';
    }
}
