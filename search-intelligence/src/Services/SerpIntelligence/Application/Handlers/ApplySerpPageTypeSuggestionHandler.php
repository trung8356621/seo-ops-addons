<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ApplySerpPageTypeSuggestionCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpEvidenceApplyService;
use InvalidArgumentException;

final class ApplySerpPageTypeSuggestionHandler extends AbstractSerpIntelligenceHandler
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
        if (! $command instanceof ApplySerpPageTypeSuggestionCommand) {
            throw new InvalidArgumentException('Expected ApplySerpPageTypeSuggestionCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $evidence = $this->evidenceApply->resolveEvidence($command->evidenceRef);
            $cluster = SeoKeywordCluster::query()->find($evidence->cluster_id);

            if ($command->preview || $this->requiresConfirmation($actor, $command->confirmationToken)) {
                return ContentProjectActionResult::ok(
                    SerpIntelligenceActionCodes::PREVIEW_READY,
                    'Apply page type requires confirmation.',
                    metadata: [
                        'workspace_ref' => $workspace->public_ref,
                        'evidence_ref' => $evidence->public_ref,
                        'suggested_page_type' => $evidence->dominant_page_type?->value ?? $evidence->dominant_page_type,
                        'source' => 'serp_evidence',
                    ],
                );
            }

            if ($cluster instanceof SeoKeywordCluster) {
                $cluster->suggested_content_type = $evidence->recommended_content_type;
                $cluster->save();
            }

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::PAGE_TYPE_APPLIED,
                'Page type suggestion recorded.',
                metadata: ['workspace_ref' => $workspace->public_ref, 'evidence_ref' => $evidence->public_ref],
            );
        });
    }
}
