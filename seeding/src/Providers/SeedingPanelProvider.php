<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Providers;

use App\Filament\Pages\Auth\CustomLogin;
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
use Omnichannel\Addons\Seeding\Filament\Pages\ManageSeedingTopicPage;
use Omnichannel\Addons\Seeding\Filament\Pages\SeedingServiceStatusPage;
use Omnichannel\Addons\Seeding\Filament\Pages\SeedingTopicsPage;

/**
 * Standalone Seeding Filament surface — path `/seeding`, no SEO panel boot.
 */
final class SeedingPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('seeding')
            ->path('seeding')
            ->login(CustomLogin::class)
            ->homeUrl(static fn (): string => url('/seeding'))
            ->brandName('Seeding')
            ->brandLogo(asset('images/logo.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => Color::Teal,
            ])
            ->maxContentWidth(MaxWidth::Full)
            ->navigation(false)
            ->pages([
                SeedingTopicsPage::class,
                ManageSeedingTopicPage::class,
                SeedingServiceStatusPage::class,
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
