<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

final class ArticleEditorRuntimeMediaPhase6c3Test extends TestCase
{
    private function js(string $relative): string
    {
        return ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
    }

    private function mediaJs(string $relative): string
    {
        return ProjectRoot::addonsPath().'/media/resources/js/'.$relative;
    }

    private function bladeEditArticle(): string
    {
        return LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/edit-article.blade.php');
    }

    public function test_featured_panel_renders_from_runtime_module(): void
    {
        $mod = (string) file_get_contents($this->js('editor/modules/featured/index.js'));
        self::assertStringContainsString("host: 'editor'", $mod);
        self::assertStringContainsString('FeaturedSidebarPanel', $mod);
        self::assertStringContainsString("portalRootKey: 'featured'", $mod);
        self::assertFileExists($this->js('editor/modules/featured/FeaturedSidebarPanel.jsx'));

        $panel = (string) file_get_contents($this->js('editor/modules/featured/FeaturedSidebarPanel.jsx'));
        self::assertStringContainsString('useEditorMedia', $panel);
        self::assertStringContainsString('useEditorMediaPicker', $panel);
        self::assertStringContainsString("mode: 'featured'", $panel);
        self::assertStringNotContainsString('window.Alpine', $panel);
        self::assertStringNotContainsString('localStorage', $panel);
        self::assertStringNotContainsString('dispatchEvent', $panel);
    }

    public function test_blade_has_featured_portal_not_primary_alpine_ui(): void
    {
        $blade = (string) file_get_contents($this->bladeEditArticle());
        self::assertStringContainsString('seo-article-featured-root', $blade);
        self::assertStringContainsString('article-editor-media-picker-root', $blade);
        self::assertStringContainsString('data-editor-portal="media.picker"', $blade);
        self::assertStringNotContainsString('wp-featured-image-picker', $blade);
        self::assertStringNotContainsString('seoProductAlbumBoxData', $blade);
        self::assertStringNotContainsString('x-show="mediaModalOpen"', $blade);
        self::assertStringNotContainsString('featuredImageDraft', $blade);
        self::assertStringNotContainsString('mediaModalOpen', $blade);
        self::assertStringContainsString('__seoOpenSharedMediaPicker', $blade);
    }

    public function test_gallery_module_nav_chip_false_ui_via_featured(): void
    {
        $mod = (string) file_get_contents($this->mediaJs('editor/modules/gallery/index.js'));
        self::assertStringContainsString('navChip: false', $mod);
        self::assertStringContainsString("portalRootKey: 'featured'", $mod);
        self::assertFileExists($this->mediaJs('editor/modules/gallery/GallerySidebarPanel.jsx'));

        $panel = (string) file_get_contents($this->mediaJs('editor/modules/gallery/GallerySidebarPanel.jsx'));
        self::assertStringContainsString('reorderGallery', $panel);
        self::assertStringContainsString('stableId', $panel);
        self::assertStringContainsString("mode: 'gallery'", $panel);
        self::assertStringNotContainsString('localStorage', $panel);
        self::assertStringContainsString("className=\"wp-product-gallery-generate", $panel);
        self::assertStringContainsString("seo-open-generate-image-modal", $panel);
        self::assertStringContainsString("target: 'product-gallery'", $panel);
        self::assertStringContainsString("t('generate_product_gallery_image')", $panel);
        self::assertStringContainsString('seo-editor-distribute-product-gallery', $panel);
        self::assertStringContainsString("t('product_gallery_distribute')", $panel);
    }

    public function test_featured_navigation_does_not_open_picker_and_product_gallery_is_multi_select(): void
    {
        $featured = (string) file_get_contents($this->js('editor/modules/featured/FeaturedSidebarPanel.jsx'));
        self::assertStringContainsString('className="wp-featured-image-picker"', $featured);
        self::assertStringNotContainsString('className="wp-featured-image-picker"'."\n".'                        onClick={openPicker}', $featured);
        self::assertStringContainsString('onClick={openPicker}', $featured);
        self::assertStringContainsString("mode: 'featured'", $featured);
        self::assertStringContainsString("selection: 'single'", $featured);
        self::assertStringNotContainsString('seo-open-generate-image-modal', $featured);
        self::assertStringNotContainsString("target: 'product-gallery'", $featured);
        self::assertStringNotContainsString("mode: 'gallery'", $featured);

        $gallery = (string) file_get_contents($this->mediaJs('editor/modules/gallery/GallerySidebarPanel.jsx'));
        self::assertStringContainsString("mode: 'gallery'", $gallery);
        self::assertStringContainsString("selection: 'multiple'", $gallery);
        self::assertStringContainsString('await media.replaceGallery(merged)', $gallery);
        self::assertStringNotContainsString('setFeatured', $gallery);
        self::assertStringContainsString("seo-open-generate-image-modal", $gallery);
        self::assertStringContainsString("target: 'product-gallery'", $gallery);

        $host = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        $featuredReasonStart = strpos($host, "code === 'featured_missing'");
        self::assertNotFalse($featuredReasonStart);
        $featuredReasonBranch = substr($host, $featuredReasonStart, 500);
        self::assertStringContainsString("openPanel('featured'", $featuredReasonBranch);
        self::assertStringNotContainsString('openMediaPicker', $featuredReasonBranch);

        $nav = (string) file_get_contents($this->js('editor/host/EditorSidebarNavigation.jsx'));
        self::assertStringContainsString("if (chipId === 'featured')", $nav);
        self::assertStringContainsString('return null;', $nav);
    }

    public function test_shared_media_picker_single_portal_and_modes(): void
    {
        self::assertFileExists($this->js('editor/host/SharedMediaPicker.jsx'));
        self::assertFileExists($this->js('editor/runtime/editorMediaPickerStore.js'));
        self::assertFileExists($this->js('editor/host/hooks/useEditorMediaPicker.js'));
        self::assertFileExists($this->js('editor/host/hooks/useEditorMedia.js'));

        $picker = (string) file_get_contents($this->js('editor/host/SharedMediaPicker.jsx'));
        self::assertStringContainsString('createPortal', $picker);
        self::assertStringContainsString("t('media_picker_tab_wp')", $picker);
        self::assertStringContainsString("t('media_picker_tab_article')", $picker);
        self::assertStringContainsString("t('media_picker_tab_local')", $picker);
        self::assertStringContainsString('data-media-picker-refresh="1"', $picker);
        // WP tab must not be disabled solely because article is unsynced.
        self::assertStringNotContainsString('disabled={!wordpressAvailable}', $picker);
        self::assertStringNotContainsString('getElementById', $picker);
        self::assertStringNotContainsString('querySelector', $picker);

        $i18n = (string) file_get_contents($this->js('utils/i18n.js'));
        self::assertStringContainsString('media_picker_tab_wp:', $i18n);
        self::assertStringContainsString('Gá»‘c (WP)', $i18n);

        $store = (string) file_get_contents($this->js('editor/runtime/editorMediaPickerStore.js'));
        self::assertStringContainsString("content_image'|'featured'|'gallery'", $store);
        self::assertStringContainsString('export function openMediaPicker', $store);

        $host = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringContainsString('SharedMediaPicker', $host);
        self::assertStringContainsString('article-editor-media-picker-root', $host);
        self::assertStringContainsString('installMediaPickerCompatibilityBridge', $host);
        self::assertStringContainsString('seo-article-featured-root', $host);
    }

    public function test_shared_media_picker_preserves_session_cache_between_reopens(): void
    {
        $picker = (string) file_get_contents($this->js('editor/host/SharedMediaPicker.jsx'));

        self::assertStringContainsString('MEDIA_PICKER_CACHE_TTL_MS = 4 * 60 * 1000', $picker);
        self::assertStringContainsString('MEDIA_PICKER_CACHE_MAX_ENTRIES = 30', $picker);
        self::assertStringContainsString('mediaPickerResultCache = new Map()', $picker);
        self::assertStringContainsString('mediaPickerUiState = new Map()', $picker);
        self::assertStringContainsString('mediaPickerScrollState = new Map()', $picker);
        self::assertStringContainsString('mediaPickerInFlight.has(key)', $picker);
        self::assertStringContainsString('setSelectedKeys(Array.isArray(saved.selectedKeys)', $picker);
        self::assertStringContainsString('setTab(savedTab)', $picker);
        self::assertStringContainsString('setTabStates(saved.tabStates', $picker);
        self::assertStringContainsString('gridRef.current.scrollTop = savedScroll', $picker);
        self::assertStringContainsString('patchMediaPickerSelection(selectedKeys, selectedItems)', $picker);
        self::assertStringContainsString('seo-article-media-picker-cache-invalidated', $picker);
        self::assertStringNotContainsString('cacheRef.current.clear()', $picker);
        self::assertStringNotContainsString('inFlightRef.current.clear()', $picker);

        $cache = (string) file_get_contents($this->js('utils/articleMediaPickerCache.js'));
        self::assertStringContainsString('seo-article-media-picker-cache-invalidated', $cache);
    }

    public function test_shared_media_picker_cache_key_is_scoped_and_query_aware(): void
    {
        $picker = (string) file_get_contents($this->js('editor/host/SharedMediaPicker.jsx'));

        self::assertStringContainsString('export function mediaPickerCacheKey', $picker);
        self::assertStringContainsString('scope:${cacheScope(articleId)}', $picker);
        self::assertStringContainsString('article:${Number(articleId || 0)}', $picker);
        self::assertStringContainsString('source:${String(source ||', $picker);
        self::assertStringContainsString('q:${normalizeSearch(query)}', $picker);
        self::assertStringContainsString('page:${Math.max(1, Number(page) || 1)}', $picker);
        self::assertStringContainsString('perPage:${Math.max(1, Number(perPage) || 28)}', $picker);
    }

    public function test_content_image_uses_shared_picker_and_insert_command(): void
    {
        $block = (string) file_get_contents($this->js('components/ImageBlockEditor.jsx'));
        self::assertStringContainsString('openMediaPicker', $block);
        self::assertStringContainsString("mode: 'content_image'", $block);
        self::assertStringContainsString("executeEditorCommand('insert_image'", $block);
        self::assertStringNotContainsString('seo-open-article-media-picker', $block);

        $bridge = (string) file_get_contents($this->js('editor/runtime/mediaPickerCompatibilityBridge.js'));
        self::assertStringContainsString("executeEditorCommand('insert_image'", $bridge);
        self::assertStringContainsString('setFeaturedViaApi', $bridge);
        self::assertStringContainsString('replaceGalleryViaApi', $bridge);
    }

    public function test_media_api_service_uses_snapshot_version(): void
    {
        $snap = (string) file_get_contents($this->js('utils/articleEditorMediaSnapshot.js'));
        self::assertStringContainsString('expected_snapshot_version', $snap);
        self::assertStringContainsString('incoming < currentVersion', $snap);
        self::assertStringContainsString('inFlightRequests = new Map()', $snap);
        self::assertStringContainsString('media_snapshot_version_conflict', $snap);
        self::assertStringContainsString('fetchMediaSnapshot(id)', $snap);
        self::assertStringContainsString('featured_managed_by_gallery', $snap);
        self::assertStringContainsString('emitLegacyGallery = false', $snap);
        self::assertStringContainsString('refreshMediaSnapshotIfStale', $snap);
        self::assertStringContainsString('subscribeMediaSnapshot', $snap);
        self::assertStringContainsString('setFeaturedViaApi', $snap);
        self::assertStringContainsString('reorderGalleryViaApi', $snap);

        $hook = (string) file_get_contents($this->js('editor/host/hooks/useEditorMedia.js'));
        self::assertStringContainsString('setFeaturedViaApi', $hook);
        self::assertStringContainsString('clearFeaturedViaApi', $hook);
        self::assertStringContainsString('replaceGalleryViaApi', $hook);
        self::assertStringContainsString('reorderGalleryViaApi', $hook);
        self::assertStringContainsString('canMutateEditor', $hook);
    }

    public function test_media_identity_uses_composite_asset_key_not_bare_numeric_id(): void
    {
        $snap = (string) file_get_contents($this->js('utils/articleEditorMediaSnapshot.js'));
        self::assertStringContainsString('asset_key', $snap);
        self::assertStringContainsString('wp:${wpAttachmentId}', $snap);
        self::assertStringContainsString('local:${seoMediaId}', $snap);

        $picker = (string) file_get_contents($this->js('editor/host/SharedMediaPicker.jsx'));
        self::assertStringContainsString('function imageKey(image)', $picker);
        self::assertStringContainsString('return `wp:${wpId}`', $picker);
        self::assertStringContainsString('return `local:${seoId}`', $picker);

        $panel = (string) file_get_contents($this->mediaJs('editor/modules/gallery/GallerySidebarPanel.jsx'));
        self::assertStringContainsString("item?.asset_key", $panel);

        $service = ProjectRoot::addonsPath().'/content/src/Services/ArticleEditor/ArticleEditorMediaSnapshotService.php';
        $source = (string) file_get_contents($service);
        self::assertStringContainsString("'asset_key' => \$assetKey", $source);
        self::assertStringContainsString("return 'wp:'.\$wpAttachmentId", $source);
        self::assertStringContainsString("return 'local:'.\$mediaId", $source);
        self::assertStringContainsString('isLocalLaravelMediaUrl($url)', $source);

        $local = ProjectRoot::addonsPath().'/media/src/Services/ArticleMediaLocalService.php';
        $localSource = (string) file_get_contents($local);
        self::assertStringContainsString("'asset_key' => \$assetKey", $localSource);
        self::assertStringContainsString("(string) (\$row['asset_key'] ?? '') === \$assetKey", $localSource);
    }

    public function test_wp_protection_fix_slug_all_skips_wp_and_picker_does_not_rename(): void
    {
        $fixSlug = ProjectRoot::addonsPath().'/media/src/Services/SeoMediaArticleSlugFixService.php';
        self::assertFileExists($fixSlug);
        $body = (string) file_get_contents($fixSlug);
        self::assertStringContainsString('wordpress_media_requires_explicit_rename', $body);
        self::assertStringContainsString('WordPress-linked media never bulk-renamed', $body);

        $picker = (string) file_get_contents($this->js('editor/host/SharedMediaPicker.jsx'));
        self::assertStringNotContainsString('rename', strtolower($picker));

        $health = (string) file_get_contents($this->js('utils/assistantWidgetHealth.js'));
        self::assertStringContainsString('isWordPressProtectedMedia', $health);
        self::assertStringContainsString('ALT / local slug integrity belong to Images unified inventory', $health);
        self::assertStringContainsString('rowHasLocalPlaceholderSlug', $health);
    }

    public function test_health_providers_read_snapshot_inputs(): void
    {
        $host = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringContainsString('featuredFromSnapshot', $host);
        self::assertStringContainsString('galleryFromSnapshot', $host);
        self::assertStringContainsString('subscribeMediaSnapshot', $host);
        self::assertStringNotContainsString("seo-featured-image-updated', onFeaturedUpdated", $host);

        $galleryHealth = (string) file_get_contents($this->js('utils/assistantWidgetHealth.js'));
        self::assertStringContainsString('gallery_item_broken', $galleryHealth);
        self::assertStringContainsString("status: 'neutral'", $galleryHealth);
    }

    public function test_alpine_album_stub_not_writable_sot(): void
    {
        $entry = (string) file_get_contents($this->js('article-editor.jsx'));
        self::assertStringContainsString('no Alpine writable gallery shadow', $entry);
        self::assertStringContainsString('this.albumItems = []', $entry);
        self::assertStringNotContainsString('storage?.reorder', $entry);
    }

    public function test_editor_hosted_includes_featured_and_core_does_not_import_panel_directly(): void
    {
        $modules = (string) file_get_contents($this->js('utils/articleEditorModules.js'));
        self::assertStringContainsString("'featured'", $modules);
        self::assertStringContainsString("panel === 'featured'", $modules);

        $host = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringNotContainsString("from '../editor/modules/featured/FeaturedSidebarPanel'", $host);
        self::assertStringNotContainsString("from '../editor/modules/gallery/GallerySidebarPanel'", $host);
        self::assertStringContainsString('EditorSidebarPortalHost', $host);
    }

    public function test_stale_snapshot_guard_and_no_three_pickers(): void
    {
        $snap = (string) file_get_contents($this->js('utils/articleEditorMediaSnapshot.js'));
        self::assertStringContainsString('if (!force && current && incoming < currentVersion)', $snap);

        $jsRoot = ProjectRoot::addonsPath().'/content/resources/js';
        $pickerFiles = glob($jsRoot.'/editor/**/*MediaPicker*') ?: [];
        self::assertNotEmpty($pickerFiles);
        $duplicateAlpineModal = (string) file_get_contents($this->bladeEditArticle());
        self::assertStringNotContainsString('class="seo-article-media-modal"', $duplicateAlpineModal);
    }
}
