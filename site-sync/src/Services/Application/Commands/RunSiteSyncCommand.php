<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RunSiteSyncCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>|null  $steps
     */
    public function __construct(
        public readonly int $siteId,
        public readonly string $mode = 'delta',
        public readonly bool $forceSnapshot = false,
        public readonly ?array $steps = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.sync';
    }
}
