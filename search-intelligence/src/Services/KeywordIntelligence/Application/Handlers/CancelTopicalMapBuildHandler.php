<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CancelTopicalMapBuildCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordTopicalMapBuildLock;
use InvalidArgumentException;

final class CancelTopicalMapBuildHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard $tenantGuard,
        \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken $previewToken,
        private readonly KeywordTopicalMapBuildLock $buildLock,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CancelTopicalMapBuildCommand) {
            throw new InvalidArgumentException('Expected CancelTopicalMapBuildCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            if (! $this->buildLock->isHeld($workspace->public_ref)) {
                return ContentProjectActionResult::ok(
                    KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_CANCELLED,
                    'No topical map build in progress.',
                    metadata: ['workspace_ref' => $workspace->public_ref, 'was_building' => false],
                );
            }

            $this->buildLock->requestCancel($workspace->public_ref);

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_CANCELLED,
                'Topical map build cancellation requested.',
                metadata: ['workspace_ref' => $workspace->public_ref, 'was_building' => true],
            );
        });
    }
}
