<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingServiceHealth;

/**
 * Canonical Seeding workspace shell at GET /seeding.
 *
 * Mounts React island; business state is 100% localStorage (bootstrap props only).
 * No global SEO/site domain context — Seeding is social/external feed scoped.
 */
final class SeedingTopicsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'workspace';

    protected static string $view = 'seeding::filament.pages.seeding-topics-page';

    /** Bare shell — no Filament sidebar / topbar chrome. */
    protected static string $layout = 'seeding::layouts.bare';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Panel root — Filament route path `/` under panel path `seeding` ⇒ `/seeding`.
     */
    public static function getRoutePath(): string
    {
        return '/';
    }

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

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('seeding::filament.topics.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Seeding';
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function mount(): void
    {
        app(SeedingAccess::class)->assertCanAccess();
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceProps(): array
    {
        $access = app(SeedingAccess::class);
        $bootstrap = app(SeedingServiceHealth::class)->bootstrap();

        return [
            'canMutate' => $access->canMutate(),
            'bootstrap' => $bootstrap,
        ];
    }
}
