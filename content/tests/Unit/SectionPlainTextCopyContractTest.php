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
        self::assertStringContainsString('writeTextToClipboard', $src);
        self::assertStringContainsString('copySectionText', $src);
        self::assertStringContainsString('editor_copy_section', $src);
        self::assertStringContainsString('extractSectionPlainText(section, blockById)', $src);
    }

    public function test_helper_keeps_list_and_heading_structure_hints(): void
    {
        $src = $this->helperSource();
        self::assertStringContainsString("tag === 'li'", $src);
        self::assertStringContainsString('/^h[1-6]$/', $src);
        self::assertStringContainsString("join('\\n\\n')", $src);
    }
}
