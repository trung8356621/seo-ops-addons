<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Statistics\UserStatisticsReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Services\Statistics\DomainStatisticsReadModel;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Analytical Statistics — domain SEO metrics + user workload.
 * Content canvas only; shell unchanged. Manager/planner only.
 */
final class Statistics extends SeoPanelPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'statistics';

    protected static string $view = 'seo-content-ai::filament.pages.statistics';

    public static function getNavigationLabel(): string
    {
        return __('Statistics');
    }

    #[Url(as: 'tab', except: 'domain')]
    public string $tab = 'domain';

    #[Url(as: 'site', except: null)]
    public ?int $filterSiteId = null;

    #[Url(as: 'month')]
    public string $month = '';

    #[Url(as: 'compare', except: false)]
    public bool $compare = false;

    #[Url(as: 'user', except: null)]
    public ?int $filterUserId = null;

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = ContentProjectMonthContext::current();
        } else {
            $this->month = ContentProjectMonthContext::normalize($this->month);
        }

        if (! in_array($this->tab, ['domain', 'user'], true)) {
            $this->tab = 'domain';
        }

        $this->normalizeFilters();
    }

    public function updatedTab(mixed $value): void
    {
        $this->tab = in_array((string) $value, ['domain', 'user'], true)
            ? (string) $value
            : 'domain';
    }

    public function updatedMonth(mixed $value): void
    {
        $this->month = ContentProjectMonthContext::normalize(is_string($value) ? $value : null);
    }

    public function updatedFilterSiteId(mixed $value): void
    {
        $id = is_numeric($value) ? (int) $value : null;
        $this->filterSiteId = ($id !== null && $id > 0) ? $id : null;
        $this->normalizeFilters();
    }

    public function updatedFilterUserId(mixed $value): void
    {
        $id = is_numeric($value) ? (int) $value : null;
        $this->filterUserId = ($id !== null && $id > 0) ? $id : null;
    }

    public function updatedCompare(mixed $value): void
    {
        $this->compare = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function selectDomain(int $siteId): void
    {
        if ($siteId <= 0) {
            return;
        }
        if (! in_array($siteId, SeoAccessControl::accessibleSiteIds(), true)) {
            return;
        }
        $this->tab = 'domain';
        $this->filterSiteId = $siteId;
    }

    public function selectUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $this->tab = 'user';
        $this->filterUserId = $userId;
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.statistics.title');
    }

    public function getHeading(): string|Htmlable
    {
        return new \Illuminate\Support\HtmlString('<span class="sr-only">'.e(__('seo-content-ai::filament.statistics.title')).'</span>');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $month = ContentProjectMonthContext::normalize($this->month);
        $sites = SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->get(['id', 'domain']);

        $domainData = null;
        $userData = null;
        $userOptions = [];

        if ($this->tab === 'user') {
            $userData = app(UserStatisticsReadModel::class)->build($month, $this->filterUserId);
            $userOptions = is_array($userData['content_manager_options'] ?? null)
                ? $userData['content_manager_options']
                : [];
            if ($userOptions === []) {
                $userOptions = $this->contentManagerNameOptions();
            }
        } else {
            $domainData = app(DomainStatisticsReadModel::class)->build(
                $this->filterSiteId,
                $month,
                $this->compare,
            );
        }

        return [
            'tab' => $this->tab,
            'month' => $month,
            'monthOptions' => ContentProjectMonthContext::selectOptions(),
            'compare' => $this->compare,
            'filterSiteId' => $this->filterSiteId,
            'filterUserId' => $this->filterUserId,
            'sites' => $sites,
            'userOptions' => $userOptions,
            'domainData' => $domainData,
            'userData' => $userData,
            'exportAvailable' => false,
        ];
    }

    private function normalizeFilters(): void
    {
        if ($this->filterSiteId === null || $this->filterSiteId <= 0) {
            $this->filterSiteId = null;

            return;
        }

        if (! in_array($this->filterSiteId, SeoAccessControl::accessibleSiteIds(), true)) {
            $this->filterSiteId = null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function contentManagerNameOptions(): array
    {
        return app(\Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService::class)
            ->baseAssignableStaffQuery()
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }
}
