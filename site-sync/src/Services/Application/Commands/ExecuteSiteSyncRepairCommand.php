<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ExecuteSiteSyncRepairCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly int $planId,
        public readonly array $selectedIds = [],
        public readonly bool $dryRun = true,
        public readonly ?string $confirmationToken = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.execute_repair';
    }
}
