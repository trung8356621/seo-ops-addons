<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ValidateClusterWithSerpCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpClusterValidationService;
use InvalidArgumentException;

final class ValidateClusterWithSerpHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpClusterValidationService $validator,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ValidateClusterWithSerpCommand) {
            throw new InvalidArgumentException('Expected ValidateClusterWithSerpCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $clusterId = KeywordIntelligencePublicRef::resolveClusterIdStrict($command->clusterRef);
            $cluster = SeoKeywordCluster::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', $clusterId)
                ->first();

            if (! $cluster instanceof SeoKeywordCluster) {
                throw new InvalidArgumentException('Cluster not found.');
            }

            $members = SeoKiKeyword::query()
                ->where('cluster_id', $cluster->id)
                ->get()
                ->map(fn (SeoKiKeyword $k): array => [
                    'keyword_ref' => $k->public_ref,
                    'results' => [],
                ])
                ->all();

            $suggestions = $this->validator->suggest($members);

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::CLUSTER_VALIDATED,
                'Cluster SERP validation complete.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'cluster_ref' => $cluster->public_ref,
                    'suggestions' => $suggestions,
                ],
            );
        });
    }
}
