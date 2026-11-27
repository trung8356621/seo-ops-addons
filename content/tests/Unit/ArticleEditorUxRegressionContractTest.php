<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Regression contracts for Outline collapse-all, init-collapsed, approval colors.
 */
final class ArticleEditorUxRegressionContractTest extends TestCase
{
    private function read(string $relative): string
    {
        return (string) file_get_contents(
            ProjectRoot::addonsPath().'/'.ltrim(str_replace('\\', '/', $relative), '/'),
        );
    }

    public function test_collapse_all_is_visible_in_outline_header(): void
    {
        $outline = $this->read('content/resources/js/components/ArticleOutlineTab.jsx');
        $editor = $this->read('content/resources/js/components/SeoArticleEditor.jsx');
        $css = $this->read('content/resources/css/article-editor.css');
        $i18n = $this->read('content/resources/js/utils/i18n.js');

        self::assertStringContainsString('onCollapseAllSections={collapseAllSections}', $editor);
        self::assertStringContainsString('seo-outline-collapse-all', $outline);
        self::assertStringContainsString('seo-outline-toolbar__title-actions', $outline);
        self::assertStringContainsString('ListCollapse', $outline);
        self::assertStringNotContainsString('onClick={collapseAllSections}', $editor);
        self::assertStringContainsString('display: inline-flex !important', $css);
        self::assertStringContainsString("editor_collapse_all_sections: 'Thu gọn tất cả'", $i18n);
    }

    public function test_fresh_load_collapses_all_sections_without_restore(): void
    {
        $hook = $this->read('content/resources/js/hooks/useArticleEditorOutline.js');
        $editor = $this->read('content/resources/js/components/SeoArticleEditor.jsx');
        $activation = $this->read('content/resources/js/hooks/useArticleEditorBlockActivation.js');

        self::assertStringContainsString('markInitialDone: true', $hook);
        self::assertStringContainsString('initialCollapseDoneRef', $hook);
        self::assertStringContainsString('seedActiveBlockIdRef', $hook);
        self::assertStringContainsString('useLayoutEffect', $hook);
        self::assertStringContainsString('readExplicitlyExpandedIds', $hook);
        self::assertStringContainsString('collapsedSectionIdsRef.current[section.id] === false', $hook);
        self::assertStringContainsString('Fresh mount / F5', $hook);
        self::assertStringContainsString('collapsedSectionIds[section.id] !== false', $editor);
        self::assertStringContainsString('sectionExpandAllowedRef', $activation);
        self::assertStringContainsString('waitAndScrollToHeading', $hook);
        // Init path must not restore via !== true (missing key treated as expanded).
        self::assertStringNotContainsString(
            'collapsedSectionIdsRef.current[section.id] !== true',
            $hook,
        );
    }

    public function test_resize_restores_explicitly_expanded_sections_only(): void
    {
        $hook = $this->read('content/resources/js/hooks/useArticleEditorOutline.js');

        self::assertStringContainsString("window.addEventListener('resize'", $hook);
        self::assertStringContainsString('restoreExpandedIds: expandedIds', $hook);
        self::assertStringContainsString('!initialCollapseDoneRef.current', $hook);
        self::assertStringContainsString('readExplicitlyExpandedIds()', $hook);
    }

    public function test_active_block_seed_does_not_expand_after_initial_collapse(): void
    {
        $hook = $this->read('content/resources/js/hooks/useArticleEditorOutline.js');

        self::assertStringContainsString('seedActiveBlockIdRef.current = activeBlockIdRef.current', $hook);
        self::assertStringContainsString('activeBlockId === seedActiveBlockIdRef.current', $hook);
        self::assertStringContainsString('!initialCollapseDoneRef.current', $hook);
    }

    public function test_pending_and_approved_status_use_distinct_chip_colors(): void
    {
        $css = $this->read('content/resources/css/article-edit-page.css');
        $actions = $this->read(
            'seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
        );

        self::assertStringContainsString("data-status='pending_review'", $css);
        self::assertStringContainsString("data-status='approved'", $css);
        self::assertStringContainsString('#fff7ed', $css);
        self::assertStringContainsString('#9a3412', $css);
        self::assertStringContainsString('#f0fdf4', $css);
        self::assertStringContainsString('#166534', $css);
        self::assertStringContainsString('seo-editor-review-status-split', $actions);
        self::assertStringContainsString('x-bind:data-status="reviewStatus"', $actions);
        self::assertStringContainsString('reviewHasActions()', $actions);
    }

    public function test_outline_jump_opens_only_target_section(): void
    {
        $hook = $this->read('content/resources/js/hooks/useArticleEditorOutline.js');

        self::assertStringContainsString('setCollapsedSectionIds(buildAllCollapsedMap())', $hook);
        self::assertStringContainsString('collapseSectionsExcept(sectionId)', $hook);
        self::assertStringContainsString('scrollEditorTargetIntoViewAsync', $hook);
        self::assertStringContainsString('waitAndScrollToHeading', $hook);
        self::assertStringContainsString('jumpGenerationRef', $hook);
        self::assertStringNotContainsString('scrollToCollapsedSectionBaseline(', $hook);
    }
}
