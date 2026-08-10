<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ApproveGscOpportunityCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly string $opportunityRef
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.approve_opportunity';
    }
}
