<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Media\Models\SeoWatermarkSetting;
use Omnichannel\Addons\Media\Services\SeoWatermarkService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

class WatermarkSettingsPage extends SeoPanelPage
{
    protected static ?string $navigationIcon = 'heroicon-o-bookmark-square';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = null;

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_MEDIA + 1;

    protected static string $view = 'seo-content-ai::filament.pages.watermark-settings-page';

    #[Url]
    public ?int $siteId = null;

    /** Bật = đóng dấu + tối ưu; tắt = chỉ tối ưu ảnh không phải .webp */
    public bool $batchApplyWatermark = true;

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
    }

    public function hasConfiguredDesign(): bool
    {
        if ($this->siteId === null) {
            return false;
        }

        $settings = SeoWatermarkSetting::query()->where('site_id', $this->siteId)->first();

        return $settings instanceof SeoWatermarkSetting && $settings->isConfiguredForApply();
    }

    public function applyBatchToCurrentSite(): void
    {
        if ($this->siteId === null) {
            Notification::make()->title('Select domain')->warning()->send();

            return;
        }

        @set_time_limit(600);

        $result = app(SeoWatermarkService::class)->applyBatchAllForSite(
            (int) $this->siteId,
            $this->batchApplyWatermark,
        );

        $processed = (int) ($result['local_watermark'] ?? 0)
            + (int) ($result['local_optimize'] ?? 0)
            + (int) ($result['wp_watermark'] ?? 0)
            + (int) ($result['wp_optimize'] ?? 0);

        if (filled($result['message'] ?? null) && $processed === 0) {
            Notification::make()
                ->title((string) $result['message'])
                ->warning()
                ->send();

            return;
        }

        $modeLabel = $this->batchApplyWatermark
            ? 'Watermark + optimize (WebP)'
            : 'Optimize only (skip .webp files)';

        $body = $modeLabel."\n";
        $body .= sprintf(
            'Local - watermarked: %d · optimized: %d · skipped: %d.',
            (int) ($result['local_watermark'] ?? 0),
            (int) ($result['local_optimize'] ?? 0),
            (int) ($result['local_skipped'] ?? 0),
        );
        $body .= "\n".sprintf(
            'WordPress - watermarked: %d · optimized: %d · skipped: %d.',
            (int) ($result['wp_watermark'] ?? 0),
            (int) ($result['wp_optimize'] ?? 0),
            (int) ($result['wp_skipped'] ?? 0),
        );

        if ((int) ($result['wp_errors'] ?? 0) > 0) {
            $body .= "\nWP errors: ".(int) $result['wp_errors'].'.';
        }

        if ($this->batchApplyWatermark) {
            $body .= "\nOriginal WordPress images are backed up on Laravel (first run).";
        }

        Notification::make()
            ->title('Batch processing completed')
            ->body($body)
            ->success()
            ->duration(15000)
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_designer')
                ->label('Open visual designer')
                ->icon('heroicon-o-paint-brush')
                ->url(fn (): string => WatermarkEditor::getUrl([
                    'siteId' => $this->siteId,
                ])),
            Action::make('batch_watermark')
                ->label('Apply to all images')
                ->icon('heroicon-o-photo')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Apply to all images')
                ->modalDescription(
                    'Optimize images (resize, WebP based on "Image optimization settings"). '
                    .'If watermark is enabled: apply watermark before optimization. '
                    .'Optimize-only mode skips files already in .webp format. '
                    .'WordPress images are backed up on Laravel when edited.'
                )
                ->action('applyBatchToCurrentSite')
                ->visible(fn (): bool => $this->siteId !== null),
        ];
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
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.watermark_batch');
    }

    public static function getNavigationParentItem(): ?string
    {
        return MediaLibrary::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.nav.watermark_batch_title');
    }
}
