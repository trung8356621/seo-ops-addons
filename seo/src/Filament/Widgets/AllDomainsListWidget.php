<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Widgets;

use Omnichannel\Addons\Seo\Services\AllDomainsDashboardService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Widgets\Widget;

class AllDomainsListWidget extends Widget
{

    protected static string $view = 'seo-content-ai::filament.widgets.all-domains-list';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

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
            'rows' => app(AllDomainsDashboardService::class)->domainsHealthOverview(),
        ];
    }
}
