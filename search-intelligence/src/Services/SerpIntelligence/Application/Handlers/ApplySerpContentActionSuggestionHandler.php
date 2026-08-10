<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ApplySerpContentActionSuggestionCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpEvidenceApplyService;
use InvalidArgumentException;

final class ApplySerpContentActionSuggestionHandler extends AbstractSerpIntelligenceHandler
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
        if (! $command instanceof ApplySerpContentActionSuggestionCommand) {
            throw new InvalidArgumentException('Expected ApplySerpContentActionSuggestionCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $evidence = $this->evidenceApply->resolveEvidence($command->evidenceRef);
            $cluster = SeoKeywordCluster::query()->find($evidence->cluster_id);
            if (! $cluster instanceof SeoKeywordCluster) {
                throw new InvalidArgumentException('Cluster not found.');
            }

            if ($command->preview) {
                $preview = $this->evidenceApply->previewApplyContentAction($evidence, $cluster);
                $preview['confirmation_token'] = $this->previewToken->issue(
                    $this->buildFingerprint('serp_intelligence.apply_content_action', (int) $workspace->id, [
                        'evidence_ref' => $evidence->public_ref,
                    ]),
                );

                return ContentProjectActionResult::ok(
                    SerpIntelligenceActionCodes::PREVIEW_READY,
                    'Apply content action preview generated.',
                    metadata: array_merge(['workspace_ref' => $workspace->public_ref], $preview),
                );
            }

            $required = $this->requiresConfirmation($actor, $command->confirmationToken);
            $fingerprint = $this->buildFingerprint('serp_intelligence.apply_content_action', (int) $workspace->id, [
                'evidence_ref' => $evidence->public_ref,
            ]);
            if ($fail = $this->assertConfirmationToken($command->confirmationToken, $fingerprint, $required)) {
                return $fail;
            }

            $applied = $this->evidenceApply->applyContentAction($evidence, $cluster);
            $this->consumeConfirmationToken($command->confirmationToken);

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::CONTENT_ACTION_APPLIED,
                'Content action applied.',
                metadata: array_merge(['workspace_ref' => $workspace->public_ref], $applied),
            );
        });
    }
}
