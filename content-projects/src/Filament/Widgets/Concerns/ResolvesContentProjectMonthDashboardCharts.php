<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Widgets\Concerns;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthChartPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

trait ResolvesContentProjectMonthDashboardCharts
{
    /**
     * @return array<string, mixed>
     */
    protected function resolveDomainWorkloadChart(): array
    {
        return app(ContentProjectMonthChartPresenter::class)
            ->presentDomain($this->resolveMonthWorkload());
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveWriterWorkloadChart(): array
    {
        return app(ContentProjectMonthChartPresenter::class)
            ->presentWriter($this->resolveMonthWorkload());
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveMonthWorkload(): array
    {
        return app(ContentProjectMonthlyWorkloadService::class)
            ->forMonth(ContentProjectMonthContext::current());
    }
}
