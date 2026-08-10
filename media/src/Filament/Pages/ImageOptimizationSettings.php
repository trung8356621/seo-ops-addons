<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Media\Models\SeoImageOptimizationSetting;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

class ImageOptimizationSettings extends SeoPanelPage
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Image optimization';

    protected static ?string $title = 'Image optimization settings';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = 'Media library';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'seo-content-ai::filament.pages.image-optimization-settings';

    /** @var int|string|null */
    #[Url]
    public $siteId = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $this->siteId = $globalSiteId;
        } elseif ($this->siteId === null || $this->siteId === '') {
            $firstSite = $this->resolveSitesQuery()->first();
            $this->siteId = $firstSite instanceof Site ? (int) $firstSite->id : null;
        } else {
            $this->siteId = (int) $this->siteId;
        }

        $this->loadSettings();
    }

    public function updatedSiteId(mixed $value): void
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $this->siteId = $globalSiteId;
            $this->loadSettings();

            return;
        }

        if ($value === null || $value === '') {
            $this->siteId = null;
        } else {
            $this->siteId = (int) $value;
        }

        $this->loadSettings();
    }

    public function loadSettings(): void
    {
        $siteId = $this->normalizedSiteId();

        $settings = $siteId !== null
            ? SeoImageOptimizationSetting::query()->where('site_id', $siteId)->first()
            : null;

        if ($settings === null) {
            $settings = SeoImageOptimizationSetting::query()->whereNull('site_id')->first()
                ?? new SeoImageOptimizationSetting;
        }

        $this->data = $settings->toFormData();
    }

    public function save(): void
    {
        $siteId = $this->normalizedSiteId();

        $quality = max(10, min(100, (int) ($this->data['quality'] ?? 80)));

        $maxWidthRaw = $this->data['max_width'] ?? null;
        $maxHeightRaw = $this->data['max_height'] ?? null;
        $maxWidth = ($maxWidthRaw === null || $maxWidthRaw === '')
            ? 0
            : max(0, (int) $maxWidthRaw);
        $maxHeight = ($maxHeightRaw === null || $maxHeightRaw === '')
            ? 0
            : max(0, (int) $maxHeightRaw);

        if ($maxWidth > 0) {
            $maxWidth = max(100, $maxWidth);
        }
        if ($maxHeight > 0) {
            $maxHeight = max(100, $maxHeight);
        }

        SeoImageOptimizationSetting::query()->updateOrCreate(
            ['site_id' => $siteId],
            [
                'auto_convert_webp' => (bool) ($this->data['auto_convert_webp'] ?? true),
                'quality' => $quality,
                'limit_dimensions' => (bool) ($this->data['limit_dimensions'] ?? true),
                'max_width' => $maxWidth,
                'max_height' => $maxHeight,
                'clean_filename' => (bool) ($this->data['clean_filename'] ?? true),
                'auto_alt_tag' => (bool) ($this->data['auto_alt_tag'] ?? true),
                'alt_tag_pattern' => (string) ($this->data['alt_tag_pattern'] ?? '{post_title} - {focus_keyword}'),
            ],
        );

        Notification::make()
            ->title(__('seo-content-ai::filament.image_optimization.saved'))
            ->success()
            ->send();
    }

    /**
     * @return Collection<int, Site>
     */
    public function getSitesProperty(): Collection
    {
        return $this->resolveSitesQuery()->get();
    }

    public function hasLockedGlobalSite(): bool
    {
        return SeoAccessControl::hasGlobalSiteScope();
    }

    public function currentSiteDomain(): ?string
    {
        if ($this->siteId === null || $this->siteId === '') {
            return null;
        }

        $site = $this->sites->firstWhere('id', (int) $this->siteId);

        return $site instanceof Site ? (string) $site->domain : null;
    }

    private function normalizedSiteId(): ?int
    {
        if ($this->siteId === null || $this->siteId === '') {
            return null;
        }

        $id = (int) $this->siteId;

        return $id > 0 ? $id : null;
    }

    private function resolveSitesQuery()
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query;
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.image_optimization');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('seo-content-ai::filament.nav.media_library');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.nav.image_optimization_settings');
    }
}
