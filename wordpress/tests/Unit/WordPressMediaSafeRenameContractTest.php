<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use Omnichannel\Addons\WordPress\Http\Controllers\WordPressMediaRenameController;
use Omnichannel\Addons\Media\Services\SeoMediaArticleSlugFixService;
use Omnichannel\Addons\WordPress\Services\WordPressMediaRenameService;
use Omnichannel\Addons\WordPress\Services\WordPressAttachmentRenameService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class WordPressMediaSafeRenameContractTest extends TestCase
{
    public function test_fix_slug_all_skips_true_wordpress_media_but_syncs_dual_owned_local_media(): void
    {
        $batch = $this->methodSource(
            new ReflectionMethod(WordPressAttachmentRenameService::class, 'renameBatch'),
        );
        self::assertStringContainsString('wordpress_media_requires_explicit_rename', $batch);

        $edit = $this->methodSource(
            new ReflectionMethod(EditArticle::class, 'renameAttachmentSlugsOnWordPress'),
        );
        self::assertStringContainsString('rejectBulkWordPressRename', $edit);

        $localFix = (string) file_get_contents(
            (new ReflectionClass(SeoMediaArticleSlugFixService::class))->getFileName(),
        );
        self::assertStringContainsString('wordpress_media_requires_explicit_rename', $localFix);
        self::assertStringContainsString('renameForSite($site, $wpItems)', $localFix);
        self::assertStringContainsString('resolveWordPressRenameOldUrl', $localFix);

        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/articleImagesUtils.js',
        );
        self::assertStringContainsString('includeWordPressRenames: false', $js);
        self::assertStringContainsString('isWordPressProtectedMedia', $js);
    }

    public function test_except_ui_removed_from_images_tab(): void
    {
        $tab = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/components/ArticleImagesTab.jsx',
        );
        self::assertStringNotContainsString("t('except')", $tab);
        self::assertStringNotContainsString('excludeQuickFix: !excluded', $tab);
        self::assertStringContainsString('wp_media_bulk_protected', $tab);
        self::assertStringContainsString('wp_media_rename_menu', $tab);

        $i18n = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/i18n.js',
        );
        // dead Except action copy may remain as unused key briefly; button must be gone.
        self::assertStringNotContainsString('image_except_enable_hint', $tab);
    }

    public function test_explicit_rename_requires_strong_confirmation(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(WordPressMediaRenameService::class))->getFileName(),
        );
        self::assertStringContainsString("CONFIRMATION_PHRASE = 'RENAME'", $source);
        self::assertStringContainsString('acknowledge_url_change', $source);
        self::assertStringContainsString('confirmation_required', $source);
        self::assertStringContainsString('usage_scan_incomplete', $source);
        self::assertStringContainsString('partial_failure', $source);
        self::assertStringContainsString('wordpress_media_rename_audit', $source);

        $controller = (string) file_get_contents(
            (new ReflectionClass(WordPressMediaRenameController::class))->getFileName(),
        );
        self::assertStringContainsString('acknowledge_url_change', $controller);
        self::assertStringContainsString('confirmation_phrase', $controller);
    }

    public function test_media_library_and_editor_share_rename_service(): void
    {
        $library = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/media-library.blade.php'),
        );
        self::assertStringContainsString('seo-wordpress-media-rename-open', $library);
        self::assertStringContainsString('Äá»•i tÃªn áº£nh', $library);

        $modal = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/resources/js/components/WordPressMediaRenameModal.jsx',
        );
        self::assertStringContainsString('/api/seo/media/wordpress/rename', $modal);
        self::assertStringContainsString("confirmation_phrase: 'RENAME'", $modal);

        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString('WordPressMediaRenameModal', $editor);
        $mediaPage = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/media-library-page.jsx',
        );
        self::assertStringContainsString('WordPressMediaRenameModal', $mediaPage);
    }

    public function test_images_health_separates_integrity_warning_info(): void
    {
        $health = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/resources/js/utils/assistantWidgetHealth.js',
        );
        self::assertStringContainsString('error_count', $health);
        self::assertStringContainsString('warning_count', $health);
        self::assertStringContainsString('info_count', $health);
        self::assertStringContainsString('image_slug_unresolved', $health);
        self::assertStringContainsString('image_ratio_low', $health);
        self::assertStringContainsString("severity: 'info'", $health);
        self::assertStringContainsString('isWordPressProtectedMedia', $health);
        self::assertStringContainsString('WP filename â‰  keyword is NOT a hard error', $health);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
