<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Outline navigation baseline + Collapse All placement contracts.
 */
final class ArticleEditorOutlineNavBaselineContractTest extends TestCase
{
    private function read(string $relative): string
    {
        return (string) file_get_contents(
            ProjectRoot::addonsPath().'/'.ltrim(str_replace('\\', '/', $relative), '/'),
        );
    }

    public function test_collapse_all_lives_in_outline_toolbar_not_search_replace(): void
    {
        $editor = $this->read('content/resources/js/components/SeoArticleEditor.jsx');
        $outline = $this->read('content/resources/js/components/ArticleOutlineTab.jsx');
        $css = $this->read('content/resources/css/article-editor.css');

        self::assertStringNotContainsString('onClick={collapseAllSections}', $editor);
        self::assertStringContainsString('onCollapseAllSections={collapseAllSections}', $editor);
        self::assertStringContainsString('onCollapseAllSections', $outline);
        self::assertStringContainsString('seo-outline-collapse-all', $outline);
        self::assertStringContainsString('seo-outline-toolbar__title-actions', $outline);
        self::assertStringContainsString('ListCollapse', $outline);
        self::assertStringContainsString('.seo-article-editor-outline-rail .seo-outline-toolbar', $css);
        self::assertStringContainsString('position: sticky', $css);
    }

    public function test_outline_nav_locks_until_collapsed_baseline_ready(): void
    {
        $hook = $this->read('content/resources/js/hooks/useArticleEditorOutline.js');
        $baseline = $this->read('content/resources/js/utils/outlineSectionBaseline.js');

        self::assertStringContainsString('outlineNavReady', $hook);
        self::assertStringContainsString('rebuildCollapsedBaseline', $hook);
        self::assertStringContainsString("window.addEventListener('resize'", $hook);
        self::assertStringContainsString('restoreExpandedIds', $hook);
        self::assertStringContainsString('flushSync', $hook);
        self::assertStringContainsString('buildAllCollapsedMap', $hook);
        self::assertStringContainsString('buildCollapsedBaselineMap', $baseline);
        self::assertStringContainsString('waitForNextPaint', $baseline);
        self::assertStringContainsString('requestAnimationFrame', $baseline);
        self::assertStringNotContainsString('setTimeout(resolve', $baseline);
    }

    public function test_sticky_header_height_drives_scroll_offset(): void
    {
        $nav = $this->read('content/resources/js/utils/ctaSectionNavigator.js');
        $pageCss = $this->read('content/resources/css/article-edit-page.css');

        self::assertStringContainsString('data-seo-sticky-editor-header', $nav);
        self::assertStringContainsString('measureEditorStickyHeaderHeight', $nav);
        self::assertStringContainsString('editorScrollOffset', $nav);
        self::assertStringNotContainsString('const SCROLL_OFFSET = 88', $nav);
        self::assertStringContainsString('padding-top: 0 !important', $pageCss);
        self::assertStringContainsString('[data-article-editor-page] section.py-8', $pageCss);
        self::assertStringContainsString('body.article-editor-page .fi-page section.py-8', $pageCss);
        self::assertStringContainsString('.seo-article-editor-sticky-header', $pageCss);
        self::assertStringContainsString('top: 0', $pageCss);
        self::assertStringContainsString('computeEditorScrollDestination', $nav);
    }
}
