<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Widgets;

use Omnichannel\Addons\ContentProjects\Filament\Widgets\Concerns\ResolvesContentProjectMonthDashboardCharts;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Widgets\Widget;

class DashboardDomainArticlesChartWidget extends Widget
{
    use ResolvesContentProjectMonthDashboardCharts;

    protected static string $view = 'seo-content-ai::filament.widgets.dashboard-domain-articles-chart';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 6,
    ];

    /** @var array<string, mixed> */
    protected $listeners = [
        'seoGlobalSiteChanged' => '$refresh',
        'domain-context-changed' => '$refresh',
    ];

    public static function canView(): bool
    {
        return ! SeoAccessControl::hasGlobalSiteScope();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'domainChart' => $this->resolveDomainWorkloadChart(),
        ];
    }
}
