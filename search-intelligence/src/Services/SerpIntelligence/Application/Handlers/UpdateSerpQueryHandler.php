<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpQueryStatus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\UpdateSerpQueryCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use InvalidArgumentException;

final class UpdateSerpQueryHandler extends AbstractSerpIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof UpdateSerpQueryCommand) {
            throw new InvalidArgumentException('Expected UpdateSerpQueryCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $query = $this->resolveQuery($command->queryRef, $workspace);
            $attrs = $command->attributes;

            foreach (['language', 'country', 'location', 'search_engine', 'provider_key'] as $field) {
                if (array_key_exists($field, $attrs)) {
                    $query->{$field} = (string) $attrs[$field];
                }
            }

            if (isset($attrs['status'])) {
                $query->status = SerpQueryStatus::tryFrom((string) $attrs['status']) ?? $query->status;
            }

            if (isset($attrs['settings']) && is_array($attrs['settings'])) {
                $query->settings = $attrs['settings'];
            }

            $query->save();

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::QUERY_UPDATED,
                'SERP query updated.',
                metadata: ['workspace_ref' => $workspace->public_ref, 'query_ref' => $query->public_ref],
            );
        });
    }
}
