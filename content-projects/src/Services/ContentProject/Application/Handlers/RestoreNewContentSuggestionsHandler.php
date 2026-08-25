<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionDecisionService;
use InvalidArgumentException;

final class RestoreNewContentSuggestionsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SeoAuditSuggestionDecisionService $decisions,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RestoreNewContentSuggestionsCommand) {
            throw new InvalidArgumentException('Expected RestoreNewContentSuggestionsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if (! $project->isDraftPlanning()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::SUGGESTIONS_PLANNING_DRAFT_ONLY,
                    'Suggestion decisions are Draft-only.',
                    $projectId,
                );
            }

            $result = $this->decisions->restoreFingerprints($project, $command->fingerprints);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::SUGGESTIONS_RESTORED,
                (int) $result['restored'].' suggestion(s) restored.',
                $projectId,
                metadata: $result,
            );
        });
    }
}
