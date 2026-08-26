<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectCreated;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Quotas\ContentProjectQuotaGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateContentProjectHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectQuotaGuard $quota,
        private readonly ContentProjectDomainEvents $domainEvents,
        private readonly SeoProjectTaskSyncService $taskSync,
        private readonly PlanningDraftResolver $planningDrafts,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CreateContentProjectCommand) {
            throw new InvalidArgumentException('Expected CreateContentProjectCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $attrs = $command->attributes;
            $siteId = (int) ($attrs['site_id'] ?? $actor->siteId ?? 0);
            if ($siteId <= 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'site_id is required.',
                );
            }

            if ($actor->siteId !== null && $actor->siteId > 0 && $actor->siteId !== $siteId) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    'Cannot create project for another site.',
                );
            }

            if (! $this->quota->canCreateProject($actor, $siteId)) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::QUOTA_DENIED,
                    'Quota denied for create project.',
                );
            }

            $name = trim((string) ($attrs['name'] ?? ''));
            if ($name === '') {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'name is required.',
                );
            }

            $userId = isset($attrs['user_id']) ? (int) $attrs['user_id'] : $actor->actorId;
            $totalTasks = isset($attrs['total_tasks']) ? (int) $attrs['total_tasks'] : 0;

            $status = (string) ($attrs['status'] ?? SeoProject::STATUS_PENDING);
            $kind = (string) ($attrs['kind'] ?? SeoProject::KIND_MONTHLY);
            $month = $attrs['month'] ?? null;
            if ($status === SeoProject::STATUS_DRAFT) {
                if ($month === null || $month === '') {
                    $month = SeoProject::draftCompatibilityMonth();
                }
                $kind = SeoProject::KIND_MONTHLY;

                // Product invariant: one reusable Planning Draft per site.
                $existing = $this->planningDrafts->findPlanningDraftForSite($siteId);
                if ($existing instanceof SeoProject) {
                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::PROJECT_CREATED,
                        'Planning Draft already exists for this domain.',
                        (int) $existing->getKey(),
                        metadata: [
                            'site_id' => $siteId,
                            'reused_existing_draft' => true,
                            'tasks_synced' => false,
                        ],
                    );
                }
            }

            $project = DB::connection('omi_seo_ai')->transaction(function () use ($attrs, $siteId, $name, $userId, $totalTasks, $status, $kind, $month): SeoProject {
                return SeoProject::query()->create([
                    'name' => $name,
                    'site_id' => $siteId,
                    'month' => $month,
                    'status' => $status,
                    'kind' => $kind,
                    'user_id' => $userId,
                    'total_tasks' => $totalTasks,
                    'description' => $attrs['description'] ?? null,
                ]);
            });

            $projectId = (int) $project->getKey();

            if ($command->tasksData !== []) {
                $this->businessLock->withLock(
                    $this->businessLock->projectSchedule($projectId),
                    function () use ($project, $command, $siteId): void {
                        $sanitized = $this->taskSync->sanitizeTasksData(
                            $command->tasksData,
                            $siteId > 0 ? $siteId : null,
                        );
                        DB::connection('omi_seo_ai')->transaction(function () use ($project, $sanitized): void {
                            $this->taskSync->sync($project->fresh() ?? $project, $sanitized);
                        });
                    },
                );
                $project->refresh();
            }

            $this->domainEvents->dispatchAfterCommit(new ContentProjectCreated(
                $projectId,
                $siteId,
                $actor->actorId,
            ));

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::PROJECT_CREATED,
                'Project created.',
                $projectId,
                metadata: [
                    'site_id' => $siteId,
                    'tasks_synced' => $command->tasksData !== [],
                ],
            );
        });
    }
}
