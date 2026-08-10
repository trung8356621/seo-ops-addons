<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

final class ArticleEditorCtaMediaQuoteFixContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_cta_freezes_insertion_on_pointerdown(): void
    {
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');
        $ctx = $this->readAddon('resources/js/utils/editorInsertionContext.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('captureCtaInsertionBeforeFocusSteal', $cta);
        self::assertStringContainsString('preserveEditorContextBeforeSidebarAction', $cta);
        self::assertStringContainsString('onPointerDown={captureCtaInsertionBeforeFocusSteal}', $cta);
        self::assertStringContainsString('getInsertionContextForCommand', $cta);
        self::assertStringContainsString('preserveEditorContextBeforeSidebarAction', $ctx);
        self::assertStringContainsString('seo-assistant-freeze-insertion-context', $ctx);
        self::assertStringContainsString('freezeEditorInsertionContext', $ctx);
        self::assertStringContainsString('clearFrozenEditorInsertionContext', $ctx);
        self::assertStringContainsString('syncAndFreezeInsertionContext', $ctx);
        self::assertStringContainsString('Do not overwrite', $ctx);
        self::assertStringContainsString('editor.isFocused', $ctx);
        self::assertStringContainsString('Do NOT re-sync from live editors after sidebar stole focus', $editor);
        self::assertStringContainsString('clearFrozenEditorInsertionContext', $editor);
    }

    public function test_cta_insert_uses_restored_selection_then_insertContent(): void
    {
        $selection = $this->readAddon('resources/js/utils/editorSelectionUtils.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('insertContactCtaAtBookmark', $selection);
        self::assertStringContainsString('insertContactCtaAtBookmark', $editor);
        self::assertStringContainsString("class: 'article-cta'", $selection);
        self::assertStringContainsString('NEVER force doc end', $selection);
        self::assertStringContainsString('editor_cta_block_inserted', $editor);
    }

    public function test_raw_insert_uses_contact_value_bookmark_helper(): void
    {
        $selection = $this->readAddon('resources/js/utils/editorSelectionUtils.js');

        self::assertStringContainsString('export function insertContactValueAtBookmark', $selection);
        self::assertStringContainsString('export function insertContactCtaAtBookmark', $selection);
        self::assertStringNotContainsString('export function insertCtaInEditor', $selection);
        self::assertStringNotContainsString('export function insertCtaBlockAtBookmark', $selection);
        self::assertStringNotContainsString('export function insertLinkReplacingEditorSelection', $selection);
    }

    public function test_content_image_counter_scans_inline_html_not_only_image_blocks(): void
    {
        $counter = $this->readAddon('resources/js/utils/contentImageCounter.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('collectContentImagesFromArticle', $counter);
        self::assertStringContainsString("type === 'image'", $counter);
        self::assertStringContainsString('inline-html', $counter);
        self::assertStringContainsString('collectContentImagesFromArticle(blocks)', $editor);
        self::assertStringContainsString('Never featured/gallery/supplemental library', $editor);
    }

    public function test_orphan_quote_normalizer_moves_outside_block_quotes_only(): void
    {
        $body = $this->readAddon('resources/js/utils/orphanQuoteNormalizer.js');

        self::assertStringContainsString('normalizeOrphanQuoteCharacters', $body);
        self::assertStringContainsString('Does NOT strip user quotes inside editable text', $body);
        self::assertStringContainsString('normalizeOrphanQuoteCharacters', $this->readAddon('resources/js/components/SeoArticleEditor.jsx'));
    }
}
