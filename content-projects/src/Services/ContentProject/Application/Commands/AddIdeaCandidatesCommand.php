<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateDraftPlannerService;

final class AddIdeaCandidatesCommand implements ContentProjectCommand
{
    /**
     * @param  list<int>  $keywordIds
     * @param  list<int>  $articleIds
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $keywordIds,
        public readonly string $action = IdeaCandidateDraftPlannerService::ACTION_CREATE,
        public readonly array $articleIds = [],
    ) {}

    public function name(): string
    {
        return 'content_project.add_idea_candidates';
    }
}
