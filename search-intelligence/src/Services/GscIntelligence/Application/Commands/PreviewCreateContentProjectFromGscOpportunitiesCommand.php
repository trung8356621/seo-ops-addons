<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class PreviewCreateContentProjectFromGscOpportunitiesCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly array $opportunityRefs,
        public readonly array $projectAttributes = []
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.preview_create_content_project';
    }
}
