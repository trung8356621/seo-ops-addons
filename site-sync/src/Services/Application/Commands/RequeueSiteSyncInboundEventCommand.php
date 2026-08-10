<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RequeueSiteSyncInboundEventCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly int $eventId,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.requeue_inbound_event';
    }
}
