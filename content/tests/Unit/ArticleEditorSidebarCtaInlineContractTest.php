<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * 3G — sidebar keeps editor bookmark; CTA primary = value, dropdown = sentence.
 */
final class ArticleEditorSidebarCtaInlineContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_sidebar_click_does_not_clear_active_editor_context(): void
    {
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');
        $ctx = $this->readAddon('resources/js/utils/editorInsertionContext.js');

        self::assertStringContainsString('isAssistantFocusStealTarget(e.target)', $editor);
        self::assertStringContainsString('never clear active editor context on click', $editor);
        self::assertStringContainsString('preserveEditorContextBeforeSidebarAction', $ctx);
        self::assertStringContainsString('Do not overwrite', $ctx);
        self::assertStringContainsString('looksLikeDocEnd', $ctx);
        self::assertStringContainsString('isAssistantFocusStealTarget(related)', $editor);
    }

    public function test_contact_ui_exposes_value_and_sentence_insert(): void
    {
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');
        $links = $this->readAddon('resources/js/components/ArticleLinksSidebar.jsx');
        $domain = $this->readAddon('resources/js/components/ArticleDomainWidgetsSidebar.jsx');

        self::assertStringContainsString('cta_widget_insert_sentence_tooltip', $cta);
        self::assertStringContainsString('cta_widget_insert_${type === \'hotline\' ? \'phone\' : type}_tooltip', $cta);
        self::assertStringContainsString('onInsertQuickCta', $cta);
        self::assertStringNotContainsString('onInsertValue', $cta);
        self::assertStringNotContainsString('onInsertValue=', $links);
        self::assertStringNotContainsString('onInsertValue=', $domain);
        self::assertStringContainsString("effectiveMode = mode === 'value' ? 'value' : 'sentence'", $cta);
        self::assertStringContainsString("data-cta-action=\"insert_contact_value\"", $cta);
        self::assertStringContainsString("data-cta-action=\"insert_contact_cta\"", $cta);
        self::assertStringContainsString("onInsertQuickCta(item, itemKey, null, 'value')", $cta);
        self::assertStringContainsString("onInsertQuickCta(item, itemKey, null, 'sentence')", $cta);
    }

    public function test_canonical_cta_command_is_insert_contact_cta_at_bookmark(): void
    {
        $selection = $this->readAddon('resources/js/utils/editorSelectionUtils.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');
        $registry = $this->readAddon('resources/js/utils/editorCommands/editorCommandRegistry.js');

        self::assertStringContainsString('insertContactCtaAtBookmark', $selection);
        self::assertStringContainsString("class: 'article-cta'", $selection);
        self::assertStringContainsString('article-cta__value', $selection);
        self::assertStringContainsString('resolveInsertionAfterEnclosingBlock', $selection);
        self::assertStringNotContainsString('commands.lift()', $selection);
        self::assertStringNotContainsString('setTextSelection(docSize)', $selection);

        // Host routes via command layer (not direct util import in SeoArticleEditor).
        self::assertStringContainsString("isCtaSentence ? 'insert_contact_cta' : 'insert_contact_value'", $editor);
        self::assertStringContainsString('wrapPlainTextWithLinkInBlocks', $editor);
        self::assertStringContainsString('Contact-value insert mirrors internal-link wrap', $editor);
        self::assertStringContainsString("mut('insert_contact_cta'", $registry);
        self::assertStringContainsString("mut('insert_contact_value'", $registry);
    }
}
