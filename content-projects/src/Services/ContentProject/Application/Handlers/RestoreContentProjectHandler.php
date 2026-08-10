<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use InvalidArgumentException;

final class RestoreContentProjectHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ArchiveContentProjectService $archiveService,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RestoreContentProjectCommand) {
            throw new InvalidArgumentException('Expected RestoreContentProjectCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at === null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::LIFECYCLE_INVALID,
                    'Project chưa được lưu trữ.',
                    $projectId,
                );
            }

            $fingerprint = $this->buildFingerprint($command->name(), $projectId);

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                $archive = $this->archiveService->getCurrentArchive($project);

                return $this->previewReady(
                    $projectId,
                    [],
                    $fingerprint,
                    [
                        'action' => 'restore',
                        'archive_id' => $archive !== null ? (int) $archive->getKey() : null,
                        'archived_at' => $project->archived_at?->toIso8601String(),
                    ],
                    requiresConfirmation: true,
                );
            }

            $confirmationFailure = $this->assertConfirmationToken(
                $command->confirmationToken ?? $actor->confirmationToken,
                $fingerprint,
                required: $this->requiresConfirmation($actor, $command->confirmationToken ?? $actor->confirmationToken),
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
                $this->businessLock->projectRestore($projectId),
                function () use ($project, $projectId, $userId): ContentProjectActionResult {
                    $archive = $this->archiveService->restore($project, $userId);

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::PROJECT_RESTORED,
                        'Project restored.',
                        $projectId,
                        metadata: [
                            'archive_id' => (int) $archive->getKey(),
                            'affected_count' => 1,
                            'workspace_reused' => false,
                        ],
                    );
                },
            );

            if ($result->success) {
                $this->consumeConfirmationToken($command->confirmationToken ?? $actor->confirmationToken);
            }

            return $result;
        });
    }
}
