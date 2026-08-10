<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSeoProjects extends ListRecords
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.list-seo-projects';

    /** Month context YYYY-MM — dùng chung toolbar + table filter. */
    public string $planningMonth = '';

    public string $staffSearch = '';

    public function mount(): void
    {
        parent::mount();

        $this->planningMonth = $this->resolvePlanningMonthFromRequest();
        $this->applyPlanningMonthToTableFilters($this->planningMonth);
    }

    protected function getTableQuery(): Builder
    {
        // Hiện cả project đã archive trên list; click → archive preview (projectRecordUrl).
        return SeoProjectResource::applyGlobalSiteScopeToProjectQuery(
            parent::getTableQuery()
                ->where(function (Builder $builder): void {
                    $builder
                        ->where('kind', SeoProject::KIND_MONTHLY)
                        ->orWhereNull('kind');
                }),
        );
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            \Omnichannel\Addons\ContentProjects\Filament\Widgets\ContentProjectQueueHealthWidget::class,
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
        $this->staffSearch = '';
        $this->applyPlanningMonthToTableFilters($normalized);
        $this->redirect($this->planningMonthUrl($normalized), navigate: true);
    }

    public function updatedTableFilters(): void
    {
        $fromFilter = $this->monthFromTableFilters();
        if ($fromFilter === null) {
            // "All months" — widget vẫn cần một tháng cụ thể (fallback current).
            return;
        }

        if ($fromFilter === $this->planningMonth) {
            return;
        }

        $this->planningMonth = $fromFilter;
    }

    public function updatedStaffSearch(): void
    {
        // Livewire re-render popover list theo search.
    }

    public function canViewUnassignedStaff(): bool
    {
        return app(ContentProjectStaffAvailabilityService::class)->canViewUnassignedStaff();
    }

    /**
     * @return array{
     *     month: string,
     *     month_display: string,
     *     total: int,
     *     staff: list<array{id: int, name: string, email: string, initials: string, create_url: string}>
     * }
     */
    public function getUnassignedStaffPayload(): array
    {
        $month = ContentProjectMonthContext::normalize($this->planningMonth ?: null);

        return app(ContentProjectStaffAvailabilityService::class)
            ->widgetPayload($month, $this->staffSearch !== '' ? $this->staffSearch : null);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getPlanningMonthOptions(): array
    {
        return ContentProjectMonthContext::selectOptions();
    }

    public function createProjectUrl(?int $staffId = null): string
    {
        $month = ContentProjectMonthContext::normalize($this->planningMonth ?: null);

        return app(ContentProjectStaffAvailabilityService::class)
            ->createProjectUrl($staffId ?? 0, $month);
    }

    public function planningMonthUrl(string $month): string
    {
        $normalized = ContentProjectMonthContext::normalize($month);
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

    private function monthFromTableFilters(): ?string
    {
        $filters = is_array($this->tableFilters ?? null) ? $this->tableFilters : [];
        $raw = $filters['month']['month'] ?? null;

        return ContentProjectMonthContext::parseOrNull(is_string($raw) || $raw instanceof \DateTimeInterface
            ? (string) $raw
            : null);
    }

    private function applyPlanningMonthToTableFilters(string $yyyyMm): void
    {
        $this->tableFilters ??= [];
        $this->tableFilters['month'] = [
            'month' => ContentProjectMonthContext::toDateString($yyyyMm),
        ];

        if (method_exists($this, 'getTableFiltersForm')) {
            try {
                $this->getTableFiltersForm()->fill($this->tableFilters);
            } catch (\Throwable) {
                // Table chưa boot ở mount sớm — state tableFilters đủ cho query.
            }
        }
    }
}
