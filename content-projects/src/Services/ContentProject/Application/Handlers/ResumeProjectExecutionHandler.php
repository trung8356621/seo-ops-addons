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
use InvalidArgumentException;

final class ResumeProjectExecutionHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
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

            $run = $this->resolveRun($projectId, $command->executionRef);
            if (! $run instanceof SeoProjectRun) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No stopping execution found.',
                    $projectId,
                );
            }

            if ((string) $run->status !== SeoProjectRun::STATUS_STOPPING) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::LIFECYCLE_INVALID,
                    'Execution is not stopping.',
                    $projectId,
                    metadata: [
                        'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                        'status' => (string) $run->status,
                    ],
                );
            }

            $run->forceFill([
                'status' => SeoProjectRun::STATUS_RUNNING,
            ])->saveQuietly();

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::EXECUTION_RESUMED,
                'Execution resumed.',
                $projectId,
                metadata: [
                    'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                ],
            );
        });
    }

    private function resolveRun(int $projectId, string|int|null $executionRef): ?SeoProjectRun
    {
        if ($executionRef !== null) {
            $runId = $this->resolveExecutionId($executionRef);

            return SeoProjectRun::query()
                ->where('project_id', $projectId)
                ->whereKey($runId)
                ->where('status', SeoProjectRun::STATUS_STOPPING)
                ->first();
        }

        return SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->where('status', SeoProjectRun::STATUS_STOPPING)
            ->orderByDesc('id')
            ->first();
    }

    private function resolveExecutionId(string|int $executionRef): int
    {
        if (is_int($executionRef) || ctype_digit((string) $executionRef)) {
            return (int) $executionRef;
        }

        return ContentProjectPublicRef::decodeExecution((string) $executionRef);
    }
}
