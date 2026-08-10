<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class CreateContentProjectFromGscOpportunitiesCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly array $opportunityRefs,
        public readonly array $projectAttributes = [],
        public readonly ?string $confirmationToken = null,
        public readonly ?string $idempotencyKey = null
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.create_content_project';
    }
}
