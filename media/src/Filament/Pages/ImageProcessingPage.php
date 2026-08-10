<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Filament\Pages;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\AiImageProcessingService;
use Omnichannel\Addons\Media\Services\ArticleEditorMediaAiService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;

class ImageProcessingPage extends Page
{
    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return ! SeoAccessControl::isContentManager();
    }

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Image processing';

    protected static ?string $title = 'Image processing';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = 'Media library';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'seo-content-ai::filament.pages.image-processing';

    /** @var int|string|null */
    #[Url]
    public $siteId = null;

    #[Url]
    public ?string $statusFilter = null;

    #[Url]
    public int $page = 1;

    /** @var list<array<string, mixed>> */
    public array $items = [];

    public int $total = 0;

    public int $totalPages = 1;

    /** @var array{all: int, processing: int, completed: int, failed: int} */
    public array $counts = [
        'all' => 0,
        'processing' => 0,
        'completed' => 0,
        'failed' => 0,
    ];

    public bool $loading = false;

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

        $this->loadItems(reconcile: true);
    }

    public function updated($propertyName): void
    {
        if (in_array($propertyName, ['siteId', 'statusFilter'], true)) {
            $this->page = 1;
            $this->loadItems(reconcile: true);

            return;
        }

        if ($propertyName === 'page') {
            $this->loadItems(reconcile: true);
        }
    }

    public function reloadItems(): void
    {
        $this->loadItems(reconcile: true);
    }

    public function retryJob(int $mediaId): void
    {
        $media = $this->findAccessibleMedia($mediaId);
        if ($media === null) {
            return;
        }

        try {
            app(ArticleEditorMediaAiService::class)->retryGeneration($media);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.image_processing.retry_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.image_processing.retry_queued'))
            ->success()
            ->send();

        $this->loadItems(reconcile: true);
    }

    public function deleteJob(int $mediaId): void
    {
        $media = $this->findAccessibleMedia($mediaId);
        if ($media === null) {
            return;
        }

        if (! $media->isAiGenerationJob()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.image_processing.delete_failed'))
                ->body(__('seo-content-ai::common.ai_job_delete_only'))
                ->warning()
                ->send();

            return;
        }

        $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
        $isSharedPlaceholder = $path === SeoMedia::placeholderLoadingPath();
        $isUploadedFile = str_starts_with($path, 'uploads/seo_media/');

        if (! $isSharedPlaceholder && $isUploadedFile && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $media->delete();

        Notification::make()
            ->title(__('seo-content-ai::filament.image_processing.deleted'))
            ->success()
            ->send();

        $this->loadItems();
    }

    public function previousPage(): void
    {
        if ($this->page <= 1) {
            return;
        }

        $this->page--;
        $this->loadItems(reconcile: true);
    }

    public function nextPage(): void
    {
        if ($this->page >= $this->totalPages) {
            return;
        }

        $this->page++;
        $this->loadItems(reconcile: true);
    }

    public function hasLockedGlobalSite(): bool
    {
        return SeoAccessControl::hasGlobalSiteScope();
    }

    public function currentSiteDomain(): ?string
    {
        if ($this->siteId === null || $this->siteId === '' || (int) $this->siteId <= 0) {
            return null;
        }

        $site = $this->sites->firstWhere('id', (int) $this->siteId);

        return $site instanceof Site ? (string) $site->domain : null;
    }

    public function hasProcessingJobs(): bool
    {
        return ($this->counts['processing'] ?? 0) > 0;
    }

    /**
     * @return Collection<int, Site>
     */
    public function getSitesProperty(): Collection
    {
        return $this->resolveSitesQuery()->get();
    }

    private function loadItems(bool $reconcile = false): void
    {
        $this->loading = true;
        $this->items = [];
        $this->total = 0;
        $this->totalPages = 1;
        $this->counts = [
            'all' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        $siteId = $this->normalizedSiteId();
        if ($siteId === null) {
            $this->loading = false;

            return;
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            $this->loading = false;

            return;
        }

        $service = app(AiImageProcessingService::class);
        if ($reconcile) {
            $service->reconcileStaleJobsForSite($siteId);
        }

        $result = $service->fetch($site, $this->statusFilter, $this->page);
        $this->items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $this->total = (int) ($result['total'] ?? 0);
        $this->totalPages = max(1, (int) ($result['total_pages'] ?? 1));
        $this->page = max(1, (int) ($result['page'] ?? $this->page));
        $this->counts = is_array($result['counts'] ?? null)
            ? array_merge($this->counts, $result['counts'])
            : $this->counts;
        $this->loading = false;
    }

    private function findAccessibleMedia(int $mediaId): ?SeoMedia
    {
        $siteId = $this->normalizedSiteId();
        if ($siteId === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.select_domain'))
                ->warning()
                ->send();

            return null;
        }

        $media = SeoMedia::query()
            ->where('site_id', $siteId)
            ->whereKey($mediaId)
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt'])
            ->first();

        if ($media === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.image_not_found'))
                ->warning()
                ->send();

            return null;
        }

        return $media;
    }

    private function normalizedSiteId(): ?int
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $this->siteId = $globalSiteId;
        }

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
        return SeoAccessControl::canAccessContentFeatures();
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.image_processing');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('seo-content-ai::filament.nav.media_library');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.nav.image_processing_title');
    }
}
