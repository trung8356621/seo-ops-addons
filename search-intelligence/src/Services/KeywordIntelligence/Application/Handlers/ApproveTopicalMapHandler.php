<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordTopicalMapMutationService;
use InvalidArgumentException;
use RuntimeException;

final class ApproveTopicalMapHandler extends AbstractKeywordIntelligenceHandler
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
        if (! $command instanceof ApproveTopicalMapCommand) {
            throw new InvalidArgumentException('Expected ApproveTopicalMapCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            // Agent never gets blocking override — even if client sends true.
            $allowOverride = $command->allowBlockingOverride && $actor->actorType !== 'agent';

            $conflicts = $this->mutations->detectConflicts($workspace);
            $blocking = array_values(array_filter(
                $conflicts,
                static fn (array $c): bool => ($c['blocking'] ?? false) === true || ($c['risk'] ?? '') === 'blocking',
            ));

            if ($blocking !== [] && ! $allowOverride) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::TOPICAL_MAP_APPROVAL_BLOCKED,
                    'Topical map has blocking conflicts.',
                    metadata: [
                        'workspace_ref' => $workspace->public_ref,
                        'map_version_ref' => $command->mapVersionRef,
                        'conflicts' => $blocking,
                    ],
                );
            }

            $requiresConfirmation = $this->requiresConfirmation($actor, $command->confirmationToken)
                || ($blocking !== [] && $allowOverride);

            $fingerprint = $this->buildFingerprint('keyword_intelligence.approve_topical_map', (int) $workspace->id, [
                'map_version_ref' => $command->mapVersionRef,
                'allow_blocking_override' => $allowOverride,
            ]);

            $denied = $this->assertConfirmationToken($command->confirmationToken, $fingerprint, $requiresConfirmation);
            if ($denied !== null) {
                if ($command->confirmationToken === null || trim($command->confirmationToken) === '') {
                    $token = $this->previewToken->issue($fingerprint);

                    return ContentProjectActionResult::fail(
                        KeywordIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                        'Confirmation required to approve topical map.',
                        metadata: [
                            'confirmation_token' => $token,
                            'conflicts' => $conflicts,
                        ],
                    );
                }

                return $denied;
            }

            try {
                $version = $this->mutations->approveMapVersion(
                    $workspace,
                    $command->mapVersionRef,
                    $actor->actorId,
                    $allowOverride,
                );
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'topical_map.approval_blocked') {
                    return ContentProjectActionResult::fail(
                        KeywordIntelligenceActionCodes::TOPICAL_MAP_APPROVAL_BLOCKED,
                        'Topical map has blocking conflicts.',
                        metadata: ['conflicts' => $this->mutations->detectConflicts($workspace)],
                    );
                }
                throw $e;
            }

            $this->consumeConfirmationToken($command->confirmationToken);

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::TOPICAL_MAP_APPROVED,
                'Topical map approved.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'map_version_ref' => $version->public_ref,
                    'status' => $version->status,
                    'approved_at' => $version->approved_at?->toIso8601String(),
                ],
            );
        });
    }
}
