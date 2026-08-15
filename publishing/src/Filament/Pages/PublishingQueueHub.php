<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithContentProjectPublishingActions;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RecoverStuckPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ReturnToContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UnscheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectAutoScheduleService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStuckPublishingDefinition;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Publishing Queue hub — nested under Content Projects nav, keeps slug /publishing-queue.
 * Navigation item is owned by SeoProjectResource::getNavigationItems() (parentItem).
 */
final class PublishingQueueHub extends SeoPanelPage
{
    use InteractsWithContentProjectPublishingActions;

    protected static ?string $slug = 'publishing-queue';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 5;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.publishing-queue-hub';

    public static function getNavigationParentItem(): ?string
    {
        return SeoProjectResource::getNavigationLabel();
    }

    #[Url]
    public ?int $projectId = null;

    #[Url(as: 'state')]
    public string $stateFilter = '';

    public ?SeoProject $project = null;

    public string $search = '';

    /** @var list<int> */
    public array $selectedTaskIds = [];

    public bool $bulkRunning = false;

    /** @var list<int> Presentation-only pending row ids */
    public array $pendingTaskIds = [];

    public ?string $pendingOp = null;

    /** updating | accepted | null — presentation only, not DB enum */
    public ?string $pendingPhase = null;

    public ?string $pendingOperationId = null;

    public bool $selectAllMatching = false;

    public string $autoMode = 'project_month';

    public string $autoStartAt = '';

    public int $autoIntervalMinutes = 15;

    public int $autoPerDay = 3;

    public string $autoDayStart = '09:00';

    public string $autoDayEnd = '17:00';

    public int $quickDays = 3;

    public string $quickStartTime = '08:00';

    /** quick submode: in_day | n_days */
    public string $quickSubmode = 'n_days';

    public int $inDayIntervalMinutes = 15;

    public string $recoverTarget = 'scheduled';

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.projects.publishing_queue_nav_label');
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canManageContentProjectWorkflow();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $this->resolveProject();
        if ($this->autoStartAt === '') {
            $this->autoStartAt = SystemDateTime::formatForInput(
                SystemDateTime::currentSystemTime()->addHour()
            ) ?? '';
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->project instanceof SeoProject
            ? __('seo-content-ai::filament.projects.publishing_queue_title', ['name' => (string) $this->project->name])
            : __('seo-content-ai::filament.projects.publishing_queue_hub_title');
    }

    public function updatedProjectId(): void
    {
        $this->resolveProject();
        $this->clearFilters();
    }

    public function updatedSearch(): void
    {
        $this->selectedTaskIds = [];
        $this->selectAllMatching = false;
    }

    public function updatedStateFilter(): void
    {
        $this->selectedTaskIds = [];
        $this->selectAllMatching = false;
    }

    private function resolveProject(): void
    {
        $this->project = null;
        if ($this->projectId === null || $this->projectId <= 0) {
            return;
        }

        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()->find($this->projectId);
        if (! $project instanceof SeoProject || ! SeoAccessControl::canAccessSite((int) ($project->site_id ?? 0))) {
            $this->projectId = null;

            return;
        }

        $this->project = $project;
    }

    /**
     * @return array{stats: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function getQueuePayloadProperty(): array
    {
        return app(ContentProjectPublishingQueueReadModel::class)->forHub(
            $this->project instanceof SeoProject ? (int) $this->project->getKey() : null,
            [
                'search' => $this->search,
                'state' => $this->stateFilter,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueueHealthProperty(): array
    {
        $siteIds = null;
        if ($this->project instanceof SeoProject) {
            $siteIds = [(int) ($this->project->site_id ?? 0)];
        }

        $connectionId = null;
        $current = \Omnichannel\Addons\Seo\Support\SeoConnectionContext::current();
        if ($current instanceof \App\Models\SeoDatabaseConnection) {
            $connectionId = (int) $current->getKey();
        }

        return app(ContentProjectQueueHealthService::class)->snapshot($siteIds, $connectionId);
    }

    public function getTimezoneLabelProperty(): string
    {
        return SystemDateTime::timezoneChip();
    }

    public function getTimezoneNameProperty(): string
    {
        return SystemDateTime::timezone();
    }

    public function getDateTimeSettingsUrlProperty(): ?string
    {
        if (! \Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsDateTime::canAccess()) {
            return null;
        }

        return \Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsDateTime::getUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAutoPreviewProperty(): array
    {
        if (! $this->project instanceof SeoProject) {
            return [
                'eligible_ids' => [],
                'excluded' => [],
                'slots' => [],
                'first_publish_at' => null,
                'last_publish_at' => null,
                'timezone' => SystemDateTime::timezone(),
                'blocked' => null,
            ];
        }

        return app(ContentProjectAutoScheduleService::class)->preview(
            $this->project,
            [],
            [
                'mode' => 'project_month',
                'day_start' => $this->autoDayStart,
                'day_end' => $this->autoDayEnd,
                'allow_reschedule' => true,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuickPreviewProperty(): array
    {
        if (! $this->project instanceof SeoProject) {
            return [
                'eligible_ids' => [],
                'excluded' => [],
                'slots' => [],
                'first_publish_at' => null,
                'last_publish_at' => null,
                'timezone' => SystemDateTime::timezone(),
                'blocked' => null,
                'suggested_max_interval' => null,
            ];
        }

        $options = $this->quickSubmode === 'in_day'
            ? [
                'mode' => 'in_day',
                'interval_minutes' => max(5, $this->inDayIntervalMinutes),
                'allow_reschedule' => true,
            ]
            : [
                'mode' => 'quick',
                'days' => max(1, $this->quickDays),
                'start_time' => $this->quickStartTime,
                'end_time' => $this->autoDayEnd,
                'allow_reschedule' => true,
            ];

        return app(ContentProjectAutoScheduleService::class)->preview($this->project, [], $options);
    }

    public function getStuckPublishingIdsProperty(): array
    {
        $ids = [];
        foreach ($this->queuePayload['rows'] ?? [] as $row) {
            if (! PublishingQueueStuckPublishingDefinition::matches($row)) {
                continue;
            }
            $ids[] = (int) ($row['task_id'] ?? 0);
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    public function applyStateFilter(string $state): void
    {
        $this->stateFilter = $state === $this->stateFilter ? '' : $state;
        $this->selectedTaskIds = [];
        $this->selectAllMatching = false;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->stateFilter = '';
        $this->selectedTaskIds = [];
        $this->selectAllMatching = false;
    }

    public function clearSelection(): void
    {
        $this->selectedTaskIds = [];
        $this->selectAllMatching = false;
    }

    public function selectPage(): void
    {
        $ids = [];
        foreach ($this->queuePayload['rows'] ?? [] as $row) {
            $id = (int) ($row['task_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $this->selectedTaskIds = array_values(array_unique($ids));
        $this->selectAllMatching = false;
    }

    public function selectAllMatchingResults(): void
    {
        $this->selectPage();
        $this->selectAllMatching = true;
    }

    public function togglePageSelection(): void
    {
        $pageIds = [];
        foreach ($this->queuePayload['rows'] ?? [] as $row) {
            $id = (int) ($row['task_id'] ?? 0);
            if ($id > 0) {
                $pageIds[] = $id;
            }
        }

        if ($pageIds === []) {
            return;
        }

        $selected = $this->selectedItemIds();
        $allSelected = count(array_diff($pageIds, $selected)) === 0;
        if ($allSelected) {
            $this->selectedTaskIds = array_values(array_diff($selected, $pageIds));
            $this->selectAllMatching = false;

            return;
        }

        $this->selectedTaskIds = array_values(array_unique(array_merge($selected, $pageIds)));
    }

    /**
     * @return list<int>
     */
    protected function selectedItemIds(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $this->selectedTaskIds,
        ), static fn (int $id): bool => $id > 0));
    }

    /** Temporary project for row actions when hub filter = all projects. */
    private ?SeoProject $actionProjectOverride = null;

    public function returnOne(int $taskId): void
    {
        $this->withProjectFromTask($taskId, function () use ($taskId): void {
            $this->dispatchPublishingCommand(new ReturnToContentProjectCommand(
                (int) $this->requireProject()->getKey(),
                [$taskId],
            ), 'return_to_content_project');
        });
    }

    public function bulkReturn(): void
    {
        $this->withProjectFromItems($this->selectedItemIds(), function (): void {
            $this->dispatchPublishingCommand(new ReturnToContentProjectCommand(
                (int) $this->requireProject()->getKey(),
                $this->selectedItemIds(),
            ), 'return_to_content_project');
        });
    }

    public function bulkSchedule(?string $at = null): void
    {
        $this->withProjectFromItems($this->selectedItemIds(), function () use ($at): void {
            $when = $at !== null && $at !== ''
                ? SystemDateTime::parseSystemInputToUtc($at)
                : SystemDateTime::currentSystemTime()->addHour()->utc();

            $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
                (int) $this->requireProject()->getKey(),
                $this->selectedItemIds(),
                $when,
            ), 'schedule');
        });
    }

    public function bulkScheduleInMinutes(int $minutes): void
    {
        $this->withProjectFromItems($this->selectedItemIds(), function () use ($minutes): void {
            $minutes = max(1, min(24 * 60, $minutes));
            $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
                (int) $this->requireProject()->getKey(),
                $this->selectedItemIds(),
                SystemDateTime::currentSystemTime()->addMinutes($minutes)->utc(),
            ), 'schedule');
        });
    }

    public function bulkScheduleTomorrowMorning(): void
    {
        $this->withProjectFromItems($this->selectedItemIds(), function (): void {
            $when = SystemDateTime::currentSystemTime()
                ->addDay()
                ->setTime(9, 0, 0)
                ->utc();

            $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
                (int) $this->requireProject()->getKey(),
                $this->selectedItemIds(),
                $when,
            ), 'schedule');
        });
    }

    public function bulkUnschedule(): void
    {
        $this->withProjectFromItems($this->selectedItemIds(), function (): void {
            $this->dispatchPublishingCommand(new UnscheduleProjectItemsCommand(
                (int) $this->requireProject()->getKey(),
                $this->selectedItemIds(),
            ), 'unschedule');
        });
    }

    public function bulkPublishNow(): void
    {
        $this->withProjectFromItems($this->selectedItemIds(), function (): void {
            $this->dispatchPublishingCommand(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand(
                (int) $this->requireProject()->getKey(),
                $this->selectedItemIds(),
            ), 'publish_now');
        });
    }

    public function bulkRetryPublish(): void
    {
        $this->withProjectFromItems($this->selectedItemIds(), function (): void {
            $this->dispatchPublishingCommand(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand(
                (int) $this->requireProject()->getKey(),
                $this->selectedItemIds(),
            ), 'retry');
        });
    }

    public function bulkReobserveWordPressStatus(): void
    {
        $ids = $this->selectedItemIds();
        if ($ids === []) {
            Notification::make()->title('Chọn ít nhất một bài.')->warning()->send();

            return;
        }

        $action = app(\Omnichannel\Addons\Publishing\Services\Publishing\ObservedWordPressStatusReconcileAction::class);
        $ok = 0;
        foreach ($ids as $taskId) {
            $task = SeoProjectTask::query()->find((int) $taskId);
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $result = $action->forTask($task);
            if (($result['ok'] ?? false) === true) {
                $ok++;
            }
        }

        Notification::make()
            ->title('Kiểm tra lại trạng thái')
            ->body('Đã đối soát '.$ok.'/'.count($ids).' bài với WordPress (không reload).')
            ->success()
            ->send();
        $this->refreshQueueHealth();
    }

    public function bulkCancelPublish(): void
    {
        $this->withProjectFromItems($this->selectedItemIds(), function (): void {
            $this->dispatchPublishingCommand(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand(
                (int) $this->requireProject()->getKey(),
                $this->selectedItemIds(),
            ), 'cancel');
        });
    }

    public function scheduleOneAt(int $taskId, string $at): void
    {
        $this->withProjectFromTask($taskId, function () use ($taskId, $at): void {
            $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
                (int) $this->requireProject()->getKey(),
                [$taskId],
                Carbon::parse($at, SystemDateTime::timezone())->utc(),
            ), 'schedule');
        });
    }

    public function scheduleOne(int $taskId): void
    {
        $this->scheduleOneInMinutes($taskId, 60);
    }

    public function scheduleOneInMinutes(int $taskId, int $minutes = 60): void
    {
        $this->withProjectFromTask($taskId, function () use ($taskId, $minutes): void {
            $minutes = max(1, min(24 * 60, $minutes));
            $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
                (int) $this->requireProject()->getKey(),
                [$taskId],
                SystemDateTime::currentSystemTime()->addMinutes($minutes)->utc(),
            ), 'schedule');
        });
    }

    public function scheduleOneTomorrowMorning(int $taskId): void
    {
        $this->withProjectFromTask($taskId, function () use ($taskId): void {
            $when = SystemDateTime::currentSystemTime()
                ->addDay()
                ->setTime(9, 0, 0)
                ->utc();

            $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
                (int) $this->requireProject()->getKey(),
                [$taskId],
                $when,
            ), 'schedule');
        });
    }

    public function unscheduleOne(int $taskId): void
    {
        $this->withProjectFromTask($taskId, function () use ($taskId): void {
            $this->dispatchPublishingCommand(new UnscheduleProjectItemsCommand(
                (int) $this->requireProject()->getKey(),
                [$taskId],
            ), 'unschedule');
        });
    }

    public function publishOneNow(int $taskId): void
    {
        $this->withProjectFromTask($taskId, function () use ($taskId): void {
            $this->dispatchPublishingCommand(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand(
                (int) $this->requireProject()->getKey(),
                [$taskId],
            ), 'publish_now');
        });
    }

    public function retryPublishOne(int $taskId): void
    {
        $this->withProjectFromTask($taskId, function () use ($taskId): void {
            $this->dispatchPublishingCommand(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand(
                (int) $this->requireProject()->getKey(),
                [$taskId],
            ), 'retry');
        });
    }

    public function cancelPublishOne(int $taskId): void
    {
        $this->withProjectFromTask($taskId, function () use ($taskId): void {
            $this->dispatchPublishingCommand(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand(
                (int) $this->requireProject()->getKey(),
                [$taskId],
            ), 'cancel');
        });
    }

    public function runProjectMonthAutoSchedule(): void
    {
        $this->dispatchPublishingCommand(new AutoScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            [],
            [
                'mode' => 'project_month',
                'day_start' => $this->autoDayStart,
                'day_end' => $this->autoDayEnd,
                'allow_reschedule' => true,
            ],
        ), 'auto_schedule');
    }

    public function runQuickSchedule(): void
    {
        $options = $this->quickSubmode === 'in_day'
            ? [
                'mode' => 'in_day',
                'interval_minutes' => max(5, $this->inDayIntervalMinutes),
                'allow_reschedule' => true,
            ]
            : [
                'mode' => 'quick',
                'days' => max(1, $this->quickDays),
                'start_time' => $this->quickStartTime,
                'end_time' => $this->autoDayEnd,
                'allow_reschedule' => true,
            ];

        $this->dispatchPublishingCommand(new AutoScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            [],
            $options,
        ), 'auto_schedule');
    }

    public function recoverStuckSelected(): void
    {
        $ids = $this->selectedItemIds();
        if ($ids === []) {
            $ids = $this->stuckPublishingIds;
        }

        $this->withProjectFromItems($ids, function () use ($ids): void {
            $this->dispatchPublishingCommand(new RecoverStuckPublishingCommand(
                (int) $this->requireProject()->getKey(),
                $ids,
                $this->recoverTarget,
                force: false,
            ), 'recover_stuck');
        });
    }

    public function recoverOne(int $taskId): void
    {
        $this->withProjectFromTask($taskId, function () use ($taskId): void {
            $this->dispatchPublishingCommand(new RecoverStuckPublishingCommand(
                (int) $this->requireProject()->getKey(),
                [$taskId],
                $this->recoverTarget,
                force: false,
            ), 'recover_stuck');
        });
    }

    public function forceRecoverOne(int $taskId): void
    {
        $this->withProjectFromTask($taskId, function () use ($taskId): void {
            $this->dispatchPublishingCommand(new RecoverStuckPublishingCommand(
                (int) $this->requireProject()->getKey(),
                [$taskId],
                $this->recoverTarget,
                force: true,
            ), 'recover_stuck');
        });
    }

    public function forceRecoverStuckSelected(): void
    {
        $ids = $this->selectedItemIds();
        if ($ids === []) {
            $ids = $this->stuckPublishingIds;
        }

        $this->withProjectFromItems($ids, function () use ($ids): void {
            $this->dispatchPublishingCommand(new RecoverStuckPublishingCommand(
                (int) $this->requireProject()->getKey(),
                $ids,
                $this->recoverTarget,
                force: true,
            ), 'recover_stuck');
        });
    }

    public function refreshQueueHealth(): void
    {
        // Livewire recomputes computed props on next render.
        unset($this->queueHealth, $this->queuePayload);
    }

    protected function getHeaderActions(): array
    {
        if (! $this->project instanceof SeoProject) {
            return [];
        }

        return [
            Actions\Action::make('back_to_project')
                ->label(__('seo-content-ai::filament.projects.ops_items_heading'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(SeoProjectResource::getUrl('view', ['record' => $this->project])),
        ];
    }

    protected function requireProject(): SeoProject
    {
        if ($this->actionProjectOverride instanceof SeoProject) {
            return $this->actionProjectOverride;
        }

        if ($this->project instanceof SeoProject) {
            return $this->project;
        }

        Notification::make()
            ->warning()
            ->title((string) __('seo-content-ai::filament.projects.publishing_queue_hub_select_project'))
            ->body((string) __('seo-content-ai::filament.projects.publishing_queue_hub_actions_disabled_hint'))
            ->send();

        throw new Halt;
    }

    /**
     * @param  callable(): void  $callback
     */
    private function withProjectFromTask(int $taskId, callable $callback): void
    {
        $this->withProjectFromItems([$taskId], $callback);
    }

    /**
     * @param  list<int>  $taskIds
     * @param  callable(): void  $callback
     */
    private function withProjectFromItems(array $taskIds, callable $callback): void
    {
        if ($this->project instanceof SeoProject || $this->actionProjectOverride instanceof SeoProject) {
            $callback();

            return;
        }

        $previous = $this->actionProjectOverride;
        $this->actionProjectOverride = $this->loadAccessibleProjectForTasks($taskIds);
        try {
            $callback();
        } finally {
            $this->actionProjectOverride = $previous;
        }
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function loadAccessibleProjectForTasks(array $taskIds): SeoProject
    {
        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $taskIds,
        ), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            Notification::make()
                ->warning()
                ->title((string) __('seo-content-ai::filament.projects.publishing_queue_hub_select_project'))
                ->body((string) __('seo-content-ai::filament.projects.queue_select_required'))
                ->send();

            throw new Halt;
        }

        $projectIds = SeoProjectTask::query()
            ->whereIn('id', $ids)
            ->pluck('project_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($projectIds->count() !== 1) {
            Notification::make()
                ->warning()
                ->title((string) __('seo-content-ai::filament.projects.publishing_queue_hub_select_project'))
                ->body((string) __('seo-content-ai::filament.projects.publishing_queue_hub_actions_disabled_hint'))
                ->send();

            throw new Halt;
        }

        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()->find((int) $projectIds->first());
        if (! $project instanceof SeoProject || ! SeoAccessControl::canAccessSite((int) ($project->site_id ?? 0))) {
            Notification::make()
                ->danger()
                ->title('Project not found')
                ->send();

            throw new Halt;
        }

        return $project;
    }
}
