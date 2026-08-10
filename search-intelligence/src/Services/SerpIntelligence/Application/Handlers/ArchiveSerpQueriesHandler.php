<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpQueryStatus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ArchiveSerpQueriesCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use InvalidArgumentException;

final class ArchiveSerpQueriesHandler extends AbstractSerpIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ArchiveSerpQueriesCommand) {
            throw new InvalidArgumentException('Expected ArchiveSerpQueriesCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $archived = [];
            foreach ($command->queryRefs as $ref) {
                $query = $this->resolveQuery((string) $ref, $workspace);
                $query->status = SerpQueryStatus::Archived;
                $query->archived_at = now();
                $query->save();
                $archived[] = $query->public_ref;
            }

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::QUERIES_ARCHIVED,
                'SERP queries archived.',
                metadata: ['workspace_ref' => $workspace->public_ref, 'query_refs' => $archived],
            );
        });
    }
}
