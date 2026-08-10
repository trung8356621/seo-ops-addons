<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\RejectSerpClusterEvidenceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpEvidenceApplyService;
use InvalidArgumentException;

final class RejectSerpClusterEvidenceHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpEvidenceApplyService $evidenceApply,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RejectSerpClusterEvidenceCommand) {
            throw new InvalidArgumentException('Expected RejectSerpClusterEvidenceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $evidence = $this->evidenceApply->resolveEvidence($command->evidenceRef);
            $rejected = $this->evidenceApply->reject($evidence, $actor->actorId);

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::EVIDENCE_REJECTED,
                'Cluster evidence rejected.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'evidence_ref' => $rejected->public_ref,
                ],
            );
        });
    }
}
