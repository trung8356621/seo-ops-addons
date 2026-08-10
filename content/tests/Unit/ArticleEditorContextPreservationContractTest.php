<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Source-contract tests for article editor context preservation (no browser runtime).
 */
final class ArticleEditorContextPreservationContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function read(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_focus_image_block_does_not_collapse_other_sections(): void
    {
        $body = $this->read('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('const focusImageBlock = useCallback(', $body);
        self::assertStringContainsString('do NOT collapse other sections', $body);
        self::assertStringContainsString('scrollElementIntoViewIfNeeded', $body);
        // focusImageBlock must expand target only — collapseSectionsExcept is reserved for outline/link jumps.
        self::assertDoesNotMatchRegularExpression(
            '/const focusImageBlock = useCallback\([\s\S]*?collapseSectionsExcept\(targetSectionId\)[\s\S]*?\],\s*\[[^\]]*sectionByBlockId[^\]]*\]\s*\)/',
            $body,
        );
    }

    public function test_collapsed_sections_initialized_once(): void
    {
        $body = $this->read('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('collapsedSectionsInitializedRef', $body);
        self::assertStringContainsString('key={section.id}', $body);
        self::assertStringContainsString('key={block.id}', $body);
    }

    public function test_editor_insertion_context_module_exists(): void
    {
        $body = $this->read('resources/js/utils/editorInsertionContext.js');

        self::assertStringContainsString('export function captureEditorInsertionContext', $body);
        self::assertStringContainsString('export function resolveEditorForInsertion', $body);
        self::assertStringContainsString('export function resolveBookmarkSelection', $body);
        self::assertStringContainsString('Never silently pick', $body);
    }

    public function test_cta_insert_uses_bookmark_and_not_first_block_fallback(): void
    {
        $body = $this->read('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('resolveEditorForInsertion', $body);
        self::assertStringContainsString('selectionBookmark', $body);
        self::assertStringContainsString('never silently insert into first section', $body);
        self::assertStringNotContainsString(
            'currentBlocks.find((block) => block.type !== \'image\' && block.content)',
            $body,
        );
    }

    public function test_active_block_editor_captures_selection_context(): void
    {
        $body = $this->read('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('onSelectionUpdate:', $body);
        self::assertStringContainsString('captureEditorInsertionContext', $body);
        self::assertStringContainsString('sectionId={section.id}', $body);
    }

    public function test_wordpress_media_capability_is_site_level(): void
    {
        $controller = $this->read('Http/Controllers/ArticleMediaPickerController.php');
        $lazy = $this->read('Http/Controllers/ArticleEditorLazyPayloadController.php');
        $resolver = $this->read('Services/WordPressMediaCapabilityResolver.php');

        self::assertStringContainsString('WordPressMediaCapabilityResolver', $controller);
        self::assertStringNotContainsString('wp_post_id ?? 0) <= 0', $controller);
        self::assertStringContainsString('wordpress_media_available', $lazy);
        self::assertStringContainsString('seo_read_token', $resolver);
        self::assertStringContainsString('getPermalinkBase', $resolver);
    }

    public function test_media_picker_ui_uses_site_capability_reason(): void
    {
        $blade = $this->read('resources/views/filament/resources/article-resource/pages/edit-article.blade.php');

        self::assertStringContainsString('pickerWordPressUnavailableReason', $blade);
        self::assertStringContainsString('wordpress_media_available', $blade);
        self::assertStringNotContainsString(
            'Đồng bộ bài viết với WordPress để sử dụng thư viện này',
            $blade,
        );
    }

    public function test_cta_sidebar_uses_insert_icons_and_filters_usable(): void
    {
        $links = $this->read('resources/js/components/ArticleLinksSidebar.jsx');
        $shared = $this->read('resources/js/components/CtaContactInsertList.jsx');
        $domainService = $this->read('Services/DomainCtaEditorService.php');

        self::assertStringContainsString('CtaContactInsertList', $links);
        self::assertStringContainsString('filterUsableCtaContacts', $links);
        self::assertStringContainsString('wp-article-links-insert-btn--contact', $shared);
        self::assertStringContainsString('wp-article-links-insert-btn--sentence', $shared);
        self::assertStringContainsString('filterUsableCtaContacts', $shared);
        self::assertStringContainsString('CtaContactUsability::isUnresolvedPlaceholder', $domainService);
        self::assertStringNotContainsString("'is_blank' => true", $domainService);
    }

    public function test_multiple_editor_instance_resolution_prefers_active_block(): void
    {
        $body = $this->read('resources/js/utils/editorInsertionContext.js');

        self::assertStringContainsString('preferredId', $body);
        self::assertStringContainsString('blockEditors.get(preferredId)', $body);
        self::assertStringContainsString('Never silently pick "first" map entry', $body);
        self::assertStringContainsString('!preferredId', $body);
    }
}
