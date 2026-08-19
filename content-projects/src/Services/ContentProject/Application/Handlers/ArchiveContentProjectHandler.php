<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectArchived;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use InvalidArgumentException;

final class ArchiveContentProjectHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ArchiveContentProjectService $archiveService,
        private readonly ContentProjectDomainEvents $domainEvents,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ArchiveContentProjectCommand) {
            throw new InvalidArgumentException('Expected ArchiveContentProjectCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $gate = $this->archiveService->archiveGate($project);
            $warnings = [];

            if (! $gate['can_archive'] && is_string($gate['blocked_reason']) && $gate['blocked_reason'] !== '') {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::LIFECYCLE_INVALID,
                    $gate['blocked_reason'],
                    $projectId,
                    metadata: ['gate' => $gate],
                );
            }

            if ($gate['requires_waiting_publish_confirm'] && ! $command->confirmWaitingPublish) {
                $warnings[] = (string) __('seo-content-ai::filament.projects.archive_waiting_publish_confirm_required', [
                    'count' => $gate['waiting_publish'],
                ]);
            }

            if ($gate['requires_hidden_stale_runs_confirm'] && ! $command->confirmHiddenStaleRuns) {
                $warnings[] = (string) __('seo-content-ai::filament.projects.archive_hidden_stale_runs_confirm_required', [
                    'count' => $gate['hidden_stale_runs'],
                ]);
            }

            $fingerprint = $this->buildFingerprint($command->name(), $projectId, [
                'note' => $command->note,
                'confirm_waiting_publish' => $command->confirmWaitingPublish,
                'confirm_hidden_stale_runs' => $command->confirmHiddenStaleRuns,
            ]);

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    [],
                    $fingerprint,
                    [
                        'action' => 'archive',
                        'gate' => $gate,
                        'note' => $command->note,
                        'confirm_waiting_publish' => $command->confirmWaitingPublish,
                        'confirm_hidden_stale_runs' => $command->confirmHiddenStaleRuns,
                    ],
                    $warnings,
                    requiresConfirmation: true,
                );
            }

            $token = $command->confirmationToken ?? $actor->confirmationToken;
            $confirmationFailure = $this->assertConfirmationToken(
                $token,
                $fingerprint,
                required: $this->requiresConfirmation($actor, $token),
                projectId: $projectId,
            );
            if ($confirmationFailure instanceof ContentProjectActionResult) {
                return $confirmationFailure;
            }

            $userId = (int) ($actor->actorId ?? 0);
            if ($userId <= 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    'Actor user id is required.',
                    $projectId,
                );
            }

            $result = $this->businessLock->withLock(
                $this->businessLock->projectArchive($projectId),
                function () use ($project, $projectId, $command, $userId): ContentProjectActionResult {
                    $archive = $this->archiveService->archive(
                        $project,
                        $userId,
                        $command->note,
                        $command->confirmWaitingPublish,
                        $command->confirmHiddenStaleRuns,
                    );

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::PROJECT_ARCHIVED,
                        'Project archived.',
                        $projectId,
                        metadata: [
                            'archive_id' => (int) $archive->getKey(),
                            'affected_count' => 1,
                        ],
                    );
                },
            );

            if ($result->success) {
                $this->consumeConfirmationToken($command->confirmationToken ?? $actor->confirmationToken);
                $this->domainEvents->dispatchAfterCommit(new ContentProjectArchived(
                    $projectId,
                    (int) ($result->metadata['archive_id'] ?? 0),
                    $actor->actorId,
                ));
            }

            return $result;
        });
    }
}
