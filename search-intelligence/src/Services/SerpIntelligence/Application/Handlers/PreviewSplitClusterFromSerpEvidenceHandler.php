<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\PreviewSplitClusterFromSerpEvidenceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpClusterValidationService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpEvidenceApplyService;
use InvalidArgumentException;

final class PreviewSplitClusterFromSerpEvidenceHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpEvidenceApplyService $evidenceApply,
        private readonly SerpClusterValidationService $validator,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof PreviewSplitClusterFromSerpEvidenceCommand) {
            throw new InvalidArgumentException('Expected PreviewSplitClusterFromSerpEvidenceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $evidence = $this->evidenceApply->resolveEvidence($command->evidenceRef);
            $cluster = SeoKeywordCluster::query()->find($evidence->cluster_id);
            if (! $cluster instanceof SeoKeywordCluster) {
                throw new InvalidArgumentException('Cluster not found.');
            }

            $suggestions = $this->validator->suggest([]);
            $preview = [
                'evidence_ref' => $evidence->public_ref,
                'cluster_ref' => $cluster->public_ref,
                'split_suggestions' => $suggestions,
                'dry_run' => $command->dryRun,
            ];

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::SPLIT_PREVIEW,
                'Split cluster preview generated.',
                metadata: array_merge(['workspace_ref' => $workspace->public_ref], $preview),
            );
        });
    }
}
