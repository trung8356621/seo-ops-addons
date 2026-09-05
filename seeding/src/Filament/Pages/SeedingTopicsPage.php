<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Filament\Pages;

use App\Models\Site;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingServiceHealth;

/**
 * Canonical Seeding workspace shell at GET /seeding.
 *
 * Mounts React island; business state is 100% localStorage (bootstrap props only).
 */
final class SeedingTopicsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'workspace';

    protected static string $view = 'seeding::filament.pages.seeding-topics-page';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'site_id')]
    public ?int $siteId = null;

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
        $domain = $this->currentSiteDomain();

        return $domain !== null && $domain !== ''
            ? __('seeding::filament.topics.title_with_domain', ['domain' => $domain])
            : __('seeding::filament.topics.title');
    }

    public function mount(): void
    {
        $access = app(SeedingAccess::class);
        $access->assertCanAccess();

        if ($this->siteId === null || $this->siteId <= 0) {
            $first = $access->accessibleSitesQuery()->orderBy('domain')->first();
            $this->siteId = $first instanceof Site ? (int) $first->id : null;
        }

        $this->assertSiteAccess();
    }

    #[On('domain-context-changed')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        $resolved = is_numeric($siteId) ? (int) $siteId : null;
        if ($resolved !== null && $resolved > 0) {
            $this->siteId = $resolved;
            $this->js(
                'window.dispatchEvent(new CustomEvent("seeding-site-changed", { detail: { siteId: '.$resolved.' } }))'
            );
        }
    }

    public function updatedSiteId(): void
    {
        $this->assertSiteAccess();

        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId > 0) {
            $this->js(
                'window.dispatchEvent(new CustomEvent("seeding-site-changed", { detail: { siteId: '.$siteId.' } }))'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceProps(): array
    {
        $siteId = (int) ($this->siteId ?? 0);
        $access = app(SeedingAccess::class);
        $bootstrap = app(SeedingServiceHealth::class)->bootstrap();

        return [
            'siteId' => $siteId > 0 ? $siteId : null,
            'canMutate' => $access->canMutate(),
            'domain' => $this->currentSiteDomain(),
            'bootstrap' => $bootstrap,
        ];
    }

    public function currentSiteDomain(): ?string
    {
        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId <= 0) {
            return null;
        }

        return Site::query()->find($siteId)?->domain;
    }

    public function hasLockedGlobalSite(): bool
    {
        return false;
    }

    /**
     * @return list<Site>
     */
    public function sites(): array
    {
        return app(SeedingAccess::class)
            ->accessibleSitesQuery()
            ->orderBy('domain')
            ->get()
            ->all();
    }

    private function assertSiteAccess(): void
    {
        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId <= 0) {
            return;
        }

        app(SeedingAccess::class)->assertCanAccessSite($siteId);
    }
}
