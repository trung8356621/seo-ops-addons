<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\MoveClusterPrimaryTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordTopicalMapMutationService;
use InvalidArgumentException;

final class MoveClusterPrimaryTopicHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordTopicalMapMutationService $mutations,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof MoveClusterPrimaryTopicCommand) {
            throw new InvalidArgumentException('Expected MoveClusterPrimaryTopicCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $link = $this->mutations->moveClusterPrimary($workspace, $command->clusterRef, $command->newTopicRef);

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::CLUSTER_PRIMARY_MOVED,
                'Cluster primary topic moved.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'cluster_ref' => $command->clusterRef,
                    'topic_ref' => $command->newTopicRef,
                    'link_ref' => $link->public_ref,
                ],
            );
        });
    }
}
