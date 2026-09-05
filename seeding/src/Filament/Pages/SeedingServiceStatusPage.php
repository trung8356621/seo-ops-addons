<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingServiceHealth;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;

/**
 * Minimal Seeding service status — activation, persistence mode, DB readiness.
 */
final class SeedingServiceStatusPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $slug = 'service';

    protected static string $view = 'seeding::filament.pages.seeding-service-status';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        return parent::getUrl($parameters, $isAbsolute, $panel ?? 'seeding', $tenant);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return app(SeedingAccess::class)->canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('seeding::filament.service.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('seeding::filament.service.title');
    }

    public function mount(): void
    {
        app(SeedingAccess::class)->assertCanAccess();
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(): array
    {
        return app(SeedingServiceHealth::class)->report();
    }

    public function persistenceMode(): string
    {
        return (string) (app(SeedingServiceResolver::class)->resolve()->persistence);
    }
}
