<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectExecutionCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftExecutionGuard;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunRecoverableState;
use App\Support\RuntimeLogger;
use InvalidArgumentException;

final class ResumeProjectExecutionHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectRunEngine $runEngine,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ResumeProjectExecutionCommand) {
            throw new InvalidArgumentException('Expected ResumeProjectExecutionCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $draftBlock = ContentProjectDraftExecutionGuard::rejectIfDraft($project, $projectId);
            if ($draftBlock !== null) {
                return $draftBlock;
            }

            $run = $this->resolveRun($projectId, $command->executionRef);
            if (! $run instanceof SeoProjectRun) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No resumable execution found.',
                    $projectId,
                );
            }

            if (! $this->isResumable($run)) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::LIFECYCLE_INVALID,
                    'Execution is not resumable (stopping or recoverable failed only).',
                    $projectId,
                    metadata: [
                        'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                        'status' => (string) $run->status,
                    ],
                );
            }

            // Engine owns stopping→running / recoverable→running + next pending dispatch.
            try {
                $this->runEngine->resume($run);
            } catch (\Throwable $e) {
                RuntimeLogger::report($e, [
                    'endpoint' => 'content_project.resume_execution',
                    'project_id' => $projectId,
                    'run_id' => (int) $run->getKey(),
                ]);

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    'Resume failed: '.$e->getMessage(),
                    $projectId,
                    metadata: [
                        'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                    ],
                );
            }

            $run->refresh();

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::EXECUTION_RESUMED,
                'Execution resumed.',
                $projectId,
                metadata: [
                    'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                    'status' => (string) $run->status,
                    'engine_resumed' => true,
                ],
            );
        });
    }

    private function resolveRun(int $projectId, string|int|null $executionRef): ?SeoProjectRun
    {
        if ($executionRef !== null) {
            $runId = $this->resolveExecutionId($executionRef);
            $run = SeoProjectRun::query()
                ->where('project_id', $projectId)
                ->whereKey($runId)
                ->first();

            return $run instanceof SeoProjectRun && $this->isResumable($run) ? $run : null;
        }

        $stopping = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->where('status', SeoProjectRun::STATUS_STOPPING)
            ->orderByDesc('id')
            ->first();
        if ($stopping instanceof SeoProjectRun) {
            return $stopping;
        }

        $failedCandidates = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->where('status', SeoProjectRun::STATUS_FAILED)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        foreach ($failedCandidates as $candidate) {
            if ($candidate instanceof SeoProjectRun && $this->isResumable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isResumable(SeoProjectRun $run): bool
    {
        if ((string) $run->status === SeoProjectRun::STATUS_STOPPING) {
            return true;
        }

        return ContentProjectRunRecoverableState::isRecoverableRun($run);
    }

    private function resolveExecutionId(string|int $executionRef): int
    {
        if (is_int($executionRef) || ctype_digit((string) $executionRef)) {
            return (int) $executionRef;
        }

        return ContentProjectPublicRef::decodeExecution((string) $executionRef);
    }
}
