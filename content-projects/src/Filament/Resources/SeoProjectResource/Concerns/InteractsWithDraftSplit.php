<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
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

    /** first_n|selected|all */
    public string $draftSplitMode = SplitDraftContentProjectCommand::MODE_FIRST_N;

    public int $draftSplitQuantity = 30;

    public string $draftSplitMonth = '';

    public string $draftSplitName = '';

    public function mountInteractsWithDraftSplit(): void
    {
        $this->draftSplitMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
        $this->draftSplitName = '';
        $this->draftSplitQuantity = 30;
        $this->draftSplitMode = SplitDraftContentProjectCommand::MODE_FIRST_N;
        $this->draftSplitModalOpen = false;
    }

    public function openDraftSplitModal(?string $preferredMode = null): void
    {
        $project = $this->requireProject();
        if (! $project->isDraftPlanning()) {
            return;
        }

        $count = app(SplitDraftContentProjectService::class)->currentDraftItemCount($project);
        if ($count <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.draft_split_empty_title'))
                ->body(__('seo-content-ai::filament.projects.draft_split_empty_body'))
                ->warning()
                ->send();

            return;
        }

        $selected = $this->normalizeSelectedIds($this->selectedTaskIds ?? []);
        $mode = $preferredMode ?? (
            $selected !== []
                ? SplitDraftContentProjectCommand::MODE_SELECTED
                : SplitDraftContentProjectCommand::MODE_FIRST_N
        );

        $this->draftSplitMode = $mode;
        $this->draftSplitQuantity = min(30, $count);
        $this->draftSplitMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
        $domain = null;
        try {
            $domain = $project->site?->domain;
        } catch (\Throwable) {
            $domain = null;
        }
        $this->draftSplitName = SeoProject::defaultExecutionName($this->draftSplitMonth, is_string($domain) ? $domain : null);
        $this->draftSplitModalOpen = true;
    }

    public function closeDraftSplitModal(): void
    {
        $this->draftSplitModalOpen = false;
    }

    public function activateAllDraftItems(): void
    {
        $project = $this->requireProject();
        if (! $project->isDraftPlanning()) {
            return;
        }

        $count = app(SplitDraftContentProjectService::class)->currentDraftItemCount($project);
        if ($count <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.draft_split_empty_title'))
                ->body(__('seo-content-ai::filament.projects.draft_split_empty_body'))
                ->warning()
                ->send();

            return;
        }

        $this->draftSplitMode = SplitDraftContentProjectCommand::MODE_ALL;
        $this->draftSplitMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
        $domain = null;
        try {
            $domain = $project->site?->domain;
        } catch (\Throwable) {
            $domain = null;
        }
        $this->draftSplitName = SeoProject::defaultExecutionName($this->draftSplitMonth, is_string($domain) ? $domain : null);
        $this->confirmDraftSplit();
    }

    public function confirmDraftSplit(): void
    {
        $project = $this->requireProject();
        if (! $project->isDraftPlanning()) {
            return;
        }

        $mode = strtolower(trim($this->draftSplitMode));
        $itemRefs = [];
        $quantity = null;

        if ($mode === SplitDraftContentProjectCommand::MODE_SELECTED) {
            $itemRefs = $this->normalizeSelectedIds($this->selectedTaskIds ?? []);
            if ($itemRefs === []) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.projects.draft_split_no_selection'))
                    ->warning()
                    ->send();

                return;
            }
        } elseif ($mode === SplitDraftContentProjectCommand::MODE_FIRST_N) {
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
                itemRefs: $itemRefs,
                targetMonth: $this->draftSplitMonth !== '' ? $this->draftSplitMonth : null,
                projectName: trim($this->draftSplitName) !== '' ? trim($this->draftSplitName) : null,
                dryRun: false,
            ),
            ActorContext::user(
                auth()->id() !== null ? (int) auth()->id() : null,
                (int) ($project->site_id ?? 0) ?: null,
                $idemKey,
            ),
        );

        $this->draftSplitModalOpen = false;

        if (! $result->success) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.draft_split_failed'))
                ->body($result->message)
                ->danger()
                ->send();

            return;
        }

        $moved = (int) ($result->metadata['moved_count'] ?? 0);
        $remaining = (int) ($result->metadata['remaining_count'] ?? 0);
        $executionId = (int) ($result->metadata['execution_project_id'] ?? 0);

        $notification = Notification::make()
            ->title(__('seo-content-ai::filament.projects.draft_split_success_title'))
            ->body(__('seo-content-ai::filament.projects.draft_split_success_body', [
                'moved' => $moved,
                'remaining' => $remaining,
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
     * @return array{count: int, selected: int, month_options: array<string, string>, default_name: string}
     */
    public function draftSplitUiState(): array
    {
        $project = $this->project;
        if (! $project instanceof SeoProject || ! $project->isDraftPlanning()) {
            return [
                'count' => 0,
                'selected' => 0,
                'month_options' => [],
                'default_name' => '',
            ];
        }

        $count = app(SplitDraftContentProjectService::class)->currentDraftItemCount($project);
        $selected = count($this->normalizeSelectedIds($this->selectedTaskIds ?? []));
        $now = Carbon::now()->startOfMonth();
        $options = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $now->copy()->addMonthsNoOverflow($i);
            $options[$m->format('Y-m-d')] = $m->format('F Y');
        }

        return [
            'count' => $count,
            'selected' => $selected,
            'month_options' => $options,
            'default_name' => SeoProject::defaultExecutionName($now),
        ];
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
