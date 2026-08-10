<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Pages;

use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoAllDomainsDashboard;
use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoConnectionRoutes;
use Omnichannel\Addons\Seo\Filament\Widgets\AllDomainsListWidget;
use Omnichannel\Addons\ContentProjects\Filament\Widgets\AllDomainsProjectsWidget;
use Omnichannel\Addons\Seo\Filament\Widgets\AllDomainsTeamWidget;
use Omnichannel\Addons\Seo\Filament\Widgets\SeoOverviewStats;
use Omnichannel\Addons\Seo\Filament\Widgets\SeoScoreChart;
use Omnichannel\Addons\WordPress\Filament\Widgets\WpPluginReleaseWidget;
use Omnichannel\Addons\WordPress\Filament\Widgets\WpSyncStatusTable;
use App\Support\ImageDriverResolver;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    use InteractsWithSeoAllDomainsDashboard;
    use InteractsWithSeoConnectionRoutes;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    /** @var array<string, mixed> */
    protected $listeners = [
        'seoGlobalSiteChanged' => '$refresh',
    ];

    public function mount(): void
    {
        $this->notifyImageDriverStatus();
    }

    private function notifyImageDriverStatus(): void
    {
        if (ImageDriverResolver::supportsImagick()) {
            return;
        }

        if (! ImageDriverResolver::supportsGd()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.dashboard.image_driver_missing_title'))
                ->body(__('seo-content-ai::filament.dashboard.image_driver_missing_body'))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.dashboard.imagick_missing_title'))
            ->body(__('seo-content-ai::filament.dashboard.imagick_missing_body'))
            ->warning()
            ->persistent()
            ->send();
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.dashboard.title');
    }

    /**
     * @return int | string | array<string, int | string>
     */
    public function getColumns(): int|string|array
    {
        return [
            'default' => 1,
            'md' => 12,
            'xl' => 12,
        ];
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        if ($this->isAllDomainsDashboard()) {
            return [
                AllDomainsProjectsWidget::class,
                AllDomainsTeamWidget::class,
                AllDomainsListWidget::class,
                WpPluginReleaseWidget::class,
            ];
        }

        return [
            SeoOverviewStats::class,
            SeoScoreChart::class,
            WpSyncStatusTable::class,
        ];
    }
}
