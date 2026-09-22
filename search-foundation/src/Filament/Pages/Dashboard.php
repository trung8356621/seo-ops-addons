<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Widgets\OperationalLandingDashboardWidget;
use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoConnectionRoutes;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Support\ImageDriverResolver;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * /seo landing Dashboard — operational, role-aware, lightweight.
 * Heavy SEO analytics belong in future Statistics (see design.md).
 */
class Dashboard extends BaseDashboard
{
    use InteractsWithSeoConnectionRoutes;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    /** @var array<string, mixed> */
    protected $listeners = [
        'seoGlobalSiteChanged' => '$refresh',
        'domain-context-changed' => '$refresh',
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
        return 'Dashboard';
    }

    public function getSubheading(): ?string
    {
        return SeoAccessControl::isContentManager()
            ? (string) __('seo-content-ai::filament.dashboard.ops_cm_subtitle')
            : (string) __('seo-content-ai::filament.dashboard.ops_manager_subtitle');
    }

    /**
     * @return int | string | array<string, int | string>
     */
    public function getColumns(): int|string|array
    {
        return 1;
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            OperationalLandingDashboardWidget::class,
        ];
    }
}
