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

    public int $draftSplitQuantity = ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS;

    /**
     * Selected writer user ids — never auto-filled.
     *
     * @var list<int|string>
     */
    public array $draftSplitWriterIds = [];

    public function mountInteractsWithDraftSplit(): void
    {
        $this->draftSplitQuantity = ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS;
        $this->draftSplitMode = SplitDraftContentProjectCommand::MODE_FIRST_N;
        $this->draftSplitWriterIds = [];
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
            ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
            $reviewed,
        );
        $this->draftSplitWriterIds = [];
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

    public function updatedDraftSplitWriterIds(): void
    {
        $this->draftSplitWriterIds = $this->orderedSelectedWriterIds();
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
        $writerIds = $this->orderedSelectedWriterIds();
        $this->draftSplitWriterIds = $writerIds;

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
        $this->draftSplitWriterIds = [];

        $moved = (int) ($result->metadata['moved_count'] ?? 0);
        $remaining = (int) ($result->metadata['remaining_count'] ?? 0);
        $executionIds = $result->metadata['execution_project_ids'] ?? [];
        if (! is_array($executionIds)) {
            $executionIds = [];
        }
        $executionId = (int) ($executionIds[0] ?? $result->metadata['execution_project_id'] ?? 0);
        $projectCount = count($executionIds) > 0 ? count($executionIds) : ($executionId > 0 ? 1 : 0);

        $notification = Notification::make()
            ->title(__('seo-content-ai::filament.projects.draft_split_success_title'))
            ->body(__('seo-content-ai::filament.projects.draft_split_success_body', [
                'moved' => $moved,
                'remaining' => $remaining,
                'projects' => $projectCount,
            ]))
            ->success();

        if ($executionId > 0) {
            try {
                $url = SeoProjectResource::getUrl('view', ['record' => $executionId]);
                $notification->actions([
                    NotificationAction::make('open')
                        ->label(__('seo-content-ai::filament.projects.draft_split_open_execution'))
                        ->url($url),
                ]);
            } catch (\Throwable) {
                // ignore URL resolution failures
            }
        }

        $notification->send();

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
     *   preview: list<array{user_id: int, user_name: string, item_count: int}>,
     *   insufficient_slots: int,
     *   insufficient_message: string,
     *   can_create: bool,
     *   selected_capacity: int,
     *   max: int
     * }
     */
    public function draftSplitUiState(): array
    {
        $project = $this->project;
        $now = Carbon::now()->startOfMonth();
        $capacity = app(ContentProjectWriterMonthlyCapacityService::class);
        $selector = $this->draftSplitModalOpen
            ? $capacity->writerSelectorPayload($now)
            : [
                'writers' => [],
                'month' => $now->format('Y-m-d'),
                'month_label' => $now->format('m/Y'),
                'max' => ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
            ];
        $empty = [
            'count' => 0,
            'reviewed_count' => 0,
            'selected' => 0,
            'start_month' => $now->format('Y-m-d'),
            'start_month_label' => $now->format('m/Y'),
            'writers' => $selector['writers'],
            'preview' => [],
            'insufficient_slots' => 0,
            'insufficient_message' => '',
            'can_create' => false,
            'selected_capacity' => 0,
            'max' => ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
        ];

        if (! $project instanceof SeoProject || ! $project->isDraftPlanning()) {
            return $empty;
        }

        $splitter = app(SplitDraftContentProjectService::class);
        $reviewed = $splitter->currentReviewedDraftItemCount($project);
        $total = $splitter->currentDraftItemCount($project);
        $writerIds = $this->orderedSelectedWriterIds($selector['writers']);
        $selectedCount = $this->selectedItemCount($reviewed);

        $previewRows = [];
        $insufficient = 0;
        $insufficientMessage = '';
        $selectedCapacity = 0;

        if ($reviewed > 0) {
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
                $selectedCapacity = (int) ($preview['selected_capacity'] ?? 0);
                $insufficient = (int) ($preview['insufficient_slots'] ?? 0);
                $insufficientMessage = (string) ($preview['insufficient_message'] ?? '');
                foreach ($preview['allocations'] ?? [] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $previewRows[] = [
                        'user_id' => (int) ($row['user_id'] ?? 0),
                        'user_name' => (string) ($row['user_name'] ?? ''),
                        'item_count' => (int) ($row['item_count'] ?? 0),
                    ];
                }
            } catch (\Throwable) {
                $previewRows = [];
            }
        }

        return [
            'count' => $reviewed,
            'reviewed_count' => $reviewed,
            'total_count' => $total,
            'selected' => $selectedCount,
            'start_month' => $now->format('Y-m-d'),
            'start_month_label' => $now->format('m/Y'),
            'writers' => $selector['writers'],
            'preview' => $previewRows,
            'insufficient_slots' => $insufficient,
            'insufficient_message' => $insufficientMessage,
            'can_create' => $reviewed > 0
                && $selectedCount > 0
                && $writerIds !== []
                && $insufficient < 1
                && $previewRows !== [],
            'selected_capacity' => $selectedCapacity,
            'max' => ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
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
     * @param  list<array<string, mixed>>|null  $writers
     * @return list<int>
     */
    protected function orderedSelectedWriterIds(?array $writers = null): array
    {
        $selected = [];
        foreach ($this->draftSplitWriterIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $selected[$id] = true;
            }
        }

        if ($writers === null) {
            $writers = app(ContentProjectWriterMonthlyCapacityService::class)
                ->writerSelectorPayload(Carbon::now()->startOfMonth())['writers'];
        }

        $ordered = [];
        foreach ($writers as $writer) {
            $id = (int) ($writer['id'] ?? 0);
            if ($id <= 0 || ! isset($selected[$id])) {
                continue;
            }
            if (! empty($writer['full'])) {
                continue;
            }
            $ordered[] = $id;
        }

        return $ordered;
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
