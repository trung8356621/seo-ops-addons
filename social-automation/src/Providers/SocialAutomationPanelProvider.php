<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Providers;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationAccountsPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationAgentsPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationDashboardPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationJobsPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationPackagesPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationSchedulesPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationServiceStatusPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationSessionsPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationTasksPage;

final class SocialAutomationPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('social-automation')
            ->path('social-automation')
            ->homeUrl(static fn (): string => url('/social-automation'))
            ->brandName('Social Automation')
            ->brandLogo(asset('images/logo.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => Color::Teal,
            ])
            ->maxContentWidth(MaxWidth::Full)
            ->navigation(true)
            ->sidebarCollapsibleOnDesktop()
            ->pages([
                SocialAutomationDashboardPage::class,
                SocialAutomationPackagesPage::class,
                SocialAutomationAgentsPage::class,
                SocialAutomationAccountsPage::class,
                SocialAutomationSessionsPage::class,
                SocialAutomationTasksPage::class,
                SocialAutomationSchedulesPage::class,
                SocialAutomationJobsPage::class,
                SocialAutomationServiceStatusPage::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
