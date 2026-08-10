<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CancelSerpCollectionCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpCollectionOperationService;
use InvalidArgumentException;

final class CancelSerpCollectionHandler extends AbstractSerpIntelligenceHandler
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
        if (! $command instanceof CancelSerpCollectionCommand) {
            throw new InvalidArgumentException('Expected CancelSerpCollectionCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            if (! $this->collection->cancel($command->operationRef)) {
                return ContentProjectActionResult::fail(
                    SerpIntelligenceActionCodes::NOT_FOUND,
                    'Collection operation not found or already finished.',
                );
            }

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::COLLECTION_CANCELLED,
                'Collection cancelled.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'operation_ref' => $command->operationRef,
                ],
            );
        });
    }
}
