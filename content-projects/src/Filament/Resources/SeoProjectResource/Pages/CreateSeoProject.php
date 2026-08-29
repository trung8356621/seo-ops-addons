<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoCreateRecord;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateSeoProject extends SeoCreateRecord
{
    protected static string $resource = SeoProjectResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $monthRaw = request()->query('month');
        $month = ContentProjectMonthContext::parseOrNull(
            is_string($monthRaw) ? $monthRaw : null,
        );
        if ($month !== null) {
            $data['month'] = ContentProjectMonthContext::toDateString($month);
        }

        $staffId = $this->resolveStaffIdFromQuery();
        if ($staffId <= 0) {
            return $data;
        }

        $service = app(ContentProjectStaffAvailabilityService::class);
        $monthForCheck = $month ?? ContentProjectMonthContext::normalize(
            isset($data['month']) ? (string) $data['month'] : null,
        );

        $isAssignable = $service->baseAssignableStaffQuery()->whereKey($staffId)->exists()
            || array_key_exists($staffId, SeoProjectResource::userSelectOptions());

        if (! $isAssignable) {
            return $data;
        }

        $data['user_id'] = $staffId;
        $isUnassigned = $service->isUnassigned($staffId, $monthForCheck);
        $data['unassigned_staff_ids'] = $isUnassigned ? [$staffId] : [];
        $data['assign_from_unassigned'] = $isUnassigned;

        return $data;
    }

    public function create(bool $another = false): void
    {
        // Chặn double/triple submit Livewire (tạo nhiều project cùng tháng).
        if ($this->formSaveExplicitlyLocked) {
            return;
        }

        $this->lockFormSave();

        try {
            parent::create($another);
        } catch (\Throwable $exception) {
            $this->unlockFormSave();

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = SeoProjectResource::normalizeProjectSiteId($data);

        if (! empty($data['month'])) {
            $data['month'] = Carbon::parse($data['month'])->startOfMonth()->format('Y-m-d');
            $writerId = (int) ($data['user_id'] ?? 0);
            $data['name'] = $writerId > 0
                ? app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService::class)
                    ->nextExecutionProjectName($writerId, $data['month'])
                : SeoProject::defaultNameFromMonth($data['month']);
        }

        $month = (string) ($data['month'] ?? '');
        $siteId = isset($data['site_id']) ? (int) $data['site_id'] : 0;

        $tasksData = $data['tasks_data'] ?? [];
        $projectSiteId = $siteId > 0 ? $siteId : null;
        $sanitized = app(SeoProjectTaskSyncService::class)->sanitizeTasksData($tasksData, $projectSiteId);

        $projectStub = new SeoProject;
        $projectStub->id = 0;
        $projectStub->site_id = $projectSiteId;
        app(SeoProjectTaskSyncService::class)->assertNoDuplicateTasksData($projectStub, is_array($tasksData) ? $tasksData : []);

        app(SeoProjectTaskSyncService::class)->assertWithinMonthlyLimit($data['month'], $sanitized);

        $data['total_tasks'] = count($sanitized);
        $data['status'] = SeoProject::STATUS_MANUAL;
        $data['kind'] = SeoProject::KIND_MONTHLY;

        unset($data['tasks_data'], $data['unassigned_staff_ids'], $data['assign_from_unassigned']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $formState = $this->form->getState();
        $tasksData = $formState['tasks_data'] ?? [];
        $month = (string) ($data['month'] ?? '');
        $siteId = (int) ($data['site_id'] ?? 0);

        $staffService = app(ContentProjectStaffAvailabilityService::class);
        $taskSync = app(SeoProjectTaskSyncService::class);
        $bus = app(ContentProjectCommandBus::class);
        $authUserId = auth()->id() !== null ? (int) auth()->id() : null;

        return $staffService->withAssignmentLock(function () use (
            $data,
            $tasksData,
            $month,
            $siteId,
            $taskSync,
            $bus,
            $authUserId,
        ): Model {
            // One-project-per-user/month uniqueness retired — any eligible staff may be assigned.

            $lockToken = substr($taskSync->tasksSignature(
                $tasksData,
                $siteId > 0 ? $siteId : null,
            ), 0, 12);
            $idempotencyKey = sprintf(
                'ui:%d:create:%d:%s:%s',
                $authUserId ?? 0,
                $siteId,
                $month,
                $lockToken,
            );

            $result = $bus->dispatch(
                new CreateContentProjectCommand($data, $tasksData),
                ActorContext::user(
                    $authUserId,
                    $siteId > 0 ? $siteId : null,
                    $idempotencyKey,
                ),
            );

            if (! $result->success) {
                if ($result->code === ContentProjectActionCodes::VALIDATION_FAILED) {
                    throw ValidationException::withMessages([
                        'data' => $result->message,
                    ]);
                }

                throw new RuntimeException($result->message);
            }

            $projectId = $result->projectId;
            if ($projectId === null || $projectId <= 0) {
                throw new RuntimeException('Project created but ID missing from command result.');
            }

            /** @var SeoProject $project */
            $project = SeoProject::query()->findOrFail($projectId);

            return $project;
        });
    }

    protected function getRedirectUrl(): string
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        return SeoProjectResource::getUrl('edit', ['record' => $record]);
    }

    /**
     * Retired: one project per staff/month is no longer enforced.
     */
    private function shouldEnforceStaffMonthUniqueness(): bool
    {
        return false;
    }

    private function resolveStaffIdFromQuery(): int
    {
        $staff = (int) request()->query('staff', 0);
        if ($staff > 0) {
            return $staff;
        }

        return (int) request()->query('writer_id', 0);
    }
}
