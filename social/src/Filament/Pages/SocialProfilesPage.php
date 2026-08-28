<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Filament\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Social\Enums\SocialPlatform;
use Omnichannel\Addons\Social\Models\SocialProfile;
use Omnichannel\Addons\Social\Support\SocialProfileReadService;
use Throwable;

/**
 * Compact Social Profile CRUD per site — business identity only (no Electron).
 */
final class SocialProfilesPage extends SeoPanelPage
{
    protected static ?string $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_SEO + 2;

    protected static ?string $slug = 'social';

    protected static string $view = 'seo-content-ai::filament.pages.social-profiles-page';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'site_id')]
    public ?int $siteId = null;

    public bool $modalOpen = false;

    public ?int $editingId = null;

    public string $formPlatform = 'facebook';

    public string $formDisplayName = '';

    public string $formProfileUrl = '';

    public bool $formIsActive = true;

    public bool $saving = false;

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
        return __('seo-content-ai::filament.social.nav');
    }

    public function getTitle(): string|Htmlable
    {
        $domain = $this->currentSiteDomain();

        return $domain !== null && $domain !== ''
            ? __('seo-content-ai::filament.social.title_with_domain', ['domain' => $domain])
            : __('seo-content-ai::filament.social.title');
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
        }
        $this->closeModal();
    }

    public function updatedSiteId(): void
    {
        $global = SeoAccessControl::globalSiteId();
        if ($global !== null) {
            $this->siteId = $global;
        }
        $this->assertSiteAccess();
        $this->closeModal();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function profiles(): array
    {
        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId <= 0) {
            return [];
        }

        return app(SocialProfileReadService::class)->allForSite($siteId);
    }

    /**
     * @return array<string, string>
     */
    public function platformOptions(): array
    {
        $out = [];
        foreach (SocialPlatform::selectable() as $platform) {
            $out[$platform->value] = $platform->label();
        }

        return $out;
    }

    public function openCreateModal(): void
    {
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);
        $this->editingId = null;
        $this->formPlatform = SocialPlatform::Facebook->value;
        $this->formDisplayName = '';
        $this->formProfileUrl = '';
        $this->formIsActive = true;
        $this->modalOpen = true;
    }

    public function openEditModal(int $profileId): void
    {
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);
        $profile = $this->findScopedProfile($profileId);
        if ($profile === null) {
            return;
        }

        $this->editingId = (int) $profile->id;
        $this->formPlatform = $profile->platform instanceof SocialPlatform
            ? $profile->platform->value
            : (string) $profile->platform;
        $this->formDisplayName = (string) $profile->display_name;
        $this->formProfileUrl = (string) $profile->profile_url;
        $this->formIsActive = (bool) $profile->is_active;
        $this->modalOpen = true;
    }

    public function closeModal(): void
    {
        $this->modalOpen = false;
        $this->editingId = null;
        $this->saving = false;
    }

    public function saveProfile(): void
    {
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);
        $this->assertSiteAccess();

        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.social.need_site'))
                ->warning()
                ->send();

            return;
        }

        $platform = SocialPlatform::tryFrom(trim($this->formPlatform));
        $displayName = trim($this->formDisplayName);
        $profileUrl = trim($this->formProfileUrl);

        if ($platform === null || $displayName === '' || $profileUrl === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.social.validation_required'))
                ->warning()
                ->send();

            return;
        }

        if (! filter_var($profileUrl, FILTER_VALIDATE_URL)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.social.validation_url'))
                ->warning()
                ->send();

            return;
        }

        $this->saving = true;

        try {
            $profile = $this->editingId !== null
                ? $this->findScopedProfile($this->editingId)
                : new SocialProfile(['site_id' => $siteId]);

            if (! $profile instanceof SocialProfile) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.social.save_failed'))
                    ->danger()
                    ->send();
                $this->saving = false;

                return;
            }

            $profile->site_id = $siteId;
            $profile->platform = $platform;
            $profile->display_name = $displayName;
            $profile->profile_url = $profileUrl;
            $profile->is_active = $this->formIsActive;
            $profile->save();

            Notification::make()
                ->title(__('seo-content-ai::filament.social.saved'))
                ->success()
                ->send();
            $this->closeModal();
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.social.save_failed'))
                ->body(mb_substr($exception->getMessage(), 0, 200))
                ->danger()
                ->send();
        }

        $this->saving = false;
    }

    public function toggleActive(int $profileId): void
    {
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);
        $profile = $this->findScopedProfile($profileId);
        if ($profile === null) {
            return;
        }

        $profile->is_active = ! (bool) $profile->is_active;
        $profile->save();
    }

    public function deleteProfile(int $profileId): void
    {
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);
        $profile = $this->findScopedProfile($profileId);
        if ($profile === null) {
            return;
        }

        $profile->delete();
        Notification::make()
            ->title(__('seo-content-ai::filament.social.deleted'))
            ->success()
            ->send();
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

    private function findScopedProfile(int $profileId): ?SocialProfile
    {
        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId <= 0 || $profileId <= 0) {
            return null;
        }

        return SocialProfile::query()
            ->forSite($siteId)
            ->whereKey($profileId)
            ->first();
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
