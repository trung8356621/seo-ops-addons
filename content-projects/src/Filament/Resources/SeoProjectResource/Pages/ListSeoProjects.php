<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCompactSuccessService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthBalancePlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthBalanceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectListBucket;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthChartPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Throwable;

class ListSeoProjects extends ListRecords
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.list-seo-projects';

    /** Month context YYYY-MM — SINGLE SOURCE OF TRUTH for table + charts. */
    public string $planningMonth = '';

    /** High-level list bucket: all|project|archived */
    public string $projectType = ContentProjectListBucket::ALL;

    /** @var array<string, mixed>|null Request-local cache for forMonth() (domain + writer share one query set). */
    private ?array $monthWorkloadCache = null;

    /** @var array<string, mixed>|null Request-local Site Planning overview for list matrix. */
    private ?array $monthlyPlanningCache = null;

    public function mount(): void
    {
        parent::mount();

        $this->planningMonth = $this->resolvePlanningMonthFromRequest();
        $this->projectType = $this->resolveProjectTypeFromRequest();
        $this->syncToolbarFiltersToTableState();
    }

    protected function getTableQuery(): Builder
    {
        // No global Domain scope — projects are domain-neutral; items own site_id.
        // Include archived rows so bucket=archived / all can surface them.
        $query = parent::getTableQuery()
            ->where(function (Builder $builder): void {
                $builder
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            });

        return ContentProjectListBucket::apply(
            $query,
            $this->projectType,
            ContentProjectMonthContext::toDateString($this->planningMonth ?: null),
        );
    }

    /**
     * Queue health is secondary — rendered compact in the page view, not as header cards.
     *
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [];
    }

    /**
     * Compact domain chart — item.site_id aggregate via MonthlyWorkloadService.
     *
     * @return array<string, mixed>
     */
    public function getDomainWorkloadChart(): array
    {
        return app(ContentProjectMonthChartPresenter::class)
            ->presentDomain($this->monthWorkload());
    }

    /**
     * Compact writer chart — project assignee aggregate + team capacity progress.
     *
     * @return array<string, mixed>
     */
    public function getWriterWorkloadChart(): array
    {
        return app(ContentProjectMonthChartPresenter::class)
            ->presentWriter($this->monthWorkload());
    }

    /**
     * Read-only Site Planning matrix for Projects list (planning_month SSOT).
     * Uses overviewForProjectsList(): same archive/history semantics as Planner overview,
     * but excludes Global Legacy import shells (aligned with Articles-by-domain workload).
     *
     * @return array{
     *     months: list<array<string, mixed>>,
     *     year_groups: list<array{year: int, span: int}>,
     *     rows: list<array<string, mixed>>,
     *     active_month: string
     * }
     */
    public function getMonthlyPlanningMatrix(): array
    {
        return $this->monthlyPlanningCache ??= app(SitePlanningReadModel::class)
            ->overviewForProjectsList(null, $this->planningMonth ?: null);
    }

    /**
     * @return array<string, mixed>
     */
    private function monthWorkload(): array
    {
        return $this->monthWorkloadCache ??= app(ContentProjectMonthlyWorkloadService::class)
            ->forMonth($this->planningMonth ?: null);
    }
    /**
     * Compact secondary queue status for managers.
     *
     * @return array{healthy: bool, label: string, detail: string|null}
     */
    public function getCompactQueueStatus(): array
    {
        $siteIds = SeoAccessControl::accessibleSiteIds();
        $connectionId = null;
        $current = \Omnichannel\Addons\Seo\Support\SeoConnectionContext::current();
        if ($current instanceof \App\Models\SeoDatabaseConnection) {
            $connectionId = (int) $current->getKey();
        }

        $health = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService::class)
            ->snapshot($siteIds !== [] ? $siteIds : null, $connectionId);

        $failed = (int) ($health['failed'] ?? 0);
        $retrying = (int) ($health['retrying'] ?? 0);
        $healthy = $failed === 0 && $retrying === 0 && (bool) ($health['runner_healthy'] ?? true);

        $formatter = new \Omnichannel\Addons\ContentProjects\Support\ContentProject\OperationalStatusFormatter();
        $worker = $formatter->formatWorker($health['last_worker_run'] ?? null);
        $workerText = ! empty($worker['empty']) ? null : (string) ($worker['text'] ?? '');

        if ($healthy) {
            return [
                'healthy' => true,
                'label' => (string) __('seo-content-ai::filament.projects.queue_healthy'),
                'detail' => $workerText !== null && $workerText !== ''
                    ? (string) __('seo-content-ai::filament.projects.queue_last_worker', ['at' => $workerText])
                    : null,
            ];
        }

        $parts = [];
        if ($failed > 0) {
            $parts[] = (string) __('seo-content-ai::filament.projects.queue_failed_compact', ['count' => $failed]);
        }
        if ($retrying > 0) {
            $parts[] = (string) __('seo-content-ai::filament.projects.queue_retrying_compact', ['count' => $retrying]);
        }

        return [
            'healthy' => false,
            'label' => implode(' · ', $parts) !== '' ? implode(' · ', $parts) : (string) __('seo-content-ai::filament.projects.queue_unhealthy'),
            'detail' => $workerText,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('compact_success_items')
                ->label(__('seo-content-ai::filament.projects.compact_success'))
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('gray')
                ->visible(fn (): bool => SeoAccessControl::canMutateContentProjects())
                ->modalHeading(__('seo-content-ai::filament.projects.compact_success_heading'))
                ->modalDescription(__('seo-content-ai::filament.projects.compact_success_subtitle'))
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.compact_success_confirm'))
                ->form(fn (): array => $this->compactSuccessFormSchema())
                ->action(function (array $data): void {
                    abort_unless(SeoAccessControl::canMutateContentProjects(), 403);
                    $month = ContentProjectMonthContext::normalize($this->planningMonth ?: null);

                    try {
                        $result = app(ContentProjectCompactSuccessService::class)
                            ->execute(
                                0,
                                $month,
                                auth()->id() ? (int) auth()->id() : null,
                            );

                        $movedDone = (int) ($result['moved_generator_done'] ?? 0);
                        $movedWork = (int) ($result['moved_not_done'] ?? 0);
                        $skipped = (int) ($result['skipped_count'] ?? $result['totals']['skipped'] ?? 0);

                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.compact_success_done'))
                            ->body((string) __('seo-content-ai::filament.projects.compact_success_done_body', [
                                'moved' => $movedDone,
                                'work' => $movedWork,
                                'skipped' => $skipped,
                            ]))
                            ->success()
                            ->send();

                        $this->resetTable();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.compact_success_failed'))
                            ->body($exception->validator->errors()->first() ?: $exception->getMessage())
                            ->danger()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.compact_success_failed'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('balance_months')
                ->label(__('seo-content-ai::filament.projects.balance_months'))
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->visible(fn (): bool => SeoAccessControl::canMutateContentProjects())
                ->modalHeading(__('seo-content-ai::filament.projects.balance_months_heading'))
                ->modalDescription(__('seo-content-ai::filament.projects.balance_months_subtitle'))
                ->modalWidth(MaxWidth::ThreeExtraLarge)
                ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.balance_months_confirm'))
                ->form(fn (): array => $this->balanceMonthsFormSchema())
                ->action(function (array $data): void {
                    abort_unless(SeoAccessControl::canMutateContentProjects(), 403);

                    try {
                        $siteId = (int) ($data['site_id'] ?? 0);
                        $months = is_array($data['months'] ?? null) ? $data['months'] : [];
                        $mode = is_string($data['mode'] ?? null)
                            ? (string) $data['mode']
                            : ContentProjectMonthBalancePlanner::DEFAULT_MODE;
                        $fingerprint = is_string($data['fingerprint'] ?? null) ? (string) $data['fingerprint'] : null;

                        $result = app(ContentProjectMonthBalanceService::class)->apply(
                            $siteId,
                            $months,
                            $fingerprint,
                            auth()->id() ? (int) auth()->id() : null,
                            $mode,
                        );

                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.balance_months_done'))
                            ->body((string) __('seo-content-ai::filament.projects.balance_months_done_body', [
                                'moved' => (int) ($result['moved_count'] ?? 0),
                                'domain' => (string) ($result['domain'] ?? ''),
                            ]))
                            ->success()
                            ->send();

                        $this->resetTable();
                        $this->monthWorkloadCache = null;
                        $this->monthlyPlanningCache = null;
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.balance_months_failed'))
                            ->body($exception->validator->errors()->first() ?: $exception->getMessage())
                            ->danger()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.balance_months_failed'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('product_gallery_canary')
                ->label(__('PG Canary fixture'))
                ->icon('heroicon-o-beaker')
                ->color('warning')
                ->visible(fn (): bool => \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryCanaryAccess::allowsUi())
                ->url(fn (): string => \Omnichannel\Addons\Commerce\Filament\Pages\ProductGalleryCanaryPage::getUrl()),
            Actions\Action::make('open_site_archive')
                ->label(__('seo-content-ai::filament.projects.open_site_archive'))
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->visible(fn (): bool => SeoAccessControl::canViewProjectArchives())
                ->url(fn (): string => SeoProjectResource::getUrl('archive')),
            Actions\CreateAction::make()
                ->url(fn (): string => $this->createProjectUrl()),
        ];
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    private function balanceMonthsFormSchema(): array
    {
        $active = ContentProjectMonthContext::normalize($this->planningMonth ?: null);
        $previous = ContentProjectMonthContext::shift($active, -1);
        $chipMonths = ContentProjectMonthContext::nearbyMonths($active, 2);
        $chipOptions = [];
        foreach ($chipMonths as $month) {
            $chipOptions[$month] = ContentProjectMonthContext::shortLabel($month).' '.substr($month, 0, 4);
        }

        $syncFingerprint = function (Set $set, Get $get): void {
            $siteId = (int) ($get('site_id') ?? 0);
            $months = is_array($get('months')) ? $get('months') : [];
            $mode = is_string($get('mode') ?? null)
                ? (string) $get('mode')
                : ContentProjectMonthBalancePlanner::DEFAULT_MODE;
            if ($siteId <= 0 || count($months) < 2) {
                $set('fingerprint', '');

                return;
            }
            try {
                $preview = app(ContentProjectMonthBalanceService::class)->preview($siteId, $months, $mode);
                $set('fingerprint', (string) ($preview['fingerprint'] ?? ''));
            } catch (Throwable) {
                $set('fingerprint', '');
            }
        };

        return [
            Forms\Components\Select::make('site_id')
                ->label(__('seo-content-ai::filament.projects.balance_months_domain'))
                ->options(function () use ($active, $previous): array {
                    $months = ContentProjectMonthContext::nearbyMonths($active, 2);
                    if (! in_array($previous, $months, true)) {
                        $months[] = $previous;
                    }
                    if (! in_array($active, $months, true)) {
                        $months[] = $active;
                    }

                    return app(ContentProjectMonthBalanceService::class)->domainOptionsForMonths($months);
                })
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated($syncFingerprint),
            Forms\Components\ToggleButtons::make('months')
                ->label(__('seo-content-ai::filament.projects.balance_months_months'))
                ->options($chipOptions)
                ->multiple()
                ->inline()
                ->required()
                ->default([$previous, $active])
                ->live()
                ->afterStateUpdated($syncFingerprint),
            Forms\Components\ToggleButtons::make('mode')
                ->label(__('seo-content-ai::filament.projects.balance_months_mode'))
                ->options([
                    ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER => (string) __('seo-content-ai::filament.projects.balance_months_mode_earlier'),
                    ContentProjectMonthBalancePlanner::MODE_EVEN => (string) __('seo-content-ai::filament.projects.balance_months_mode_even'),
                    ContentProjectMonthBalancePlanner::MODE_FILL_LATER => (string) __('seo-content-ai::filament.projects.balance_months_mode_later'),
                ])
                ->default(ContentProjectMonthBalancePlanner::DEFAULT_MODE)
                ->inline()
                ->grouped()
                ->required()
                ->live()
                ->afterStateUpdated($syncFingerprint),
            Forms\Components\Hidden::make('fingerprint')->default(''),
            Forms\Components\Placeholder::make('balance_preview')
                ->label(__('seo-content-ai::filament.projects.balance_months_preview'))
                ->content(function (Get $get): HtmlString {
                    $siteId = (int) ($get('site_id') ?? 0);
                    $months = is_array($get('months')) ? $get('months') : [];
                    $mode = is_string($get('mode') ?? null)
                        ? (string) $get('mode')
                        : ContentProjectMonthBalancePlanner::DEFAULT_MODE;
                    if ($siteId <= 0) {
                        return new HtmlString(
                            '<p class="text-sm text-gray-500">'.e((string) __('seo-content-ai::filament.projects.balance_months_pick_domain')).'</p>'
                        );
                    }
                    if (count($months) < 2) {
                        return new HtmlString(
                            '<p class="text-sm text-gray-500">'.e((string) __('seo-content-ai::filament.projects.balance_months_pick_months')).'</p>'
                        );
                    }
                    try {
                        $preview = app(ContentProjectMonthBalanceService::class)->preview($siteId, $months, $mode);

                        return new HtmlString($this->renderBalanceMonthsPreviewHtml($preview));
                    } catch (Throwable $exception) {
                        return new HtmlString(
                            '<p class="text-sm text-danger-600">'.e($exception->getMessage()).'</p>'
                        );
                    }
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function renderBalanceMonthsPreviewHtml(array $preview): string
    {
        $html = '<div class="space-y-4 text-sm">';
        $html .= '<p class="m-0 font-medium leading-6">'.e((string) __('seo-content-ai::filament.projects.balance_months_preview_title', [
            'domain' => (string) ($preview['domain'] ?? ''),
        ])).'</p>';

        $html .= '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">'
            .'<table class="min-w-full text-sm"><thead><tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-900/60 dark:text-gray-400">'
            .'<th class="px-3 py-2.5">'.e((string) __('seo-content-ai::filament.projects.balance_months_col_month')).'</th>'
            .'<th class="px-3 py-2.5">'.e((string) __('seo-content-ai::filament.projects.balance_months_col_before')).'</th>'
            .'<th class="px-3 py-2.5">'.e((string) __('seo-content-ai::filament.projects.balance_months_col_after')).'</th>'
            .'</tr></thead><tbody>';

        foreach ($preview['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $html .= '<tr class="border-t border-gray-100 dark:border-white/10">'
                .'<td class="px-3 py-2.5 font-medium text-gray-900 dark:text-gray-100">'.e((string) ($row['month_label'] ?? '')).'</td>'
                .'<td class="px-3 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">'.(int) ($row['current'] ?? 0).'</td>'
                .'<td class="px-3 py-2.5 tabular-nums font-semibold text-gray-900 dark:text-gray-50">'.(int) ($row['after'] ?? 0).'</td>'
                .'</tr>';
        }
        $html .= '</tbody></table></div>';

        $html .= '<div class="space-y-1.5 pt-0.5">';
        $html .= '<p class="m-0 text-sm text-gray-600 dark:text-gray-300">'.e((string) __('seo-content-ai::filament.projects.balance_months_stat_will_move', [
            'count' => (int) ($preview['move_count'] ?? 0),
        ])).'</p>';

        $fixedTotal = (int) ($preview['fixed_total'] ?? 0);
        if ($fixedTotal > 0) {
            $html .= '<p class="m-0 text-sm text-gray-500 dark:text-gray-400">'.e((string) __('seo-content-ai::filament.projects.balance_months_stat_fixed_stay', [
                'count' => $fixedTotal,
            ])).'</p>';
        }
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    private function compactSuccessFormSchema(): array
    {
        $month = ContentProjectMonthContext::normalize($this->planningMonth ?: null);

        return [
            Forms\Components\Placeholder::make('compact_month')
                ->label(__('seo-content-ai::filament.projects.planning_month'))
                ->content(ContentProjectMonthContext::display($month)),
            Forms\Components\Placeholder::make('compact_notice')
                ->content(new HtmlString(
                    '<p class="text-sm text-gray-600 dark:text-gray-300">'
                    .e((string) __('seo-content-ai::filament.projects.compact_success_no_new_project'))
                    .'</p>'
                )),
            Forms\Components\Placeholder::make('compact_preview')
                ->label(__('seo-content-ai::filament.projects.compact_success_preview'))
                ->content(function () use ($month): HtmlString {
                    try {
                        $plan = app(ContentProjectCompactSuccessService::class)->preview(0, $month);

                        return new HtmlString($this->renderCompactSuccessPreviewHtml($plan));
                    } catch (Throwable $exception) {
                        return new HtmlString(
                            '<p class="text-sm text-danger-600">'.e($exception->getMessage()).'</p>'
                        );
                    }
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function renderCompactSuccessPreviewHtml(array $plan): string
    {
        $totals = is_array($plan['totals'] ?? null) ? $plan['totals'] : [];
        $skippedSummary = is_array($plan['skipped_summary'] ?? null) ? $plan['skipped_summary'] : [];
        $html = '<div class="space-y-3 text-sm">';
        $html .= '<p><strong>'.e((string) ($plan['domain'] ?? '')).'</strong> · '
            .e((string) ($plan['month_label'] ?? '')).'</p>';

        $doneNames = array_map(
            static fn (array $row): string => (string) ($row['name'] ?? ''),
            array_filter(is_array($plan['done_buckets'] ?? null) ? $plan['done_buckets'] : [], 'is_array'),
        );
        $workNames = array_map(
            static fn (array $row): string => (string) ($row['name'] ?? ''),
            array_filter(is_array($plan['work_buckets'] ?? null) ? $plan['work_buckets'] : [], 'is_array'),
        );

        $html .= '<ul class="list-disc pl-5 text-gray-700 dark:text-gray-200">'
            .'<li>'.e((string) __('seo-content-ai::filament.projects.compact_success_stat_projects', [
                'count' => (int) ($totals['projects'] ?? 0),
            ])).'</li>'
            .'<li>'.e((string) __('seo-content-ai::filament.projects.compact_success_stat_generator_done', [
                'count' => (int) ($totals['generator_done'] ?? $totals['done'] ?? 0),
            ])).'</li>'
            .'<li>'.e((string) __('seo-content-ai::filament.projects.compact_success_stat_not_done', [
                'count' => (int) ($totals['not_done'] ?? $totals['unfinished'] ?? 0),
            ])).'</li>'
            .'<li>'.e((string) __('seo-content-ai::filament.projects.compact_success_stat_unsafe', [
                'count' => (int) ($totals['unsafe'] ?? $totals['skipped'] ?? 0),
            ])).'</li>'
            .'<li>'.e((string) __('seo-content-ai::filament.projects.compact_success_stat_moves_done', [
                'count' => (int) ($totals['moves_generator_done'] ?? 0),
            ])).'</li>'
            .'<li>'.e((string) __('seo-content-ai::filament.projects.compact_success_stat_moves_work', [
                'count' => (int) ($totals['moves_not_done'] ?? 0),
            ])).'</li>'
            .'</ul>';

        if ($doneNames !== []) {
            $html .= '<p class="text-xs">'.e((string) __('seo-content-ai::filament.projects.compact_success_done_buckets', [
                'names' => implode(', ', $doneNames),
            ])).'</p>';
        }
        if ($workNames !== []) {
            $html .= '<p class="text-xs">'.e((string) __('seo-content-ai::filament.projects.compact_success_work_buckets', [
                'names' => implode(', ', $workNames),
            ])).'</p>';
        }

        if ($skippedSummary !== []) {
            $html .= '<ul class="list-disc pl-5 text-xs text-gray-600 dark:text-gray-300">';
            foreach ([
                'active_running' => 'compact_success_skip_active',
                'failed' => 'compact_success_skip_failed',
                'pending' => 'compact_success_skip_pending',
                'missing_generated_content' => 'compact_success_skip_missing_content',
                'scheduled_published_unsafe' => 'compact_success_skip_scheduled',
                'capacity_limit' => 'compact_success_skip_capacity',
                'wrong_domain_month' => 'compact_success_skip_wrong_scope',
            ] as $key => $langKey) {
                $count = (int) ($skippedSummary[$key] ?? 0);
                if ($count <= 0) {
                    continue;
                }
                $html .= '<li>'.e((string) __('seo-content-ai::filament.projects.'.$langKey, [
                    'count' => $count,
                ])).'</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<div class="overflow-x-auto"><table class="w-full text-left text-xs">'
            .'<thead><tr class="border-b border-gray-200 dark:border-gray-700">'
            .'<th class="py-1 pr-2">'.e((string) __('seo-content-ai::filament.projects.compact_success_col_project')).'</th>'
            .'<th class="py-1 pr-2">'.e((string) __('seo-content-ai::filament.projects.compact_success_col_writer')).'</th>'
            .'<th class="py-1 pr-2">'.e((string) __('seo-content-ai::filament.projects.compact_success_col_before')).'</th>'
            .'<th class="py-1 pr-2">'.e((string) __('seo-content-ai::filament.projects.compact_success_col_after')).'</th>'
            .'<th class="py-1">'.e((string) __('seo-content-ai::filament.projects.compact_success_col_moves')).'</th>'
            .'</tr></thead><tbody>';

        $beforeById = [];
        foreach (($plan['projects_before'] ?? []) as $row) {
            if (is_array($row)) {
                $beforeById[(int) ($row['project_id'] ?? 0)] = $row;
            }
        }
        $afterById = [];
        foreach (($plan['projects_after'] ?? []) as $row) {
            if (is_array($row)) {
                $afterById[(int) ($row['project_id'] ?? 0)] = $row;
            }
        }

        foreach ($beforeById as $projectId => $before) {
            $after = $afterById[$projectId] ?? $before;
            $gdBefore = (int) ($before['generator_done_count'] ?? $before['done_count'] ?? 0);
            $ndBefore = (int) ($before['not_done_count'] ?? $before['unfinished_count'] ?? 0);
            $unsafeBefore = (int) ($before['unsafe_count'] ?? 0);
            $gdAfter = (int) ($after['generator_done_count'] ?? $after['done_count'] ?? 0);
            $ndAfter = (int) ($after['not_done_count'] ?? $after['unfinished_count'] ?? 0);
            $unsafeAfter = (int) ($after['unsafe_count'] ?? 0);
            $in = (int) ($after['moves_in'] ?? 0);
            $out = (int) ($after['moves_out'] ?? 0);
            $html .= '<tr class="border-b border-gray-100 dark:border-gray-800">'
                .'<td class="py-1 pr-2">'.e((string) ($before['name'] ?? '')).'</td>'
                .'<td class="py-1 pr-2">'.e((string) ($before['writer_name'] ?? '')).'</td>'
                .'<td class="py-1 pr-2 tabular-nums">'
                .e((string) $gdBefore).' gen / '
                .e((string) $ndBefore).' work / '
                .e((string) $unsafeBefore).' unsafe / '
                .e((string) ($gdBefore + $ndBefore)).' total'
                .'</td>'
                .'<td class="py-1 pr-2 tabular-nums">'
                .e((string) $gdAfter).' gen / '
                .e((string) $ndAfter).' work / '
                .e((string) $unsafeAfter).' unsafe / '
                .e((string) ($gdAfter + $ndAfter)).' total'
                .'</td>'
                .'<td class="py-1 tabular-nums">'
                .'+'.e((string) $in).' / −'.e((string) $out)
                .'</td>'
                .'</tr>';
        }
        $html .= '</tbody></table></div>';

        if (! empty($plan['blocked'])) {
            $html .= '<p class="text-warning-600 dark:text-warning-400">'
                .e((string) __('seo-content-ai::filament.projects.compact_success_blocked_active', [
                    'reason' => (string) ($plan['block_reason'] ?? 'active_running'),
                ]))
                .'</p>';
        } elseif (! empty($plan['already_partitioned']) || ! empty($plan['already_compacted'])) {
            $html .= '<p class="text-warning-600 dark:text-warning-400">'
                .e((string) __('seo-content-ai::filament.projects.compact_success_already_done'))
                .'</p>';
        } elseif (empty($plan['can_execute'])) {
            $html .= '<p class="text-warning-600 dark:text-warning-400">'
                .e((string) __('seo-content-ai::filament.projects.compact_success_cannot_execute'))
                .'</p>';
        }

        $html .= '</div>';

        return $html;
    }

    public function updatedPlanningMonth(mixed $value): void
    {
        $normalized = ContentProjectMonthContext::normalize(is_string($value) ? $value : null);
        $this->planningMonth = $normalized;
        $this->syncToolbarFiltersToTableState();
        $this->redirect($this->planningMonthUrl($normalized, $this->projectType), navigate: true);
    }

    public function updatedProjectType(mixed $value): void
    {
        $type = ContentProjectListBucket::normalize(is_string($value) ? $value : ContentProjectListBucket::ALL);
        $this->projectType = $type;
        $this->syncToolbarFiltersToTableState();
        $this->redirect($this->planningMonthUrl($this->planningMonth, $type), navigate: true);
    }

    public function updatedTableFilters(): void
    {
        $fromFilter = $this->monthFromTableFilters();
        if ($fromFilter !== null && $fromFilter !== $this->planningMonth) {
            $this->planningMonth = $fromFilter;
        }

        $fromType = $this->projectTypeFromTableFilters();
        if ($fromType !== null && $fromType !== $this->projectType) {
            $this->projectType = $fromType;
        }
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getPlanningMonthOptions(): array
    {
        return ContentProjectMonthContext::selectOptions();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getProjectTypeOptions(): array
    {
        return ContentProjectListBucket::selectOptions();
    }

    public function createProjectUrl(?int $staffId = null): string
    {
        $month = ContentProjectMonthContext::normalize($this->planningMonth ?: null);

        return app(ContentProjectStaffAvailabilityService::class)
            ->createProjectUrl($staffId ?? 0, $month);
    }

    public function planningMonthUrl(string $month, ?string $projectType = null): string
    {
        $normalized = ContentProjectMonthContext::normalize($month);
        $type = ContentProjectListBucket::normalize($projectType ?? $this->projectType);
        $base = SeoProjectResource::getUrl('index');
        $monthDate = ContentProjectMonthContext::toDateString($normalized);

        $params = [
            'month' => $normalized,
            'tableFilters' => [
                'month' => [
                    'month' => $monthDate,
                ],
            ],
        ];
        if ($type !== ContentProjectListBucket::ALL) {
            $params['project_type'] = $type;
            $params['tableFilters']['project_type'] = ['value' => $type];
        }

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query($params);
    }

    private function resolvePlanningMonthFromRequest(): string
    {
        $fromQuery = ContentProjectMonthContext::parseOrNull(
            is_string(request()->query('month')) ? (string) request()->query('month') : null,
        );
        if ($fromQuery !== null) {
            return $fromQuery;
        }

        $fromFilter = $this->monthFromTableFilters();
        if ($fromFilter !== null) {
            return $fromFilter;
        }

        $fromTableQuery = ContentProjectMonthContext::parseOrNull(
            is_string(request()->input('tableFilters.month.month'))
                ? (string) request()->input('tableFilters.month.month')
                : null,
        );
        if ($fromTableQuery !== null) {
            return $fromTableQuery;
        }

        return ContentProjectMonthContext::current();
    }

    private function resolveProjectTypeFromRequest(): string
    {
        $fromQuery = request()->query('project_type');
        if (is_string($fromQuery) && $fromQuery !== '') {
            return ContentProjectListBucket::normalize($fromQuery);
        }

        // Legacy ?status= raw lifecycle → bucket map.
        $legacyStatus = request()->query('status');
        if (is_string($legacyStatus) && $legacyStatus !== '') {
            return ContentProjectListBucket::normalize($legacyStatus);
        }

        return $this->projectTypeFromTableFilters() ?? ContentProjectListBucket::ALL;
    }

    private function monthFromTableFilters(): ?string
    {
        $filters = is_array($this->tableFilters ?? null) ? $this->tableFilters : [];
        $raw = $filters['month']['month'] ?? null;

        return ContentProjectMonthContext::parseOrNull(is_string($raw) || $raw instanceof \DateTimeInterface
            ? (string) $raw
            : null);
    }

    private function projectTypeFromTableFilters(): ?string
    {
        $filters = is_array($this->tableFilters ?? null) ? $this->tableFilters : [];
        $raw = $filters['project_type']['value']
            ?? $filters['project_type']
            ?? $filters['status']['value']
            ?? $filters['status']
            ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return ContentProjectListBucket::normalize($raw);
    }

    private function syncToolbarFiltersToTableState(): void
    {
        $this->tableFilters ??= [];
        $this->tableFilters['month'] = [
            'month' => ContentProjectMonthContext::toDateString($this->planningMonth),
        ];

        $type = ContentProjectListBucket::normalize($this->projectType);
        if ($type === ContentProjectListBucket::ALL) {
            unset($this->tableFilters['project_type'], $this->tableFilters['status']);
        } else {
            $this->tableFilters['project_type'] = ['value' => $type];
            unset($this->tableFilters['status']);
        }

        if (method_exists($this, 'getTableFiltersForm')) {
            try {
                $this->getTableFiltersForm()->fill($this->tableFilters);
            } catch (\Throwable) {
                // Table chưa boot ở mount sớm — state tableFilters đủ cho query.
            }
        }
    }
}
