<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Quotas\KeywordIntelligenceQuotaGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordToContentProjectConverter;
use InvalidArgumentException;

final class PreviewContentProjectFromClustersHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordToContentProjectConverter $converter,
        private readonly KeywordIntelligenceQuotaGuard $quota,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof PreviewContentProjectFromClustersCommand) {
            throw new InvalidArgumentException('Expected PreviewContentProjectFromClustersCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            if ($command->clusterRefs === []) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::VALIDATION_FAILED,
                    'cluster_refs is required.',
                );
            }

            $ids = array_values(array_unique(array_filter(array_map(
                static fn (string $ref): int => KeywordIntelligencePublicRef::resolveClusterIdStrict($ref),
                $command->clusterRefs,
            ), static fn (int $id): bool => $id > 0)));

            if (! $this->quota->canConvert(count($ids))) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::CONVERSION_TOO_LARGE,
                    'Cluster count exceeds convert quota.',
                );
            }

            $preview = $this->converter->preview($workspace, $command->clusterRefs);

            $requiresConfirmation = $this->requiresConfirmation($actor)
                || $this->quota->requiresConfirmation((int) $preview['eligible_clusters']);

            $fingerprint = $this->buildFingerprint('keyword_intelligence.convert_to_content_project', (int) $workspace->id, [
                'cluster_refs' => $command->clusterRefs,
                'project_attributes' => $command->projectAttributes,
            ]);

            $token = $requiresConfirmation ? $this->previewToken->issue($fingerprint) : null;

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::PREVIEW_READY,
                'Preview generated.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'preview' => $preview,
                    'requires_confirmation' => $requiresConfirmation,
                    'confirmation_token' => $token,
                ],
            );
        });
    }
}
