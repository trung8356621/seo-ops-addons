<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SectionPlainTextCopyContractTest extends TestCase
{
    private function helperSource(): string
    {
        $path = dirname(__DIR__, 2).'/resources/js/utils/sectionPlainTextCopy.js';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function editorSource(): string
    {
        $path = dirname(__DIR__, 2).'/resources/js/components/SeoArticleEditor.jsx';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_helper_exports_and_skips_media_ui_metadata(): void
    {
        $src = $this->helperSource();
        self::assertStringContainsString('export function extractSectionPlainText', $src);
        self::assertStringContainsString('export function extractSelectedBlocksPlainText', $src);
        self::assertStringContainsString('export function htmlToSectionPlainText', $src);
        self::assertStringContainsString('export async function writeTextToClipboard', $src);
        self::assertStringContainsString("tag === 'img'", $src);
        self::assertStringContainsString('figcaption', $src);
        self::assertStringNotContainsString('Section 5', $src);
        self::assertStringNotContainsString('Quản lý trong Outline', $src);
    }

    public function test_editor_wires_copy_from_current_block_state(): void
    {
        $src = $this->editorSource();
        self::assertStringContainsString('extractSectionPlainText', $src);
        self::assertStringContainsString('extractSelectedBlocksPlainText', $src);
        self::assertStringContainsString('writeTextToClipboard', $src);
        self::assertStringContainsString('copySectionText', $src);
        self::assertStringContainsString('copySelectedBlocksText', $src);
        self::assertStringContainsString('editor_copy_section', $src);
        self::assertStringContainsString('editor_copy_all', $src);
        self::assertStringContainsString('extractSectionPlainText(section, blockById)', $src);
        self::assertStringContainsString('extractSelectedBlocksPlainText', $src);
        self::assertStringContainsString('tempMergeRef.current?.rangeIds', $src);
    }

    public function test_copy_all_preserves_temp_merge_and_avoids_dom_select_all(): void
    {
        $src = $this->editorSource();
        self::assertStringContainsString('copySelectedBlocksText', $src);
        self::assertStringContainsString('onCopyAllArticle={copySelectedBlocksText}', $src);
        self::assertStringContainsString('Clipboard-only', $src);
        self::assertStringNotContainsString('selectAll()', $src);
        // Handler must not clear multi-select / merge before copying.
        self::assertDoesNotMatchRegularExpression(
            '/copySelectedBlocksText[\s\S]{0,400}clearTempMerge\s*\(/',
            $src,
        );

        $blockEditor = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/BlockEditor.jsx',
        );
        self::assertStringContainsString('onCopyAllArticle', $blockEditor);
        self::assertStringContainsString('editor_copy_all', $blockEditor);
        self::assertStringContainsString('editor_merge_paragraphs', $blockEditor);
        self::assertStringContainsString('seo-temp-merge-banner', $blockEditor);
        self::assertStringContainsString('onCopy=', $blockEditor);
        self::assertStringContainsString('seo-temp-merge-content', $blockEditor);
        self::assertStringContainsString('select-none', $blockEditor);

        $css = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/css/article-editor.css',
        );
        self::assertMatchesRegularExpression(
            '/\.seo-temp-merge-banner\s*\{[\s\S]*?user-select:\s*none/',
            $css,
        );

        $helper = $this->helperSource();
        self::assertStringContainsString('export function extractSelectedBlocksPlainText', $helper);
        self::assertStringContainsString('resolveBlockHtmlForCopy', $helper);
    }

    public function test_helper_keeps_list_and_heading_structure_hints(): void
    {
        $src = $this->helperSource();
        self::assertStringContainsString("tag === 'li'", $src);
        self::assertStringContainsString('/^h[1-6]$/', $src);
        self::assertStringContainsString("join('\\n\\n')", $src);
    }

    public function test_helper_strips_cta_shortcode_from_clipboard_only(): void
    {
        $src = $this->helperSource();
        self::assertStringContainsString('stripCtaFromCopyRoot', $src);
        self::assertStringContainsString('seo-managed-cta', $src);
        self::assertStringContainsString('seo_ops_cta', $src);
        self::assertStringContainsString('Clipboard-only', $src);
    }
}
