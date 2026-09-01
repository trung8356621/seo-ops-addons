<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Widgets;

use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoDashboardSite;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DashboardKeywordOverviewService;
use Filament\Widgets\Widget;

class KeywordOverviewWidget extends Widget
{
    use InteractsWithSeoDashboardSite;

    protected static string $view = 'seo-content-ai::filament.widgets.keyword-overview';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return \Omnichannel\Addons\Seo\Support\SeoAccessControl::hasGlobalSiteScope();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $siteId = $this->resolveDashboardSiteId();
        if ($siteId === null) {
            return [
                'has_site' => false,
            ];
        }

        $overview = app(DashboardKeywordOverviewService::class)->forSite($siteId);

        return [
            'has_site' => true,
            'overview' => $overview,
        ];
    }
}
