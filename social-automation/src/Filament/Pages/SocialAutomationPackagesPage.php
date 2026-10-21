<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

/**
 * Read-only Package Registry UI (Admin publish is via Service API).
 */
final class SocialAutomationPackagesPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $slug = 'packages';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'social-automation::filament.pages.packages';

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        return parent::getUrl($parameters, $isAbsolute, $panel ?? 'social-automation', $tenant);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return app(SocialAutomationAccess::class)->canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.packages.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.packages.title');
    }

    public function mount(): void
    {
        app(SocialAutomationAccess::class)->assertCanAccess();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packages(): array
    {
        return app(PackageRegistryService::class)->listPackages();
    }
}
