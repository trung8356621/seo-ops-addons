<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromKeywordClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Quotas\KeywordIntelligenceQuotaGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordToContentProjectConverter;
use InvalidArgumentException;
use Throwable;

final class CreateContentProjectFromKeywordClustersHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordToContentProjectConverter $converter,
        private readonly KeywordIntelligenceQuotaGuard $quota,
        private readonly ContentProjectCommandBus $bus,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CreateContentProjectFromKeywordClustersCommand) {
            throw new InvalidArgumentException('Expected CreateContentProjectFromKeywordClustersCommand.');
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
                static fn (string $ref): int => KeywordIntelligencePublicRef::resolveClusterIdStrict((string) $ref),
                $command->clusterRefs,
            ), static fn (int $id): bool => $id > 0)));

            if ($ids === []) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::VALIDATION_FAILED,
                    'No valid cluster_refs provided.',
                );
            }

            if (! $this->quota->canConvert(count($ids))) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::CONVERSION_TOO_LARGE,
                    'Cluster count exceeds convert quota.',
                );
            }

            $requiresConfirmation = $this->requiresConfirmation($actor, $command->confirmationToken)
                || $this->quota->requiresConfirmation(count($ids));

            $fingerprint = $this->buildFingerprint('keyword_intelligence.convert_to_content_project', (int) $workspace->id, [
                'cluster_refs' => $command->clusterRefs,
                'project_attributes' => $command->projectAttributes,
            ]);

            $confirmationFailure = $this->assertConfirmationToken($command->confirmationToken, $fingerprint, $requiresConfirmation);
            if ($confirmationFailure !== null) {
                return $confirmationFailure;
            }

            if ($command->dryRun) {
                $preview = $this->converter->preview($workspace, $command->clusterRefs);

                return ContentProjectActionResult::ok(
                    KeywordIntelligenceActionCodes::PREVIEW_READY,
                    'Dry-run preview ready.',
                    metadata: [
                        'workspace_ref' => $workspace->public_ref,
                        'preview' => $preview,
                        'dry_run' => true,
                    ],
                );
            }

            try {
                $result = $this->converter->convert($workspace, $command->clusterRefs, $actor, $this->bus, $command->projectAttributes);
            } catch (Throwable $e) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::FAILED,
                    'Conversion failed: '.$e->getMessage(),
                );
            }

            $this->consumeConfirmationToken($command->confirmationToken);

            return $result;
        });
    }
}
