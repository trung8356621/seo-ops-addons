<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Filament\Pages;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\GeneratedImageLibraryService;
use Omnichannel\Addons\Media\Services\MediaLibraryAccessScope;
use Omnichannel\Addons\Media\Services\MediaLibraryArticleResolver;
use Omnichannel\Addons\Media\Services\SeoMediaImageEditorResolverService;
use Omnichannel\Addons\Media\Services\SeoMediaLibraryDeleteService;
use Omnichannel\Addons\Media\Services\SeoMediaLibraryImageActionService;
use Omnichannel\Addons\Media\Services\SeoMediaLibraryService;
use Omnichannel\Addons\Media\Services\SeoMediaWpEditStagingService;
use Omnichannel\Addons\Media\Services\SeoWpMediaEditedPendingService;
use Omnichannel\Addons\WordPress\Services\WordPressMediaLibraryService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use App\Models\Site;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Omnichannel\Addons\Seo\Livewire\Concerns\RefreshesOnDomainContextChanged;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

class MediaLibrary extends Page
{
    use RefreshesOnDomainContextChanged;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Media library';

    protected static ?string $title = 'Media library';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_MEDIA;

    protected static string $view = 'seo-content-ai::filament.pages.media-library';

    #[Url]
    public string $activeTab = 'original';

    /** @var int|string|null */
    #[Url]
    public $siteId = null;

    #[Url]
    public ?string $filterMonth = null;

    #[Url]
    public ?string $filterSearch = null;

    public ?string $filterSearchInput = null;

    #[Url]
    public int $page = 1;

    /** @var list<array<string, mixed>> */
    public array $images = [];

    public int $total = 0;

    public int $totalPages = 1;

    public ?string $loadError = null;

    public ?string $editingKey = null;

    public string $editingSlug = '';

    /** @var array{id: int, url: string, wp_attachment_id: int} */
    public array $editingContext = [];

    public bool $previewOpen = false;

    /** @var array<string, mixed>|null */
    public ?array $previewImage = null;

    public bool $previewBusy = false;

    public ?string $previewMessage = null;

    public ?string $previewMessageType = null;

    public bool $previewCanRestore = false;

    public bool $previewCanOptimize = false;

    public bool $previewCanSyncToWp = false;

    public bool $previewPendingWpSync = false;

    public ?string $previewProcessingStatus = null;

    /** @var list<string> */
    public array $selectedKeys = [];

    public ?string $selectionAnchorKey = null;

    public ?string $resizeWidth = null;

    public ?string $resizeHeight = null;

    public bool $resizeBusy = false;

    #[On('seo-media-library-refresh')]
    public function refreshLibrary(): void
    {
        $this->loadImages();
    }

    public function notifyLocalMediaUpload(string $status, string $title, string $body = ''): void
    {
        $notification = Notification::make()->title($title);

        if ($body !== '') {
            $notification->body($body);
        }

        if ($status === 'danger') {
            $notification->danger()->send();

            return;
        }

        $notification->success()->send();
    }

    public function refreshAfterLocalUpload(int $uploadedCount = 1): void
    {
        if (! in_array($this->activeTab, ['local', 'generated'], true)) {
            $this->activeTab = 'local';
        }

        $this->page = 1;
        $this->loadImages();

        $count = max(1, $uploadedCount);

        Notification::make()
            ->title($count === 1
                ? __('seo-content-ai::filament.media_tools.upload_success_one')
                : __('seo-content-ai::filament.media_tools.upload_success_many', ['count' => $count]))
            ->body(__('seo-content-ai::filament.media_tools.upload_success_body'))
            ->success()
            ->send();
    }

    #[On('seo-magic-eraser-saved')]
    public function onMagicEraserSaved(string $url, ?int $imageId = null, bool $pendingWpSync = false): void
    {
        if (is_array($this->previewImage)) {
            $this->previewImage['url'] = $url;
            if ($imageId !== null && $imageId > 0) {
                $this->previewImage['seo_media_id'] = $imageId;
            }
        }

        if ($pendingWpSync) {
            $this->previewPendingWpSync = true;
        }

        $mediaId = $imageId ?? (int) ($this->previewImage['seo_media_id'] ?? 0);
        $media = $mediaId > 0 ? SeoMedia::query()->find($mediaId) : null;
        if ($media !== null) {
            $this->previewPendingWpSync = app(SeoMediaWpEditStagingService::class)->canSyncToWordPress($media);
        } elseif ($pendingWpSync) {
            $this->previewPendingWpSync = true;
        }

        $this->previewMessage = __('seo-content-ai::filament.media_runtime.image_edits_saved');
        $this->previewMessageType = 'success';
        $this->previewOpen = true;
        $this->syncPreviewWpSyncState();
        $this->loadImages();
    }

    public function openImageEditor(): void
    {
        if ($this->previewImage === null || $this->siteId === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.domain_not_found'))->danger()->send();

            return;
        }

        try {
            $resolved = app(SeoMediaImageEditorResolverService::class)
                ->resolve($site, $this->previewImage);
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.unable_to_open_editor'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $imageId = (int) $resolved['seo_media_id'];
        $this->previewImage['seo_media_id'] = $imageId;
        if ((int) ($this->previewImage['wp_attachment_id'] ?? 0) > 0) {
            $this->previewImage['kind'] = 'wordpress';
        }

        $this->previewPendingWpSync = false;
        $this->previewOpen = false;

        $this->js('window.open('.json_encode($resolved['editor_url']).', "_blank")');
    }

    public function previewSyncToWordPress(): void
    {
        if ($this->previewImage === null || $this->siteId === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.domain_not_found'))->danger()->send();

            return;
        }

        $mediaId = (int) ($this->previewImage['seo_media_id'] ?? 0);
        $media = SeoMedia::query()
            ->where('site_id', $site->id)
            ->whereKey($mediaId)
            ->first();

        if ($media === null) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.staging_copy_not_found'))->warning()->send();

            return;
        }

        $this->previewBusy = true;
        $this->previewMessage = null;

        $result = app(SeoMediaWpEditStagingService::class)->syncStagingToWordPress($site, $media);

        $this->previewBusy = false;

        if (! ($result['success'] ?? false)) {
            $this->previewMessage = (string) ($result['message'] ?? __('seo-content-ai::filament.media_runtime.sync_failed'));
            $this->previewMessageType = 'error';
            Notification::make()->title($this->previewMessage)->warning()->send();

            return;
        }

        $wpUrl = (string) ($result['url'] ?? $this->previewImage['url'] ?? '');
        if ($wpUrl !== '') {
            $this->previewImage['url'] = $wpUrl;
        }

        $this->previewPendingWpSync = false;
        $this->previewMessage = (string) ($result['message'] ?? __('seo-content-ai::filament.media_runtime.synced_to_wordpress'));
        $this->previewMessageType = 'success';
        $this->syncPreviewProcessingState($this->previewImage);
        $this->syncPreviewWpSyncState();

        Notification::make()->title($this->previewMessage)->success()->send();

        $this->loadImages();
    }

    public function mount(): void
    {
        if ($this->siteId === null) {
            $globalSiteId = SeoAccessControl::globalSiteId();
            if ($globalSiteId !== null) {
                $this->siteId = $globalSiteId;
            } else {
                $firstSite = $this->resolveSitesQuery()->first();
                $this->siteId = $firstSite instanceof Site ? (int) $firstSite->id : null;
            }
        }

        $this->normalizeFilters();
        $this->filterSearchInput = $this->filterSearch;
        $this->loadImages();
    }

    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $this->siteId = $globalSiteId;
        }

        $this->page = 1;
        $this->normalizeFilters();
        $this->filterSearchInput = $this->filterSearch;
        $this->loadImages();
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'generated') {
            $this->activeTab = 'local';
        }
    }

    public function updated($propertyName): void
    {
        if (in_array($propertyName, ['activeTab', 'siteId', 'filterMonth'], true)) {
            $this->normalizeFilters();
            $this->page = 1;
            $this->loadImages();

            return;
        }

        if ($propertyName === 'page') {
            $this->loadImages();
        }
    }

    public function clearMonthFilter(): void
    {
        $this->filterMonth = null;
        $this->page = 1;
        $this->loadImages();
    }

    public function applyFilterSearch(): void
    {
        $search = trim((string) ($this->filterSearchInput ?? ''));
        $this->filterSearch = $search !== '' ? $search : null;
        $this->filterSearchInput = $this->filterSearch;
        $this->page = 1;
        $this->loadImages();
    }

    public function clearSearchFilter(): void
    {
        $this->filterSearch = null;
        $this->filterSearchInput = null;
        $this->page = 1;
        $this->loadImages();
    }

    public function beginSlugEdit(
        string $key,
        string $slug,
        int $imageId,
        string $url,
        int $wpAttachmentId,
        string $kind = 'local',
        int $seoMediaId = 0,
    ): void {
        $this->editingKey = $key;
        $this->editingSlug = $slug;
        $this->editingContext = [
            'id' => $imageId,
            'url' => $url,
            'wp_attachment_id' => $wpAttachmentId,
            'kind' => $kind,
            'seo_media_id' => $seoMediaId > 0 ? $seoMediaId : $imageId,
        ];
    }

    public function cancelSlugEdit(): void
    {
        $this->editingKey = null;
        $this->editingSlug = '';
        $this->editingContext = [];
    }

    public function saveSlugEdit(): void
    {
        if ($this->editingKey === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.domain_not_found'))
                ->danger()
                ->send();

            return;
        }

        $newSlug = Str::slug(trim($this->editingSlug));
        if ($newSlug === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.invalid_slug'))
                ->danger()
                ->send();

            return;
        }

        $context = $this->editingContext;
        $this->cancelSlugEdit();

        if ($this->activeTab === 'local') {
            $kind = (string) ($context['kind'] ?? 'local');
            if ($kind === 'generated') {
                $result = app(GeneratedImageLibraryService::class)->updateSlug(
                    $site,
                    (int) ($context['id'] ?? 0),
                    $newSlug,
                );
            } else {
                $media = SeoMedia::query()
                    ->where('site_id', $site->id)
                    ->whereKey((int) ($context['seo_media_id'] ?? $context['id'] ?? 0))
                    ->first();

                if ($media === null) {
                    $result = [
                        'success' => false,
                        'message' => __('seo-content-ai::filament.media_runtime.local_image_not_found'),
                    ];
                } else {
                    try {
                        app(SeoMediaLibraryService::class)->renameLocalBySlug($media, $newSlug);
                        $result = [
                            'success' => true,
                            'message' => __('seo-content-ai::filament.media_runtime.updated_file_slug'),
                        ];
                    } catch (\InvalidArgumentException|\RuntimeException $e) {
                        $result = [
                            'success' => false,
                            'message' => $e->getMessage(),
                        ];
                    }
                }
            }
        } else {
            $result = app(WordPressMediaLibraryService::class)->updateSlug(
                $site,
                (int) ($context['wp_attachment_id'] ?? $context['id'] ?? 0),
                $newSlug,
                (string) ($context['url'] ?? ''),
            );
        }

        if (! ($result['success'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.unable_to_update_slug'))
                ->body((string) ($result['message'] ?? ''))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.media_runtime.slug_updated'))
            ->body((string) ($result['message'] ?? ''))
            ->success()
            ->send();

        $this->loadImages();
    }

    public function loadImages(): void
    {
        $this->cancelSlugEdit();
        $this->selectedKeys = [];
        $this->selectionAnchorKey = null;
        $this->loadError = null;
        $this->images = [];
        $this->total = 0;
        $this->totalPages = 1;

        if ($this->siteId === null || $this->siteId <= 0) {
            $this->loadError = __('seo-content-ai::filament.media_runtime.select_domain_to_view');

            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            $this->loadError = __('seo-content-ai::filament.media_runtime.domain_not_found_dot');

            return;
        }

        if (! SeoAccessControl::canAccessSite((int) $site->id)) {
            $this->loadError = __('seo-content-ai::filament.media_runtime.domain_not_found_dot');

            return;
        }

        $month = filled($this->filterMonth) ? (string) $this->filterMonth : null;

        $search = filled($this->filterSearch) ? (string) $this->filterSearch : null;

        $accessScope = app(MediaLibraryAccessScope::class);
        $restrictArticleIds = $accessScope->restrictedArticleIdsForSite((int) $site->id);
        $restrictWpAttachmentIds = $accessScope->restrictedWordPressAttachmentIds((int) $site->id, $restrictArticleIds);

        $result = match ($this->activeTab) {
            'local', 'generated' => app(SeoMediaLibraryService::class)->fetch(
                $site,
                $month,
                $this->page,
                $search,
                restrictToArticleIds: $restrictArticleIds,
            ),
            default => app(WordPressMediaLibraryService::class)->fetch(
                $site,
                $month,
                $this->page,
                search: $search,
                includeAttachmentIds: $restrictWpAttachmentIds,
            ),
        };

        $images = is_array($result['images'] ?? null) ? $result['images'] : [];
        $this->images = app(MediaLibraryArticleResolver::class)->enrichImages((int) $site->id, $images);
        $this->total = (int) ($result['total'] ?? 0);
        $this->totalPages = max(1, (int) ($result['total_pages'] ?? 1));
        $this->page = max(1, (int) ($result['page'] ?? $this->page));
        $this->loadError = filled($result['error'] ?? null) ? (string) $result['error'] : null;
    }

    public function previousPage(): void
    {
        if ($this->page <= 1) {
            return;
        }

        $this->page--;
        $this->loadImages();
    }

    public function nextPage(): void
    {
        if ($this->page >= $this->totalPages) {
            return;
        }

        $this->page++;
        $this->loadImages();
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
        if ($this->siteId === null || $this->siteId === '' || (int) $this->siteId <= 0) {
            return null;
        }

        $site = $this->sites->firstWhere('id', (int) $this->siteId);

        return $site instanceof Site ? (string) $site->domain : null;
    }

    public function handleImageSelectClick(string $key, bool $shiftKey = false): void
    {
        if ($shiftKey && filled($this->selectionAnchorKey)) {
            $this->selectImageRange($this->selectionAnchorKey, $key);

            return;
        }

        $this->toggleImageSelection($key);
        $this->selectionAnchorKey = $key;
    }

    public function toggleImageSelection(string $key): void
    {
        $index = array_search($key, $this->selectedKeys, true);
        if ($index !== false) {
            unset($this->selectedKeys[$index]);
            $this->selectedKeys = array_values($this->selectedKeys);

            return;
        }

        $this->selectedKeys[] = $key;
    }

    public function selectImageRange(string $fromKey, string $toKey): void
    {
        $keys = array_map(
            fn (array $image): string => $this->imageSelectionKey($image),
            $this->images,
        );

        $fromIndex = array_search($fromKey, $keys, true);
        $toIndex = array_search($toKey, $keys, true);

        if ($fromIndex === false || $toIndex === false) {
            return;
        }

        $start = min((int) $fromIndex, (int) $toIndex);
        $end = max((int) $fromIndex, (int) $toIndex);

        $this->selectedKeys = array_values(array_slice($keys, $start, $end - $start + 1));
    }

    public function clearImageSelection(): void
    {
        $this->selectedKeys = [];
        $this->selectionAnchorKey = null;
    }

    /**
     * @param  list<string>  $keys
     */
    public function syncSelectedKeys(array $keys, ?string $anchorKey = null): void
    {
        $this->selectedKeys = $this->sanitizeSelectedKeys($keys);
        $this->selectionAnchorKey = $anchorKey !== null && in_array($anchorKey, $this->selectedKeys, true)
            ? $anchorKey
            : null;
    }

    /**
     * @param  list<string>  $keys
     */
    public function resizeSelectedImagesFromClient(array $keys, ?string $anchorKey = null): void
    {
        $this->syncSelectedKeys($keys, $anchorKey);
        $this->resizeSelectedImages();
    }

    /**
     * @param  list<string>  $keys
     * @return array{success: bool, removed_keys: list<string>, staging_only: bool}
     */
    public function deleteSelectedImagesFromClient(array $keys, ?string $anchorKey = null): array
    {
        $this->syncSelectedKeys($keys, $anchorKey);

        return $this->deleteSelectedImages();
    }

    public function isImageSelected(string $key): bool
    {
        return in_array($key, $this->selectedKeys, true);
    }

    public function resizeSelectedImages(): void
    {
        if ($this->siteId === null || $this->siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.select_domain'))
                ->body(__('seo-content-ai::filament.media_runtime.select_domain_before_resizing'))
                ->warning()
                ->send();

            return;
        }

        if ($this->selectedKeys === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.no_images_selected'))
                ->body(__('seo-content-ai::filament.media_runtime.select_image_hint'))
                ->warning()
                ->send();

            return;
        }

        $targetWidth = filled($this->resizeWidth)
            ? max(1, (int) $this->resizeWidth)
            : null;
        $targetHeight = filled($this->resizeHeight)
            ? max(1, (int) $this->resizeHeight)
            : null;

        if ($targetWidth === null && $targetHeight === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.missing_dimensions'))
                ->body(__('seo-content-ai::filament.media_runtime.resize_dimension_hint'))
                ->warning()
                ->send();

            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.domain_not_found'))->danger()->send();

            return;
        }

        $this->resizeBusy = true;
        $actions = app(SeoMediaLibraryImageActionService::class);
        $successCount = 0;
        $failedLabels = [];

        foreach ($this->selectedKeys as $key) {
            $image = $this->findImageRowBySelectionKey($key);
            if ($image === null) {
                continue;
            }

            $result = $actions->resize($site, $image, $targetWidth, $targetHeight);

            if ($result['success'] ?? false) {
                $successCount++;
                $this->patchImageUrlInList($key, (string) ($result['url'] ?? ''));
            } else {
                $failedLabels[] = (string) ($image['slug'] ?? $key);
            }
        }

        $this->resizeBusy = false;
        $this->selectedKeys = [];

        if ($successCount > 0 && $failedLabels === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.resize_complete'))
                ->body("{$successCount} images were updated.")
                ->success()
                ->send();
        } elseif ($successCount > 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.resize_partial'))
                ->body("Success: {$successCount}. Failed: ".implode(', ', array_slice($failedLabels, 0, 5)))
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.resize_failed'))
                ->body(__('seo-content-ai::filament.media_runtime.no_images_updated'))
                ->danger()
                ->send();
        }
    }

    /**
     * @return array{success: bool, removed_keys: list<string>, staging_only: bool}
     */
    public function deleteLibraryImage(string $key): array
    {
        if (! SeoAccessControl::canDeleteSeoMedia()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.delete_denied'))
                ->warning()
                ->send();

            return ['success' => false, 'removed_keys' => [], 'staging_only' => false];
        }

        if ($this->siteId === null || $this->siteId <= 0) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.select_domain'))->warning()->send();

            return ['success' => false, 'removed_keys' => [], 'staging_only' => false];
        }

        $image = $this->findImageRowBySelectionKey($key);
        if ($image === null) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.image_not_found'))->warning()->send();

            return ['success' => false, 'removed_keys' => [], 'staging_only' => false];
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.domain_not_found'))->danger()->send();

            return ['success' => false, 'removed_keys' => [], 'staging_only' => false];
        }

        $result = app(SeoMediaLibraryDeleteService::class)->delete($site, $image);

        if ($result['success'] ?? false) {
            $stagingOnly = ($result['scope'] ?? '') === 'staging';
            $removedKeys = $stagingOnly ? [] : [$key];

            if ($removedKeys !== []) {
                $this->removeImagesFromListByKeys($removedKeys);
            }

            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.deleted'))
                ->body((string) ($result['message'] ?? ''))
                ->success()
                ->send();
            $this->selectedKeys = array_values(array_filter(
                $this->selectedKeys,
                static fn (string $selected): bool => $selected !== $key,
            ));

            return [
                'success' => true,
                'removed_keys' => $removedKeys,
                'staging_only' => $stagingOnly,
            ];
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.media_runtime.delete_failed'))
            ->body((string) ($result['message'] ?? ''))
            ->danger()
            ->send();

        return ['success' => false, 'removed_keys' => [], 'staging_only' => false];
    }

    /**
     * @return array{success: bool, removed_keys: list<string>, staging_only: bool}
     */
    public function deleteSelectedImages(): array
    {
        if (! SeoAccessControl::canDeleteSeoMedia()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.delete_denied'))
                ->warning()
                ->send();

            return ['success' => false, 'removed_keys' => [], 'staging_only' => false];
        }

        if ($this->siteId === null || $this->siteId <= 0) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.select_domain'))->warning()->send();

            return ['success' => false, 'removed_keys' => [], 'staging_only' => false];
        }

        if ($this->selectedKeys === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.no_images_selected'))
                ->body(__('seo-content-ai::filament.media_runtime.select_images_delete_hint'))
                ->warning()
                ->send();

            return ['success' => false, 'removed_keys' => [], 'staging_only' => false];
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.domain_not_found'))->danger()->send();

            return ['success' => false, 'removed_keys' => [], 'staging_only' => false];
        }

        $deleter = app(SeoMediaLibraryDeleteService::class);
        $successCount = 0;
        $successScopes = [];
        $failedMessages = [];
        $removedKeys = [];

        foreach ($this->selectedKeys as $key) {
            $image = $this->findImageRowBySelectionKey($key);
            if ($image === null) {
                continue;
            }

            $result = $deleter->delete($site, $image);
            if ($result['success'] ?? false) {
                $successCount++;
                $scope = strtolower(trim((string) ($result['scope'] ?? '')));
                if ($scope !== '') {
                    $successScopes[] = $scope;
                }
                if ($scope !== 'staging') {
                    $removedKeys[] = $key;
                }
            } else {
                $failedMessages[] = (string) ($image['slug'] ?? $key).': '.($result['message'] ?? '');
            }
        }

        $removedKeys = array_values(array_unique($removedKeys));
        if ($removedKeys !== []) {
            $this->removeImagesFromListByKeys($removedKeys);
        }

        $this->selectedKeys = [];
        $this->selectionAnchorKey = null;

        if ($failedMessages !== []) {
            $this->loadImages();
        }

        $isOriginalTab = $this->activeTab === 'original';
        $hasWordPressDeletes = in_array('wordpress', $successScopes, true);
        $allStagingDeletes = $successCount > 0
            && $successScopes !== []
            && count(array_unique($successScopes)) === 1
            && $successScopes[0] === 'staging';

        if ($successCount > 0 && $failedMessages === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.deleted'))
                ->body(
                    $isOriginalTab && ! $hasWordPressDeletes && $allStagingDeletes
                        ? __('seo-content-ai::filament.media_runtime.deleted_staging_only_bulk', ['count' => $successCount])
                        : "{$successCount} images were deleted.",
                )
                ->success()
                ->send();
        } elseif ($successCount > 0) {
            $partialBody = $isOriginalTab && ! $hasWordPressDeletes && $allStagingDeletes
                ? __('seo-content-ai::filament.media_runtime.deleted_staging_only_partial', ['count' => $successCount])
                : "Success: {$successCount}.";

            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.deleted_partial'))
                ->body($partialBody.' '.implode(' ', array_slice($failedMessages, 0, 2)))
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_runtime.delete_failed'))
                ->body($failedMessages[0] ?? __('seo-content-ai::filament.media_runtime.no_images_deleted'))
                ->danger()
                ->send();
        }

        $stagingOnly = $successCount > 0
            && $successScopes !== []
            && count(array_unique($successScopes)) === 1
            && $successScopes[0] === 'staging'
            && $removedKeys === [];

        return [
            'success' => $successCount > 0,
            'removed_keys' => $removedKeys,
            'staging_only' => $stagingOnly,
        ];
    }

    public function openImagePreview(array $image): void
    {
        if ($this->activeTab === 'original') {
            $image['kind'] = 'wordpress';
        } elseif (in_array($this->activeTab, ['local', 'generated'], true) && empty($image['kind'])) {
            $image['kind'] = 'local';
        }

        if ($this->siteId !== null && $this->siteId > 0) {
            $image = app(SeoWpMediaEditedPendingService::class)
                ->applyPendingToImageRow((int) $this->siteId, $image);
        }

        $this->previewImage = $image;
        $this->previewOpen = true;
        $this->previewBusy = false;
        $this->previewMessage = null;
        $this->previewMessageType = null;
        $this->syncPreviewProcessingState($image);
    }

    public function closeImagePreview(): void
    {
        $this->previewOpen = false;
        $this->previewImage = null;
        $this->previewBusy = false;
        $this->previewMessage = null;
        $this->previewPendingWpSync = false;
        $this->previewCanSyncToWp = false;
    }

    public function previewApplyWatermark(): void
    {
        $this->runPreviewAction('watermark');
    }

    public function previewOptimize(): void
    {
        $this->runPreviewAction('optimize');
    }

    public function previewRestore(): void
    {
        if ($this->previewImage === null || $this->siteId === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.domain_not_found'))->danger()->send();

            return;
        }

        $this->previewBusy = true;
        $this->previewMessage = null;

        $result = app(SeoMediaLibraryImageActionService::class)->restore($site, $this->previewImage);

        $this->previewBusy = false;

        if (! ($result['success'] ?? false)) {
            $this->previewMessage = (string) ($result['message'] ?? __('seo-content-ai::filament.media_runtime.unable_to_restore'));
            $this->previewMessageType = 'error';
            Notification::make()->title($this->previewMessage)->warning()->send();

            return;
        }

        $this->previewImage['url'] = (string) ($result['url'] ?? $this->previewImage['url']);
        $this->previewMessage = (string) ($result['message'] ?? __('seo-content-ai::filament.media_runtime.restored'));
        $this->previewMessageType = 'success';
        $this->syncPreviewProcessingState($this->previewImage);

        $wpAttachmentId = (int) ($this->previewImage['wp_attachment_id'] ?? 0);
        if ($wpAttachmentId > 0) {
            app(SeoMediaWpEditStagingService::class)->resetStagingFromWordPressBackup($site, $wpAttachmentId);
        }

        $this->previewPendingWpSync = false;
        $this->syncPreviewWpSyncState();

        Notification::make()->title($this->previewMessage)->success()->send();

        $this->loadImages();
    }

    /**
     * @param  array<string, mixed>|null  $image
     */
    private function syncPreviewProcessingState(?array $image): void
    {
        $this->previewCanRestore = false;
        $this->previewCanOptimize = false;
        $this->previewProcessingStatus = null;

        if ($image === null || $this->siteId === null || $this->siteId <= 0) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            return;
        }

        $state = app(SeoMediaLibraryImageActionService::class)->previewState($site, $image);
        $this->previewCanRestore = (bool) ($state['can_restore'] ?? false);
        $this->previewCanOptimize = (bool) ($state['can_optimize'] ?? false);
        $this->previewProcessingStatus = (string) ($state['status'] ?? 'original');
        $this->syncPreviewWpSyncState();
    }

    private function syncPreviewWpSyncState(): void
    {
        $this->previewCanSyncToWp = false;

        if (! is_array($this->previewImage) || $this->siteId === null || $this->siteId <= 0) {
            return;
        }

        $siteId = (int) $this->siteId;
        $wpAttachmentId = (int) ($this->previewImage['wp_attachment_id'] ?? $this->previewImage['id'] ?? 0);
        $pendingService = app(SeoWpMediaEditedPendingService::class);

        if ($wpAttachmentId > 0 && $pendingService->canSyncPending($siteId, $wpAttachmentId)) {
            $this->previewCanSyncToWp = true;
            $this->previewPendingWpSync = true;

            return;
        }

        $mediaId = (int) ($this->previewImage['seo_media_id'] ?? 0);
        if ($mediaId > 0) {
            $media = SeoMedia::query()->find($mediaId);
            $this->previewCanSyncToWp = app(SeoMediaWpEditStagingService::class)->canSyncToWordPress($media);
        }
    }

    private function runPreviewAction(string $action): void
    {
        if ($this->previewImage === null || $this->siteId === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title(__('seo-content-ai::filament.media_runtime.domain_not_found'))->danger()->send();

            return;
        }

        $this->previewBusy = true;
        $this->previewMessage = null;

        $service = app(SeoMediaLibraryImageActionService::class);
        $result = $action === 'watermark'
            ? $service->applyWatermark($site, $this->previewImage)
            : $service->optimize($site, $this->previewImage);

        $this->previewBusy = false;

        if (! ($result['success'] ?? false)) {
            $this->previewMessage = (string) ($result['message'] ?? __('seo-content-ai::filament.media_runtime.action_failed'));
            $this->previewMessageType = 'error';
            Notification::make()
                ->title($this->previewMessage)
                ->warning()
                ->send();

            return;
        }

        $this->previewImage['url'] = (string) ($result['url'] ?? $this->previewImage['url']);
        $this->previewMessage = (string) ($result['message'] ?? __('seo-content-ai::filament.media_runtime.processed'));
        $this->previewMessageType = 'success';

        $this->previewCanRestore = (bool) ($result['can_restore'] ?? false);
        $this->previewCanOptimize = (bool) ($result['can_optimize'] ?? false);

        Notification::make()
            ->title($this->previewMessage)
            ->success()
            ->send();

        $this->loadImages();

        if ($this->previewImage !== null) {
            foreach ($this->images as $row) {
                $sameKind = ($row['kind'] ?? '') === ($this->previewImage['kind'] ?? '');
                $sameId = (int) ($row['id'] ?? 0) === (int) ($this->previewImage['id'] ?? 0);
                if ($sameKind && $sameId) {
                    $this->previewImage = $row;
                    $this->previewImage['url'] = (string) ($result['url'] ?? $row['url']);

                    break;
                }
            }
        }
    }

    public function filterMonthLabel(): string
    {
        if (! filled($this->filterMonth)) {
            return '';
        }

        try {
            return \Carbon\Carbon::createFromFormat('Y-m', (string) $this->filterMonth)->format('m/Y');
        } catch (\Throwable) {
            return (string) $this->filterMonth;
        }
    }

    private function normalizeFilters(): void
    {
        $siteId = $this->siteId;
        if ($siteId === null || $siteId === '' || (int) $siteId <= 0) {
            $this->siteId = null;
        } else {
            $this->siteId = (int) $siteId;
        }

        $month = trim((string) ($this->filterMonth ?? ''));
        $this->filterMonth = $month !== '' ? $month : null;

        $search = trim((string) ($this->filterSearch ?? ''));
        $this->filterSearch = $search !== '' ? $search : null;

        if ($this->activeTab === 'generated') {
            $this->activeTab = 'local';
        }
    }

    private function resolveSitesQuery()
    {
        return SeoAccessControl::accessibleSitesQuery()->orderBy('domain');
    }

    /**
     * @return list<array{id: int, domain: string}>
     */
    public function getSitesListForJs(): array
    {
        return $this->sites->map(fn (Site $site): array => [
            'id' => (int) $site->id,
            'domain' => (string) $site->domain,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private function imageSelectionKey(array $image): string
    {
        $kind = (string) ($image['kind'] ?? 'local');

        return $kind.'-'.$image['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findImageRowBySelectionKey(string $key): ?array
    {
        foreach ($this->images as $image) {
            if ($this->imageSelectionKey($image) === $key) {
                return $image;
            }
        }

        return null;
    }

    private function patchImageUrlInList(string $key, string $url): void
    {
        if ($url === '') {
            return;
        }

        foreach ($this->images as $index => $image) {
            if ($this->imageSelectionKey($image) !== $key) {
                continue;
            }

            $this->images[$index]['url'] = $url;
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function removeImagesFromListByKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $removeSet = array_flip($keys);
        $before = count($this->images);
        $this->images = array_values(array_filter(
            $this->images,
            fn (array $image): bool => ! isset($removeSet[$this->imageSelectionKey($image)]),
        ));

        $removed = $before - count($this->images);
        if ($removed > 0) {
            $this->total = max(0, $this->total - $removed);
        }
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function sanitizeSelectedKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $allowed = array_flip(array_map(
            fn (array $image): string => $this->imageSelectionKey($image),
            $this->images,
        ));

        $sanitized = [];
        foreach ($keys as $key) {
            if (! is_string($key) || ! isset($allowed[$key])) {
                continue;
            }

            $sanitized[$key] = $key;
        }

        return array_values($sanitized);
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessContentFeatures();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$navigationGroup;
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.media_library');
    }

    /**
     * @return array<int, NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return [
            NavigationItem::make(static::getNavigationLabel())
                ->icon(static::getNavigationIcon())
                ->group(static::getNavigationGroup())
                ->isActiveWhen(fn (): bool => SeoPanelRoutes::is('filament.seo.pages.media-library'))
                ->sort(static::getNavigationSort())
                ->url(static::getUrl(['activeTab' => 'original'])),
        ];
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.nav.media_library');
    }
}
