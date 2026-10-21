<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Support\DashboardMetrics;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceHealth;

/**
 * Global Social Automation dashboard — real model metrics (zeros when schema missing).
 */
final class SocialAutomationDashboardPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $slug = '/';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'social-automation::filament.pages.dashboard';

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
        return __('social-automation::filament.dashboard.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.dashboard.title');
    }

    public function mount(): void
    {
        app(SocialAutomationAccess::class)->assertCanAccess();
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(): array
    {
        return app(SocialAutomationServiceHealth::class)->report();
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        return app(DashboardMetrics::class)->snapshot();
    }
}

