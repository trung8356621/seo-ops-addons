<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Domain safety: quick internal-link search is always scoped to the current site.
 */
final class ArticleEditorOutlineStructureUxTest extends TestCase
{
    public function test_search_returns_empty_when_site_id_missing(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkSearchService::class))->getFileName()
        );

        self::assertStringContainsString('if ($siteId <= 0)', $source);
        self::assertStringContainsString("->where('site_id', \$siteId)", $source);
        self::assertStringContainsString('->whereKey($excludeArticleId)', $source);
    }

    public function test_fallback_query_never_omits_site_id(): void
    {
        $ref = new ReflectionClass(ArticleInternalLinkSearchService::class);
        $method = $ref->getMethod('search');
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));
        $body = implode("\n", array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertGreaterThanOrEqual(2, substr_count($body, "site_id"));
        self::assertStringNotContainsString('SeoArticle::query()->where(\'id\'', $body);
    }

    public function test_context_menu_reuses_cached_same_site_search(): void
    {
        $menu = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/EditorContextMenu.jsx',
        );
        $helper = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/internalLinkArticleSearch.js',
        );

        self::assertStringContainsString('searchInternalLinkArticlesCached', $menu);
        self::assertStringContainsString('siteId', $menu);
        self::assertStringContainsString("callEditArticleLivewire('searchInternalLinkArticles'", $helper);
        self::assertStringContainsString('id === articleId', $helper);
    }

    public function test_heading_structure_commands_are_registered(): void
    {
        $registry = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorCommands/editorCommandRegistry.js',
        );

        self::assertStringContainsString("mut('split_selection_to_heading'", $registry);
        self::assertStringContainsString("mut('split_paragraph_at_cursor'", $registry);
        self::assertStringContainsString("mut('rename_heading'", $registry);
        self::assertStringContainsString("mut('change_heading_level'", $registry);
        self::assertStringContainsString("mut('delete_heading_keep_content'", $registry);
        self::assertStringContainsString("mut('insert_heading_after'", $registry);

        $splitCommands = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorCommands/headingStructureCommands.js',
        );
        self::assertStringContainsString('planCanonicalArticleBlockSplit', $splitCommands);
        self::assertStringContainsString("runHostStructure(context, 'replace_blocks_at'", $splitCommands);
        self::assertStringNotContainsString('splitSelectionToBlockType', $splitCommands);

        $host = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorInsertAndSections.js',
        );
        self::assertStringContainsString("name === 'replace_blocks_at'", $host);
    }

    public function test_heading_only_blocks_use_locked_preview(): void
    {
        $blockEditor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/BlockEditor.jsx',
        );

        self::assertStringContainsString('OutlineLockedHeadingBlock', $blockEditor);
        self::assertStringContainsString('isCanonicalLockedHeadingHtml', $blockEditor);
        self::assertStringContainsString('focusHeadingIndex', $blockEditor);
    }

    public function test_active_block_insert_bars_remain_in_editor_shell(): void
    {
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertStringContainsString('BlockInsertBar', $editor);
        self::assertStringContainsString('BlockInsertMenuBar', $editor);
        self::assertStringContainsString('position="before"', $editor);
        self::assertStringContainsString('position="after"', $editor);
        self::assertStringContainsString('EditorContextMenu', (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ActiveBlockEditor.jsx',
        ));
    }

    public function test_context_menu_captures_pm_coords_and_runs_commands_once(): void
    {
        $active = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ActiveBlockEditor.jsx',
        );
        $menu = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/EditorContextMenu.jsx',
        );
        $controller = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorContextMenuController.js',
        );

        self::assertStringContainsString('captureEditorContextMenuSnapshot', $active);
        self::assertStringContainsString('posAtCoords', $controller);
        self::assertStringContainsString('applyContextMenuSelection', $menu);
        self::assertStringContainsString('ranRef.current', $menu);
        self::assertStringContainsString("onMouseDown={(event) => {", $menu);
        self::assertStringContainsString('event.preventDefault()', $menu);
        self::assertStringContainsString('Heading3', $menu);
        self::assertStringContainsString('is-danger', $menu);
        self::assertStringContainsString("name: 'split_selection_to_heading'", $controller);
        self::assertStringNotContainsString('setClientOutline', $controller);
        self::assertStringNotContainsString("addEventListener('scroll'", $menu);
        self::assertStringContainsString('canSplitParagraph', $controller);
        self::assertStringContainsString('disabled={!snapshot.canSplitParagraph}', $menu);
        self::assertStringContainsString('applyReplaceBlocksAt', (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorInsertAndSections.js',
        ));
        self::assertStringContainsString('skipCommit', (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorCommands/runEditorTransaction.js',
        ));
        self::assertStringContainsString('HOST_COMMAND_MISSING', (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorCommands/editorCommandResult.js',
        ));
        self::assertStringContainsString('document_changed !== true', $menu);
    }

    public function test_outline_context_menu_convert_submenu_and_close_behavior(): void
    {
        $outline = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleOutlineTab.jsx',
        );
        $mutations = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorOutlineMutations.js',
        );
        $registry = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorCommands/editorCommandRegistry.js',
        );
        $hook = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorOutline.js',
        );

        self::assertStringContainsString("event.key === 'Escape'", $outline);
        self::assertStringContainsString("document.addEventListener('mousedown'", $outline);
        self::assertStringContainsString('OutlineMenuProvider', $outline);
        self::assertStringContainsString('setOpenKey(menuOpen ? null : moreKey)', $outline);
        self::assertStringNotContainsString("t('outline_ai_gen')", $outline);
        self::assertStringNotContainsString('outline_delete_keep_content', $outline);
        self::assertStringContainsString("t('outline_convert_to')", $outline);
        self::assertStringContainsString("t('outline_hide_from_outline')", $outline);
        self::assertStringContainsString("t('outline_delete_with_content')", $outline);
        self::assertStringContainsString('is-danger', $outline);
        self::assertStringContainsString("choose('h2')", $outline);
        self::assertStringContainsString("choose('paragraph')", $outline);
        self::assertStringContainsString("choose('bold')", $outline);
        self::assertStringContainsString("choose('italic')", $outline);
        self::assertStringContainsString('convertHeadingInHtml', $mutations);
        self::assertStringContainsString('<p><strong>', $mutations);
        self::assertStringContainsString('<p><em>', $mutations);
        self::assertStringContainsString("mut('convert_heading'", $registry);
        self::assertStringContainsString('convertOutlineHeading', $hook);
        self::assertStringContainsString("data-outline-visible", $mutations);
        self::assertStringContainsString('deleteHeadingWithContentInHtml', $mutations);
    }
}
