<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Carbon\Carbon;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Draft → execution Split / Activate all (ViewSeoProject / Content Planning).
 * Target month is chosen in the Create project modal — Draft stays monthless.
 */
trait InteractsWithDraftSplit
{
    public bool $draftSplitModalOpen = false;

    /** first_n|all */
    public string $draftSplitMode = SplitDraftContentProjectCommand::MODE_FIRST_N;

    public int $draftSplitQuantity = ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS;

    /** Target execution month YYYY-MM — defaults to current on modal open. */
    public string $draftSplitTargetMonth = '';

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
        $this->draftSplitTargetMonth = ContentProjectMonthContext::current();
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
        $reviewed = $splitter->currentReviewedDraftItemCount(
            $project,
            $this->resolvePublishDraftSiteScope(),
        );
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
        // Quantity = reviewed pool size (user may lower). MAX_EXECUTION_PROJECT_ITEMS is
        // packing per project (overflow -2/-3), NOT the Publish batch ceiling.
        $this->draftSplitQuantity = max(1, $reviewed);
        $this->draftSplitTargetMonth = ContentProjectMonthContext::current();
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

    public function updatedDraftSplitTargetMonth(mixed $value): void
    {
        $this->draftSplitTargetMonth = ContentProjectMonthContext::normalize(
            is_string($value) ? $value : null,
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getDraftSplitTargetMonthOptions(): array
    {
        return ContentProjectMonthContext::selectOptions();
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
        $targetMonth = ContentProjectMonthContext::normalize($this->draftSplitTargetMonth ?: null);

        if ($writerIds === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.draft_split_failed'))
                ->body(__('seo-content-ai::filament.projects.draft_split_no_writers'))
                ->danger()
                ->send();

            return;
        }

        $eligible = $this->resolvePublishEligibleTaskIds($project);
        if ($eligible === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.draft_split_empty_title'))
                ->body(__('seo-content-ai::filament.projects.draft_split_empty_reviewed_body'))
                ->danger()
                ->send();

            return;
        }

        $mode = strtolower(trim($this->draftSplitMode));
        $quantity = null;
        $itemRefs = $eligible;
        $selectionMode = SplitDraftContentProjectCommand::MODE_SELECTED;

        if ($mode === SplitDraftContentProjectCommand::MODE_FIRST_N) {
            $quantity = max(1, (int) $this->draftSplitQuantity);
            $itemRefs = array_slice($eligible, 0, $quantity);
        } elseif ($this->resolvePublishDraftSiteScope() === null
            && $this->normalizeSelectedIds($this->selectedTaskIds ?? []) === []
        ) {
            // Global Shared Draft overview — keep legacy first_n / all semantics.
            $selectionMode = $mode === SplitDraftContentProjectCommand::MODE_ALL
                ? SplitDraftContentProjectCommand::MODE_ALL
                : SplitDraftContentProjectCommand::MODE_FIRST_N;
            $itemRefs = [];
            if ($selectionMode === SplitDraftContentProjectCommand::MODE_FIRST_N) {
                $quantity = max(1, (int) $this->draftSplitQuantity);
            }
        }

        $idemKey = 'ui:split-draft:'.$project->getKey().':'.Str::uuid()->toString();

        $result = app(ContentProjectCommandBus::class)->dispatch(
            new SplitDraftContentProjectCommand(
                projectRef: (int) $project->getKey(),
                selectionMode: $selectionMode,
                quantity: $quantity,
                itemRefs: $itemRefs,
                dryRun: false,
                assigneeIds: $writerIds,
                targetMonth: $targetMonth,
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
        $unallocated = (int) ($result->metadata['unallocated_count'] ?? 0);
        $redirectMonth = (string) ($result->metadata['redirect_month']
            ?? $result->metadata['month']
            ?? $targetMonth);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $redirectMonth) === 1) {
            $redirectMonth = substr($redirectMonth, 0, 7);
        }
        $redirectMonth = ContentProjectMonthContext::normalize($redirectMonth);

        $body = $unallocated > 0
            ? __('seo-content-ai::filament.projects.draft_split_partial_capacity_body', [
                'moved' => $moved,
                'unallocated' => $unallocated,
            ])
            : __('seo-content-ai::filament.projects.draft_split_success_body', [
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
     *   target_month: string,
     *   target_month_label: string,
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
        $target = ContentProjectMonthContext::normalize($this->draftSplitTargetMonth ?: null);
        $targetCarbon = Carbon::parse(ContentProjectMonthContext::toDateString($target))->startOfMonth();
        $selector = $this->draftSplitModalOpen
            ? $this->currentWriterSelectorPayload($targetCarbon)
            : [
                'writers' => [],
                'month' => ContentProjectMonthContext::toDateString($target),
                'month_label' => ContentProjectMonthContext::display($target),
            ];
        $empty = [
            'count' => 0,
            'reviewed_count' => 0,
            'selected' => 0,
            'start_month' => ContentProjectMonthContext::toDateString($target),
            'start_month_label' => ContentProjectMonthContext::display($target),
            'target_month' => $target,
            'target_month_label' => ContentProjectMonthContext::display($target),
            'writers' => [],
            'included_writers' => [],
            'excluded_writers' => [],
            'can_create' => false,
            'max' => ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS,
            'domain_rows' => [],
            'domain_count' => 0,
            'domain_warning' => null,
            'writer_count' => 0,
            'target_month_days' => (int) $targetCarbon->daysInMonth,
        ];

        if (! $project instanceof SeoProject || ! $project->isDraftPlanning()) {
            return $empty;
        }

        $splitter = app(SplitDraftContentProjectService::class);
        $siteScope = $this->resolvePublishDraftSiteScope();
        $reviewed = $splitter->currentReviewedDraftItemCount($project, $siteScope);
        // Domain-scoped Publish reports the eligible (reviewed) pool, not whole Shared Draft.
        $total = $siteScope !== null
            ? $reviewed
            : $splitter->currentDraftItemCount($project);
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
                    $target,
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
            $capacity = max(0, (int) ($writer['capacity'] ?? 0));
            $remaining = (int) ($writer['remaining'] ?? ($capacity - $current));
            $included = isset($includedLookup[$id]);
            $newAllocation = $included ? (int) ($allocationByUser[$id] ?? 0) : 0;
            $projectCount = $included ? (int) ($projectCountByUser[$id] ?? 0) : 0;
            $row = [
                'id' => $id,
                'name' => (string) ($writer['name'] ?? ''),
                'current' => $current,
                'capacity' => $capacity,
                'remaining' => $remaining,
                'active' => (int) ($writer['active'] ?? $current),
                'archived' => (int) ($writer['archived'] ?? 0),
                'included' => $included,
                'new_allocation' => $newAllocation,
                'resulting' => $current + $newAllocation,
                'project_count' => $projectCount,
                'assignable' => (bool) ($writer['assignable'] ?? ($capacity > 0 && $remaining > 0)),
                'capacity_zero' => $capacity === 0,
                'capacity_full' => $capacity > 0 && $remaining <= 0,
            ];
            $allWriters[] = $row;

            if ($included) {
                $includedWriters[] = $row;
            } else {
                $excludedWriters[] = $row;
            }
        }

        $domainPreview = $this->publishDomainDistributionPreview($project, $selectedCount, $targetCarbon);

        return [
            'count' => $reviewed,
            'reviewed_count' => $reviewed,
            'total_count' => $total,
            'selected' => $selectedCount,
            'start_month' => ContentProjectMonthContext::toDateString($target),
            'start_month_label' => ContentProjectMonthContext::display($target),
            'target_month' => $target,
            'target_month_label' => ContentProjectMonthContext::display($target),
            'writers' => $allWriters,
            'included_writers' => $includedWriters,
            'excluded_writers' => $excludedWriters,
            'can_create' => $reviewed > 0
                && $selectedCount > 0
                && $writerIds !== []
                && $hasPositiveAllocation,
            'max' => ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS,
            'domain_rows' => $domainPreview['rows'],
            'domain_count' => $domainPreview['domain_count'],
            'domain_warning' => $domainPreview['warning'],
            'writer_count' => count($includedWriters),
            'target_month_days' => (int) $targetCarbon->daysInMonth,
        ];
    }

    /**
     * Domain distribution for the Publish selection (reviewed pool / first-N slice).
     *
     * @return array{
     *     rows: list<array{site_id: int, domain: string, count: int, percent: float, articles_per_day: float}>,
     *     domain_count: int,
     *     warning: array{domain: string, count: int, total: int, percent: float, articles_per_day: float}|null
     * }
     */
    protected function publishDomainDistributionPreview(SeoProject $project, int $selectedCount, Carbon $targetMonth): array
    {
        $empty = ['rows' => [], 'domain_count' => 0, 'warning' => null];
        if ($selectedCount < 1) {
            return $empty;
        }

        $eligible = $this->resolvePublishEligibleTaskIds($project);
        if ($eligible === []) {
            return $empty;
        }

        $mode = strtolower(trim($this->draftSplitMode));
        $ids = $mode === SplitDraftContentProjectCommand::MODE_ALL
            ? $eligible
            : array_slice($eligible, 0, max(1, $selectedCount));

        if ($ids === []) {
            return $empty;
        }

        $counts = SeoProjectTask::query()
            ->whereIn('id', $ids)
            ->selectRaw('COALESCE(site_id, 0) as site_id, COUNT(*) as item_count')
            ->groupByRaw('COALESCE(site_id, 0)')
            ->orderByDesc('item_count')
            ->get();

        $siteIds = [];
        foreach ($counts as $row) {
            $sid = (int) ($row->site_id ?? 0);
            if ($sid > 0) {
                $siteIds[] = $sid;
            }
        }

        $domains = [];
        if ($siteIds !== []) {
            foreach (\App\Models\Site::query()->whereIn('id', $siteIds)->get(['id', 'domain']) as $site) {
                $domains[(int) $site->getKey()] = trim((string) ($site->domain ?? ''));
            }
        }

        $total = max(1, array_sum(array_map(
            static fn ($row): int => (int) ($row->item_count ?? 0),
            $counts->all(),
        )));
        $days = max(1, (int) $targetMonth->daysInMonth);
        $rows = [];
        $top = null;

        foreach ($counts as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            $count = (int) ($row->item_count ?? 0);
            if ($count < 1) {
                continue;
            }
            $domain = $siteId > 0 ? ($domains[$siteId] ?? '') : '';
            if ($domain === '') {
                $domain = $siteId > 0 ? '#'.$siteId : '(no site)';
            }
            $percent = round(($count / $total) * 100, 1);
            $perDay = round($count / $days, 1);
            $entry = [
                'site_id' => $siteId,
                'domain' => $domain,
                'count' => $count,
                'percent' => $percent,
                'articles_per_day' => $perDay,
            ];
            $rows[] = $entry;
            if ($top === null || $count > (int) $top['count']) {
                $top = $entry;
            }
        }

        $warning = null;
        // Multi-domain concentration: ≥2 domains, top ≥60%, top ≥30 items.
        if (
            $top !== null
            && count($rows) >= 2
            && (int) $top['count'] >= 30
            && (float) $top['percent'] >= 60.0
        ) {
            $warning = [
                'domain' => (string) $top['domain'],
                'count' => (int) $top['count'],
                'total' => $total,
                'percent' => (float) $top['percent'],
                'articles_per_day' => (float) $top['articles_per_day'],
            ];
        }

        return [
            'rows' => $rows,
            'domain_count' => count($rows),
            'warning' => $warning,
        ];
    }

    protected function clampDraftSplitInputs(): void
    {
        $project = $this->project;
        if (! $project instanceof SeoProject) {
            return;
        }

        $this->draftSplitTargetMonth = ContentProjectMonthContext::normalize(
            $this->draftSplitTargetMonth !== '' ? $this->draftSplitTargetMonth : null,
        );

        $reviewed = app(SplitDraftContentProjectService::class)->currentReviewedDraftItemCount(
            $project,
            $this->resolvePublishDraftSiteScope(),
        );
        if ($reviewed < 1) {
            $this->draftSplitQuantity = 1;

            return;
        }

        $this->draftSplitQuantity = min(max(1, (int) $this->draftSplitQuantity), $reviewed);
    }

    /**
     * Publish / Split eligible scope from Draft list projection (?draft_domain=).
     * null = entire Shared Draft; 0 = unassigned; >0 = concrete Site.
     */
    protected function resolvePublishDraftSiteScope(): ?int
    {
        if (! property_exists($this, 'draftDomainFilter')) {
            return null;
        }

        $domain = strtolower(trim((string) $this->draftDomainFilter));
        if ($domain === '' || $domain === 'all') {
            return null;
        }
        if ($domain === '0') {
            return 0;
        }
        if (! ctype_digit($domain)) {
            return null;
        }

        $siteId = (int) $domain;

        return $siteId > 0 ? $siteId : null;
    }

    /**
     * Priority: explicit selected rows ∩ current domain projection → domain projection → all.
     *
     * @return list<int>
     */
    protected function resolvePublishEligibleTaskIds(SeoProject $project): array
    {
        $scoped = app(SplitDraftContentProjectService::class)
            ->orderedReviewedDraftTaskIds($project, $this->resolvePublishDraftSiteScope());

        $selected = $this->normalizeSelectedIds($this->selectedTaskIds ?? []);
        if ($selected === []) {
            return $scoped;
        }

        $lookup = array_fill_keys($scoped, true);
        $hit = [];
        foreach ($selected as $id) {
            if (isset($lookup[$id])) {
                $hit[] = $id;
            }
        }

        return $hit !== [] ? $hit : $scoped;
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
            // Capacity 0 writers stay visible but are not auto-included for allocation.
            $capacity = (int) ($writer['capacity'] ?? 0);
            if ($capacity <= 0) {
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
     *     default_capacity?: int,
     *     team_capacity?: int,
     *     writers: list<array<string, mixed>>
     * }
     */
    protected function currentWriterSelectorPayload(?Carbon $month = null): array
    {
        $resolved = $month ?? Carbon::parse(
            ContentProjectMonthContext::toDateString(
                ContentProjectMonthContext::normalize($this->draftSplitTargetMonth ?: null),
            ),
        )->startOfMonth();

        return app(ContentProjectWriterMonthlyCapacityService::class)
            ->writerSelectorPayload($resolved);
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
