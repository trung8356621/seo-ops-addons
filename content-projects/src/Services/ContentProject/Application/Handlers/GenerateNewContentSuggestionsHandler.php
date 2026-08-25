<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Jobs\GenerateNewContentSuggestionsJob;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use App\Models\Site;
use InvalidArgumentException;

final class GenerateNewContentSuggestionsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly NewContentSuggestionPlannerService $planner,
        private readonly SitePrimaryLanguageService $primaryLanguage,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof GenerateNewContentSuggestionsCommand) {
            throw new InvalidArgumentException('Expected GenerateNewContentSuggestionsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Cannot generate suggestions on archived project.',
                    $projectId,
                );
            }

            if (! $project->isDraftPlanning()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::SUGGESTIONS_PLANNING_DRAFT_ONLY,
                    'Add AI suggestions to a Draft project.',
                    $projectId,
                );
            }

            $siteId = (int) ($project->site_id ?? 0);
            $site = $siteId > 0 ? Site::query()->find($siteId) : null;
            if (! $site instanceof Site) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Project domain is required.',
                    $projectId,
                );
            }

            $language = $this->primaryLanguage->resolvePrimaryLanguage($site);
            if ($language === null || trim($language) === '') {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PRIMARY_LANGUAGE_MISSING,
                    'Primary language is not configured.',
                    $projectId,
                    metadata: [
                        'site_id' => $siteId,
                    ],
                );
            }

            $options = NewContentSuggestionOptions::normalize(array_merge($command->options, [
                'quantity' => $command->quantity,
            ]));

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return ContentProjectActionResult::ok(
                    ContentProjectActionCodes::PREVIEW_READY,
                    'Preview ready.',
                    $projectId,
                    metadata: [
                        'project_id' => $projectId,
                        'quantity' => $options['quantity'],
                        'primary_language' => trim($language),
                        'direction' => $options['direction'],
                        'focus' => $options['focus'],
                        'post_type' => $options['post_type'],
                        'requires_confirmation' => false,
                    ],
                );
            }

            $queued = $this->planner->queueGeneration($project, $options, $actor->actorId);
            $runId = (int) ($queued['planner_run_id'] ?? 0);

            if (! (bool) ($queued['already_active'] ?? false) && $runId > 0) {
                GenerateNewContentSuggestionsJob::dispatch(
                    $runId,
                    $projectId,
                    (int) ($actor->actorId ?? 0),
                );
            }

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::PROCESSING,
                sprintf(
                    'Generating %d new content suggestions…',
                    (int) ($queued['requested'] ?? $options['quantity']),
                ),
                $projectId,
                metadata: [
                    'requested' => (int) ($queued['requested'] ?? $options['quantity']),
                    'generated' => 0,
                    'added' => 0,
                    'duplicates_skipped' => 0,
                    'rejected_skipped' => 0,
                    'planner_run_id' => $runId,
                    'status' => (string) ($queued['status'] ?? 'queued'),
                    'already_active' => (bool) ($queued['already_active'] ?? false),
                    'primary_language' => trim($language),
                ],
            );
        });
    }
}
