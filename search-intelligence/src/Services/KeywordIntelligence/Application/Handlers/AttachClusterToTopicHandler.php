<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AttachClusterToTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordTopicalMapMutationService;
use InvalidArgumentException;

final class AttachClusterToTopicHandler extends AbstractKeywordIntelligenceHandler
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
        if (! $command instanceof AttachClusterToTopicCommand) {
            throw new InvalidArgumentException('Expected AttachClusterToTopicCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $link = $this->mutations->attachCluster(
                $workspace,
                $command->topicRef,
                $command->clusterRef,
                $command->relationship,
            );

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::CLUSTER_ATTACHED,
                'Cluster attached to topic.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'topic_ref' => $command->topicRef,
                    'cluster_ref' => $command->clusterRef,
                    'link_ref' => $link->public_ref,
                    'relationship' => $link->relationship instanceof \BackedEnum
                        ? $link->relationship->value
                        : (string) $link->relationship,
                ],
            );
        });
    }
}
