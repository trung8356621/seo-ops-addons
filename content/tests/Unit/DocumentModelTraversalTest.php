<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 â€” DocumentModel walk / memoization contracts.
 */
final class DocumentModelTraversalTest extends TestCase
{
    private function js(string $relative): string
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/utils/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_document_model_exposes_walk_and_memoized_selectors(): void
    {
        $source = $this->js('documentModel.js');
        self::assertStringContainsString('export function walk', $source);
        self::assertStringContainsString('export function createDocumentModel', $source);
        self::assertStringContainsString('wordCount', $source);
        self::assertStringContainsString('plainTextEligible', $source);
        self::assertStringContainsString("type === 'articleImage'", $source);
        self::assertStringContainsString('countWordsInPlainText', $source);
        self::assertStringContainsString('sliceFirstWordsFromModel', $source);
        self::assertStringContainsString('let cache = null', $source);
    }

    public function test_html_compat_is_single_domparser_ingest(): void
    {
        $source = $this->js('htmlDocumentCompat.js');
        self::assertStringContainsString('htmlToDocumentJson', $source);
        self::assertStringContainsString('blocksToDocumentJson', $source);
        self::assertStringContainsString('DOMParser', $source);
        self::assertStringContainsString('compatibility adapter', $source);
    }

    public function test_editor_document_bridge_prefers_tiptap_getjson(): void
    {
        $source = $this->js('editorDocumentBridge.js');
        self::assertStringContainsString('getJSON', $source);
        self::assertStringContainsString('documentJsonFromEditorsOrBlocks', $source);
        self::assertStringContainsString("source: usedLiveJson ? 'tiptap_json'", $source);
    }
}
