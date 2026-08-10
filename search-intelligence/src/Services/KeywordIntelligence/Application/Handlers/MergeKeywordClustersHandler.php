<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\MergeKeywordClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterMutationService;
use InvalidArgumentException;

final class MergeKeywordClustersHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordClusterMutationService $mutations,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof MergeKeywordClustersCommand) {
            throw new InvalidArgumentException('Expected MergeKeywordClustersCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $sourceIds = array_map(
                static fn (string $ref): int => KeywordIntelligencePublicRef::resolveClusterIdStrict($ref),
                $command->sourceClusterRefs,
            );
            $targetId = KeywordIntelligencePublicRef::resolveClusterIdStrict($command->targetClusterRef);

            $preview = $this->mutations->previewMerge($workspace, $sourceIds, $targetId);

            if ($command->dryRun || ($preview['requires_confirmation'] ?? false)) {
                $fingerprint = $this->buildFingerprint('merge_clusters', (int) $workspace->id, [
                    'sources' => $command->sourceClusterRefs,
                    'target' => $command->targetClusterRef,
                ]);

                if ($command->dryRun || $command->confirmationToken === null || trim($command->confirmationToken) === '') {
                    $token = $this->previewToken->issue($fingerprint);

                    return ContentProjectActionResult::ok(
                        KeywordIntelligenceActionCodes::MERGE_PREVIEW,
                        'Merge preview ready.',
                        metadata: [
                            'preview' => $preview,
                            'confirmation_token' => $token,
                        ],
                    );
                }

                $denied = $this->assertConfirmationToken($command->confirmationToken, $fingerprint, true);
                if ($denied !== null) {
                    return $denied;
                }
            }

            $merged = $this->mutations->merge($workspace, $sourceIds, $targetId);
            $this->consumeConfirmationToken($command->confirmationToken);

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::CLUSTERS_APPROVED,
                'Clusters merged.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'target_cluster_ref' => $merged->public_ref,
                    'keyword_count' => $merged->keyword_count,
                ],
            );
        });
    }
}
