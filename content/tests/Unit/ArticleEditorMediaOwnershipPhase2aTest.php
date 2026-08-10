<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Media\Http\Controllers\ArticleEditorMediaSnapshotController;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorMediaMutationService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorMediaSnapshotService;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 2A â€” Featured/Gallery media snapshot ownership contracts.
 */
final class ArticleEditorMediaOwnershipPhase2aTest extends TestCase
{
    public function test_snapshot_service_schema_and_version_meta(): void
    {
        $class = new ReflectionClass(ArticleEditorMediaSnapshotService::class);
        self::assertTrue($class->hasMethod('build'));
        self::assertTrue($class->hasMethod('bumpVersion'));
        self::assertTrue($class->hasMethod('assertExpectedVersion'));
        self::assertSame('editor_media_snapshot_version', ArticleEditorMediaSnapshotService::META_SNAPSHOT_VERSION);

        $source = (string) file_get_contents((string) $class->getFileName());
        self::assertStringContainsString("'featured'", $source);
        self::assertStringContainsString("'gallery'", $source);
        self::assertStringContainsString("'content_images'", $source);
        self::assertStringContainsString("'snapshot_version'", $source);
        self::assertStringContainsString("'capabilities'", $source);
        self::assertStringContainsString('featured_alt_missing', $source);
        self::assertStringNotContainsString('featured_slug_not_fixed', $source);
    }

    public function test_mutation_service_guards_session_and_returns_snapshot(): void
    {
        $class = new ReflectionClass(ArticleEditorMediaMutationService::class);
        foreach (['setFeatured', 'clearFeatured', 'replaceGallery', 'reorderGallery'] as $method) {
            self::assertTrue($class->hasMethod($method), $method);
        }

        $source = (string) file_get_contents((string) $class->getFileName());
        self::assertStringContainsString('assertOwningActiveSessionForWrite', $source);
        self::assertStringContainsString('assertArticleEditable', $source);
        self::assertStringContainsString('assertExpectedVersion', $source);
        self::assertStringContainsString('bumpVersion', $source);
        self::assertStringContainsString('clearFeaturedLocal', $source);
    }

    public function test_media_local_clear_featured_exists(): void
    {
        self::assertTrue(method_exists(ArticleMediaLocalService::class, 'clearFeaturedLocal'));
    }

    public function test_featured_wp_sync_keeps_remote_attachment_identity(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Services/ArticleMediaLocalService.php',
        );

        self::assertStringContainsString('$this->markMediaPendingSync($article);', $source);
        self::assertStringContainsString('isWordPressMediaUrl', $source);
        self::assertStringContainsString("'/wp-content/uploads/'", $source);
        self::assertStringContainsString("'attachment_id' => \$refId", $source);
        self::assertStringContainsString('resolveGalleryAttachmentRefs', $source);
        self::assertStringContainsString('hasStoredFeaturedOrGalleryRefs', $source);
    }

    public function test_controller_routes_and_response_shape(): void
    {
        $class = new ReflectionClass(ArticleEditorMediaSnapshotController::class);
        foreach (['show', 'setFeatured', 'clearFeatured', 'replaceGallery', 'reorderGallery'] as $method) {
            self::assertTrue($class->hasMethod($method), $method);
        }

        $provider = (string) file_get_contents(
            LegacyAddonPath::resolve('Providers/SeoPanelProvider.php'),
        );
        self::assertStringContainsString('editor/media-snapshot', $provider);
        self::assertStringContainsString('editor/media/featured', $provider);
        self::assertStringContainsString('editor/media/gallery', $provider);
        self::assertStringContainsString('editor/media/gallery/reorder', $provider);
    }

    public function test_bootstrap_embeds_media_snapshot(): void
    {
        $editArticle = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        self::assertStringContainsString("'mediaSnapshot'", $editArticle);
        self::assertStringContainsString('ArticleEditorMediaSnapshotService', $editArticle);
        self::assertStringContainsString("'mediaSnapshot' => route('seo.articles.editor.media-snapshot'", $editArticle);
    }

    public function test_frontend_no_localstorage_featured_gallery_sot(): void
    {
        $featured = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/articleFeaturedImageStorage.js',
        );
        self::assertStringNotContainsString('localStorage.setItem', $featured);
        self::assertStringNotContainsString('localStorage.getItem', $featured);
        self::assertStringContainsString('featuredFromSnapshot', $featured);
        self::assertStringContainsString('setFeaturedViaApi', $featured);

        $album = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/articleProductAlbumStorage.js',
        );
        self::assertStringNotContainsString('localStorage.setItem', $album);
        self::assertStringNotContainsString('localStorage.getItem', $album);
        self::assertStringContainsString('replaceGalleryViaApi', $album);

        $snap = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorMediaSnapshot.js',
        );
        self::assertStringContainsString("ARTICLE_EDITOR_MEDIA_SNAPSHOT_EVENT = 'article-editor-media-snapshot-changed'", $snap);
        self::assertStringContainsString('discardLegacyMediaLocalStorage', $snap);
        self::assertStringContainsString('incoming < currentVersion', $snap);

        $api = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorApi.js',
        );
        self::assertStringContainsString('getMediaSnapshot(articleId)', $api);
        self::assertStringContainsString('featured_image', $api);
        self::assertStringContainsString('product_album', $api);
        self::assertStringContainsString('media_snapshot', $api);
        self::assertStringContainsString('flushMedia', $api);
        self::assertStringContainsString('hasOwnProperty.call(mediaSnapshot, \'featured\')', $api);

        $entry = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString('applyMediaSnapshot', $entry);
        self::assertStringContainsString('discardLegacyMediaLocalStorage', $entry);
        self::assertStringContainsString('mediaSnapshot', $entry);
    }

    public function test_alpine_no_longer_owns_media_snapshot_shadow(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/edit-article.blade.php'),
        );
        // Phase 6C.3+ / legacy cleanup: React media snapshot SoT; Alpine stubs removed.
        self::assertStringNotContainsString('article-editor-media-snapshot-changed.window', $blade);
        self::assertStringNotContainsString('onMediaSnapshotChanged', $blade);
        self::assertStringNotContainsString('featuredImageDraft', $blade);

        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('article-editor-media-snapshot-changed', $editor);
    }

    public function test_images_utils_no_longer_write_featured_localstorage(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/articleImagesUtils.js',
        );
        $start = strpos($source, 'export function applyRenameMapToFeaturedImageStorage');
        self::assertNotFalse($start);
        $body = substr($source, $start, 1200);
        self::assertStringNotContainsString('localStorage.setItem', $body);
        self::assertStringContainsString('setFeaturedViaApi', $body);
    }
}
