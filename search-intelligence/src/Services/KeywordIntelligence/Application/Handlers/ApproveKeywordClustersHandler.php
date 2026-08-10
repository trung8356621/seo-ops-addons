<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveKeywordClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use InvalidArgumentException;

final class ApproveKeywordClustersHandler extends AbstractKeywordIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ApproveKeywordClustersCommand) {
            throw new InvalidArgumentException('Expected ApproveKeywordClustersCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $updated = [];
            $missing = [];

            foreach ($command->clusterRefs as $ref) {
                try {
                    $id = KeywordIntelligencePublicRef::resolveClusterIdStrict((string) $ref);
                } catch (InvalidArgumentException) {
                    $missing[] = $ref;

                    continue;
                }

                $cluster = SeoKeywordCluster::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('id', $id)
                    ->first();

                if (! $cluster instanceof SeoKeywordCluster) {
                    $missing[] = $ref;

                    continue;
                }

                $cluster->status = $command->approve
                    ? KeywordClusterStatus::Approved->value
                    : KeywordClusterStatus::Excluded->value;
                $cluster->save();
                $updated[] = $cluster->public_ref;
            }

            if ($updated === []) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::NOT_FOUND,
                    'No matching clusters found.',
                    errors: $missing !== [] ? ['cluster_refs' => $missing] : [],
                );
            }

            return ContentProjectActionResult::ok(
                $command->approve ? KeywordIntelligenceActionCodes::CLUSTERS_APPROVED : KeywordIntelligenceActionCodes::CLUSTERS_EXCLUDED,
                $command->approve ? 'Clusters approved.' : 'Clusters excluded.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'updated_cluster_refs' => $updated,
                    'missing_cluster_refs' => $missing,
                ],
            );
        });
    }
}
