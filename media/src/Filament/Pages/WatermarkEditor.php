<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Media\Models\SeoWatermarkSetting;
use Omnichannel\Addons\Media\Services\SeoMediaLibraryService;
use Omnichannel\Addons\Media\Services\SeoWatermarkOverlayStorage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

class WatermarkEditor extends SeoPanelPage
{
    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationLabel = 'Watermark designer';

    protected static ?string $title = 'Watermark design suite';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = 'Media library';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'seo-content-ai::filament.pages.watermark-editor';

    protected static bool $shouldRegisterNavigation = true;

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return ! SeoAccessControl::isContentManager();
    }

    #[Url]
    public ?int $siteId = null;

    #[Url]
    public ?string $imageUrl = null;

    #[Url]
    public ?int $imageId = null;

    public function mount(): void
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $this->siteId = $globalSiteId;
        } elseif ($this->siteId === null) {
            $firstSite = $this->resolveSitesQuery()->first();
            $this->siteId = $firstSite instanceof Site ? (int) $firstSite->id : null;
        }
    }

    public function updatedSiteId(): void
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $this->siteId = $globalSiteId;
        }

        $this->imageUrl = null;
        $this->imageId = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInitialDesignConfig(): array
    {
        if ($this->siteId === null) {
            return (new SeoWatermarkSetting)->defaultDesignConfig();
        }

        $setting = SeoWatermarkSetting::query()->where('site_id', $this->siteId)->first();
        if ($setting === null) {
            return (new SeoWatermarkSetting)->defaultDesignConfig();
        }

        $design = is_array($setting->design_config) && $setting->design_config !== []
            ? $setting->design_config
            : $setting->defaultDesignConfig();

        if (filled($setting->logoUrl())) {
            $design['logoUrl'] = $setting->logoUrl();
        }

        $design['overlay_previews'] = app(SeoWatermarkOverlayStorage::class)->variantsForEditor($design);

        return $design;
    }

    /**
     * @return list<array{id: int|string, url: string, slug: string, source: string}>
     */
    public function getMediaSamples(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            return [];
        }

        $samples = [];

        $result = app(SeoMediaLibraryService::class)->fetch($site, null, 1, null, 24);
        foreach ($result['images'] ?? [] as $row) {
            if (! is_array($row) || empty($row['url'])) {
                continue;
            }
            $samples[] = [
                'id' => (int) ($row['seo_media_id'] ?? $row['id'] ?? 0),
                'url' => (string) $row['url'],
                'slug' => (string) ($row['slug'] ?? ''),
                'source' => 'local',
            ];
        }

        if ($this->imageUrl && ! collect($samples)->contains('url', $this->imageUrl)) {
            array_unshift($samples, [
                'id' => $this->imageId ?? 0,
                'url' => $this->imageUrl,
                'slug' => 'current',
                'source' => 'picker',
            ]);
        }

        return $samples;
    }

    public function getEditorUrl(): string
    {
        return static::getUrl([
            'siteId' => $this->siteId,
            'imageUrl' => $this->imageUrl,
            'imageId' => $this->imageId,
        ]);
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
        if ($this->siteId === null || $this->siteId <= 0) {
            return null;
        }

        $site = $this->sites->firstWhere('id', (int) $this->siteId);

        return $site instanceof Site ? (string) $site->domain : null;
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
        return SeoAccessControl::canAccessContentFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.watermark_designer');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('seo-content-ai::filament.nav.media_library');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.nav.watermark_design_suite');
    }
}
