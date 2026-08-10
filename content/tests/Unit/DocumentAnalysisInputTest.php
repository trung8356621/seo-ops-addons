<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 â€” analyzer / editor consume DocumentModel, not HTML-only.
 */
final class DocumentAnalysisInputTest extends TestCase
{
    public function test_analyzer_resolves_document_model_not_html_only(): void
    {
        $analyzer = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/utils/seoAnalyzer.js',
        );
        self::assertStringContainsString('resolveAnalysisDocumentModel', $analyzer);
        self::assertStringContainsString('extractLinksFromDocument', $analyzer);
        self::assertStringContainsString('documentModel', $analyzer);
        self::assertStringContainsString("document_owner: 'tiptap_json'", $analyzer);
        self::assertStringContainsString('sliceFirstWordsFromModel', $analyzer);
        self::assertStringContainsString('selectH2', $analyzer);
        self::assertStringContainsString('selectFaqPlaceholders', $analyzer);
        self::assertStringContainsString('selectTables', $analyzer);
    }

    public function test_compose_and_editor_pass_document_or_blocks(): void
    {
        $compose = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/utils/composeArticleAnalysis.js',
        );
        self::assertStringContainsString('document: input.document', $compose);
        self::assertStringContainsString('blocks: input.blocks', $compose);

        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('documentJsonFromEditorsOrBlocks', $editor);
        self::assertStringContainsString('document: documentJsonFromEditorsOrBlocks', $editor);
    }

    public function test_document_model_architecture_doc_exists(): void
    {
        $path = ProjectRoot::path().'/docs/architecture/ARTICLE_EDITOR_DOCUMENT_MODEL.md';
        self::assertFileExists($path);
        $body = (string) file_get_contents($path);
        self::assertStringContainsString('TipTap JSON', $body);
        self::assertStringContainsString('DocumentModel', $body);
        self::assertStringContainsString('htmlDocumentCompat', $body);
    }
}
