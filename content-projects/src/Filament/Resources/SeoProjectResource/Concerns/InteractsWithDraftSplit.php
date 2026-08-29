<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Carbon\Carbon;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Draft → execution Split / Activate all (ViewSeoProject).
 */
trait InteractsWithDraftSplit
{
    public bool $draftSplitModalOpen = false;

    /** first_n|all */
    public string $draftSplitMode = SplitDraftContentProjectCommand::MODE_FIRST_N;

    public int $draftSplitQuantity = ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS;

    /**
     * Included writer user ids (exclude-to-remove UX).
     * Default on open = all eligible real writers. Not reset on quantity/mode change.
     *
     * @var list<int|string>
     */
    public array $draftSplitIncludedUserIds = [];

    public function mountInteractsWithDraftSplit(): void
    {
        $this->draftSplitQuantity = ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS;
        $this->draftSplitMode = SplitDraftContentProjectCommand::MODE_FIRST_N;
        $this->draftSplitIncludedUserIds = [];
        $this->draftSplitModalOpen = false;
    }

    public function openDraftSplitModal(?string $preferredMode = null): void
    {
        $project = $this->requireProject();
        if (! $project->isDraftPlanning()) {
            return;
        }

        $splitter = app(SplitDraftContentProjectService::class);
        $reviewed = $splitter->currentReviewedDraftItemCount($project);
        if ($reviewed <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.draft_split_empty_title'))
                ->body(__('seo-content-ai::filament.projects.draft_split_empty_reviewed_body'))
                ->warning()
                ->send();

            return;
        }

        $mode = $preferredMode === SplitDraftContentProjectCommand::MODE_ALL
            ? SplitDraftContentProjectCommand::MODE_ALL
            : SplitDraftContentProjectCommand::MODE_FIRST_N;

        $this->draftSplitMode = $mode;
        $this->draftSplitQuantity = min(
            ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS,
            $reviewed,
        );
        $this->draftSplitIncludedUserIds = $this->defaultEligibleIncludedUserIds();
        $this->draftSplitModalOpen = true;
    }

    public function closeDraftSplitModal(): void
    {
        $this->draftSplitModalOpen = false;
    }

    public function updatedDraftSplitQuantity(): void
    {
        $this->clampDraftSplitInputs();
    }

    public function updatedDraftSplitMode(): void
    {
        $this->clampDraftSplitInputs();
    }

    public function excludeDraftSplitWriter(int|string $userId): void
    {
        $excludeId = (int) $userId;
        if ($excludeId <= 0) {
            return;
        }

        $this->draftSplitIncludedUserIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $this->draftSplitIncludedUserIds),
            static fn (int $id): bool => $id > 0 && $id !== $excludeId,
        ));
    }

    public function includeDraftSplitWriter(int|string $userId): void
    {
        $includeId = (int) $userId;
        if ($includeId <= 0) {
            return;
        }

        $writers = $this->currentWriterSelectorPayload()['writers'];
        $eligible = false;
        foreach ($writers as $writer) {
            if ((int) ($writer['id'] ?? 0) === $includeId) {
                $eligible = true;
                break;
            }
        }

        if (! $eligible) {
            return;
        }

        $included = [];
        foreach ($this->draftSplitIncludedUserIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $included[$id] = true;
            }
        }
        $included[$includeId] = true;

        $this->draftSplitIncludedUserIds = $this->orderedIncludedUserIds($writers, $included);
    }

    public function activateAllDraftItems(): void
    {
        $this->openDraftSplitModal(SplitDraftContentProjectCommand::MODE_ALL);
    }

    public function confirmDraftSplit(): void
    {
        $project = $this->requireProject();
        if (! $project->isDraftPlanning()) {
            return;
        }

        $this->clampDraftSplitInputs();
        $writerIds = $this->orderedIncludedUserIds();

        if ($writerIds === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.draft_split_failed'))
                ->body(__('seo-content-ai::filament.projects.draft_split_no_writers'))
                ->danger()
                ->send();

            return;
        }

        $mode = strtolower(trim($this->draftSplitMode));
        $quantity = null;

        if ($mode === SplitDraftContentProjectCommand::MODE_FIRST_N) {
            $quantity = max(1, (int) $this->draftSplitQuantity);
        } else {
            $mode = SplitDraftContentProjectCommand::MODE_ALL;
        }

        $idemKey = 'ui:split-draft:'.$project->getKey().':'.Str::uuid()->toString();

        $result = app(ContentProjectCommandBus::class)->dispatch(
            new SplitDraftContentProjectCommand(
                projectRef: (int) $project->getKey(),
                selectionMode: $mode,
                quantity: $quantity,
                itemRefs: [],
                dryRun: false,
                assigneeIds: $writerIds,
            ),
            ActorContext::user(
                auth()->id() !== null ? (int) auth()->id() : null,
                (int) ($project->site_id ?? 0) ?: null,
                $idemKey,
            ),
        );

        if (! $result->success) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.draft_split_failed'))
                ->body($result->message)
                ->danger()
                ->send();

            return;
        }

        $this->draftSplitModalOpen = false;
        $this->draftSplitIncludedUserIds = [];

        $moved = (int) ($result->metadata['moved_count'] ?? $result->metadata['assigned_items'] ?? 0);
        $createdCount = (int) ($result->metadata['created_count'] ?? count($result->metadata['created_projects'] ?? []));
        $reusedCount = (int) ($result->metadata['reused_count'] ?? count($result->metadata['reused_projects'] ?? []));
        $projectCount = (int) ($result->metadata['project_count'] ?? ($createdCount + $reusedCount));
        $redirectMonth = (string) ($result->metadata['redirect_month']
            ?? $result->metadata['month']
            ?? now()->format('Y-m'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $redirectMonth) === 1) {
            $redirectMonth = substr($redirectMonth, 0, 7);
        }

        $body = __('seo-content-ai::filament.projects.draft_split_success_body', [
            'moved' => $moved,
            'projects' => max(1, $projectCount),
            'created' => $createdCount,
            'reused' => $reusedCount,
        ]);

        $listUrl = null;
        try {
            $listUrl = SeoProjectResource::getUrl('index', [
                'month' => $redirectMonth,
                'tableFilters' => [
                    'month' => [
                        'month' => $redirectMonth.'-01',
                    ],
                ],
            ]);
        } catch (\Throwable) {
            $listUrl = null;
        }

        $notification = Notification::make()
            ->title(__('seo-content-ai::filament.projects.draft_split_success_title'))
            ->body($body)
            ->success();

        if (is_string($listUrl) && $listUrl !== '') {
            $notification->actions([
                NotificationAction::make('view_month')
                    ->label(__('seo-content-ai::filament.projects.draft_split_view_month_projects'))
                    ->url($listUrl),
            ]);
        }

        $notification->send();

        if (is_string($listUrl) && $listUrl !== '') {
            $this->redirect($listUrl, navigate: false);

            return;
        }

        $this->selectedTaskIds = [];
        $this->project = $project->fresh() ?? $project;
        $this->resetPage();
    }

    /**
     * @return array{
     *   count: int,
     *   reviewed_count: int,
     *   selected: int,
     *   start_month: string,
     *   start_month_label: string,
     *   writers: list<array<string, mixed>>,
     *   included_writers: list<array<string, mixed>>,
     *   excluded_writers: list<array<string, mixed>>,
     *   can_create: bool,
     *   max: int
     * }
     */
    public function draftSplitUiState(): array
    {
        $project = $this->project;
        $now = Carbon::now()->startOfMonth();
        $selector = $this->draftSplitModalOpen
            ? $this->currentWriterSelectorPayload($now)
            : [
                'writers' => [],
                'month' => $now->format('Y-m-d'),
                'month_label' => $now->format('m/Y'),
            ];
        $empty = [
            'count' => 0,
            'reviewed_count' => 0,
            'selected' => 0,
            'start_month' => $now->format('Y-m-d'),
            'start_month_label' => $now->format('m/Y'),
            'writers' => [],
            'included_writers' => [],
            'excluded_writers' => [],
            'can_create' => false,
            'max' => ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS,
        ];

        if (! $project instanceof SeoProject || ! $project->isDraftPlanning()) {
            return $empty;
        }

        $splitter = app(SplitDraftContentProjectService::class);
        $reviewed = $splitter->currentReviewedDraftItemCount($project);
        $total = $splitter->currentDraftItemCount($project);
        $writerIds = $this->orderedIncludedUserIds($selector['writers']);
        $selectedCount = $this->selectedItemCount($reviewed);

        $allocationByUser = [];
        $projectCountByUser = [];
        $hasPositiveAllocation = false;

        if ($reviewed > 0 && $writerIds !== []) {
            try {
                $preview = $splitter->preview(
                    $project,
                    $this->draftSplitMode === SplitDraftContentProjectCommand::MODE_ALL
                        ? SplitDraftContentProjectCommand::MODE_ALL
                        : SplitDraftContentProjectCommand::MODE_FIRST_N,
                    $this->draftSplitMode === SplitDraftContentProjectCommand::MODE_ALL ? null : $selectedCount,
                    [],
                    $writerIds,
                );
                foreach ($preview['allocations'] ?? [] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $userId = (int) ($row['user_id'] ?? 0);
                    $count = (int) ($row['item_count'] ?? 0);
                    if ($userId <= 0) {
                        continue;
                    }
                    $allocationByUser[$userId] = $count;
                    $projectCountByUser[$userId] = (int) ($row['project_count'] ?? 0);
                    if ($count > 0) {
                        $hasPositiveAllocation = true;
                    }
                }
            } catch (\Throwable) {
                $allocationByUser = [];
            }
        }

        $includedLookup = array_fill_keys($writerIds, true);
        $includedWriters = [];
        $excludedWriters = [];
        $allWriters = [];

        foreach ($selector['writers'] as $writer) {
            if (! is_array($writer)) {
                continue;
            }
            $id = (int) ($writer['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $current = (int) ($writer['current'] ?? 0);
            $included = isset($includedLookup[$id]);
            $newAllocation = $included ? (int) ($allocationByUser[$id] ?? 0) : 0;
            $projectCount = $included ? (int) ($projectCountByUser[$id] ?? 0) : 0;
            $row = [
                'id' => $id,
                'name' => (string) ($writer['name'] ?? ''),
                'current' => $current,
                'included' => $included,
                'new_allocation' => $newAllocation,
                'resulting' => $current + $newAllocation,
                'project_count' => $projectCount,
            ];
            $allWriters[] = $row;

            if ($included) {
                $includedWriters[] = $row;
            } else {
                $excludedWriters[] = $row;
            }
        }

        return [
            'count' => $reviewed,
            'reviewed_count' => $reviewed,
            'total_count' => $total,
            'selected' => $selectedCount,
            'start_month' => $now->format('Y-m-d'),
            'start_month_label' => $now->format('m/Y'),
            'writers' => $allWriters,
            'included_writers' => $includedWriters,
            'excluded_writers' => $excludedWriters,
            'can_create' => $reviewed > 0
                && $selectedCount > 0
                && $writerIds !== []
                && $hasPositiveAllocation,
            'max' => ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS,
        ];
    }

    protected function clampDraftSplitInputs(): void
    {
        $project = $this->project;
        if (! $project instanceof SeoProject) {
            return;
        }

        $reviewed = app(SplitDraftContentProjectService::class)->currentReviewedDraftItemCount($project);
        if ($reviewed < 1) {
            $this->draftSplitQuantity = 1;

            return;
        }

        $this->draftSplitQuantity = min(max(1, (int) $this->draftSplitQuantity), $reviewed);
    }

    /**
     * @return list<int>
     */
    protected function defaultEligibleIncludedUserIds(): array
    {
        $ids = [];
        foreach ($this->currentWriterSelectorPayload()['writers'] as $writer) {
            $id = (int) ($writer['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>|null  $writers
     * @param  array<int, true>|null  $includedLookup
     * @return list<int>
     */
    protected function orderedIncludedUserIds(?array $writers = null, ?array $includedLookup = null): array
    {
        if ($includedLookup === null) {
            $includedLookup = [];
            foreach ($this->draftSplitIncludedUserIds as $raw) {
                $id = (int) $raw;
                if ($id > 0) {
                    $includedLookup[$id] = true;
                }
            }
        }

        if ($writers === null) {
            $writers = $this->currentWriterSelectorPayload()['writers'];
        }

        $ordered = [];
        foreach ($writers as $writer) {
            $id = (int) ($writer['id'] ?? 0);
            if ($id <= 0 || ! isset($includedLookup[$id])) {
                continue;
            }
            $ordered[] = $id;
        }

        return $ordered;
    }

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     month_display?: string,
     *     writers: list<array<string, mixed>>
     * }
     */
    protected function currentWriterSelectorPayload(?Carbon $month = null): array
    {
        return app(ContentProjectWriterMonthlyCapacityService::class)
            ->writerSelectorPayload($month ?? Carbon::now()->startOfMonth());
    }

    protected function selectedItemCount(int $reviewed): int
    {
        if ($reviewed < 1) {
            return 0;
        }

        if (strtolower(trim($this->draftSplitMode)) === SplitDraftContentProjectCommand::MODE_ALL) {
            return $reviewed;
        }

        return min(max(1, (int) $this->draftSplitQuantity), $reviewed);
    }

    /**
     * @param  list<int|string>|array<int|string, mixed>  $ids
     * @return list<int>
     */
    protected function normalizeSelectedIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
