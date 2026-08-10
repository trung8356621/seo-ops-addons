<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CollectSerpSnapshotsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpCollectionOperationService;
use InvalidArgumentException;

final class CollectSerpSnapshotsHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpCollectionOperationService $collection,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CollectSerpSnapshotsCommand) {
            throw new InvalidArgumentException('Expected CollectSerpSnapshotsCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $result = $this->collection->collect(
                $workspace->public_ref,
                $command->queryRefs,
                $command->providerKey,
            );

            $code = $result['stage'] === 'partially_completed'
                ? SerpIntelligenceActionCodes::COLLECTION_PARTIAL
                : SerpIntelligenceActionCodes::COLLECTION_STARTED;

            return ContentProjectActionResult::ok(
                $code,
                'SERP collection finished.',
                metadata: array_merge(['workspace_ref' => $workspace->public_ref], $result),
            );
        });
    }
}
