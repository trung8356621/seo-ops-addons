<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Filament\Pages;

use App\Models\Site;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Seeding Topic V2 workspace shell — mounts React island; no classic CRUD flow.
 */
final class SeedingTopicsPage extends SeoPanelPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_SEO + 3;

    protected static ?string $slug = 'seeding-topics';

    protected static string $view = 'seeding::filament.pages.seeding-topics-page';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'site_id')]
    public ?int $siteId = null;

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
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
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $this->siteId = $globalSiteId;
        } elseif ($this->siteId === null || $this->siteId <= 0) {
            $first = SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->first();
            $this->siteId = $first instanceof Site ? (int) $first->id : null;
        }

        $this->assertSiteAccess();
    }

    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        $resolved = is_numeric($siteId) ? (int) $siteId : SeoAccessControl::globalSiteId();
        if ($resolved !== null && $resolved > 0) {
            $this->siteId = $resolved;
            $this->js(
                'window.dispatchEvent(new CustomEvent("seeding-site-changed", { detail: { siteId: '.$resolved.' } }))'
            );
        }
    }

    public function updatedSiteId(): void
    {
        $global = SeoAccessControl::globalSiteId();
        if ($global !== null) {
            $this->siteId = $global;
        }
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

        return [
            'siteId' => $siteId > 0 ? $siteId : null,
            'apiBase' => url('/api/seo/seeding-topics'),
            'canMutate' => SeoAccessControl::canMutateInSeoPanel(),
            'domain' => $this->currentSiteDomain(),
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
        return SeoAccessControl::globalSiteId() !== null;
    }

    /**
     * @return list<Site>
     */
    public function sites(): array
    {
        return SeoAccessControl::accessibleSitesQuery()
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

        abort_unless(SeoAccessControl::canAccessSite($siteId), 403);
    }
}
