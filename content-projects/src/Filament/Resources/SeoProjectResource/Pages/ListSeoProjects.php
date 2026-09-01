<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectListBucket;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthChartPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

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
            Actions\Action::make('product_gallery_canary')
                ->label('PG Canary fixture')
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
