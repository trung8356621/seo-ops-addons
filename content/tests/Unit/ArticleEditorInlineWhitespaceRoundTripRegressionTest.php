<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentHtmlIngest;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentHtmlRenderer;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentNodeRegistry;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentSchema;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter;
use Omnichannel\Addons\Content\Services\ArticleEditorHtmlSanitizeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Inline mark boundary whitespace must survive HTML â†” TipTap JSON round-trips.
 */
final class ArticleEditorInlineWhitespaceRoundTripRegressionTest extends TestCase
{
    private ArticleEditorDocumentHtmlIngest $ingest;

    private ArticleEditorDocumentSchema $schema;

    private ArticleEditorHtmlSanitizeService $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingest = new ArticleEditorDocumentHtmlIngest;
        $renderer = new ArticleEditorDocumentHtmlRenderer;
        $this->schema = new ArticleEditorDocumentSchema(
            new ArticleEditorDocumentNodeRegistry,
            $renderer,
        );
        $this->sanitizer = new ArticleEditorHtmlSanitizeService;
    }

    /**
     * @return list<string>
     */
    private function textNodes(array $node): array
    {
        $out = [];
        if (isset($node['text']) && is_string($node['text'])) {
            $out[] = $node['text'];
        }
        foreach (($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $out = array_merge($out, $this->textNodes($child));
            }
        }

        return $out;
    }

    private function plainFromDoc(array $doc): string
    {
        return implode('', $this->textNodes($doc));
    }

    private function roundTrip(string $html): array
    {
        $doc1 = $this->ingest->htmlToTipTapDoc($html);
        $env = [
            'schema_version' => 1,
            'type' => 'article_document',
            'blocks' => [['id' => 'b1', 'type' => 'text', 'document' => $doc1]],
        ];
        $rendered = $this->schema->renderHtml($env);
        $cleaned = $this->sanitizer->stripTransientEditorMarkup($rendered);
        $doc2 = $this->ingest->htmlToTipTapDoc($cleaned);

        return [$doc1, $cleaned, $doc2];
    }

    public function test_strong_keeps_spaces_both_sides(): void
    {
        $html = '<p>vÃ¬ <strong>Mix &amp; Match tÃºi váº£i khÃ´ng dá»‡t</strong> Ä‘ang trá»Ÿ thÃ nh</p>';
        [$doc1, $cleaned, $doc2] = $this->roundTrip($html);
        self::assertSame('vÃ¬ Mix & Match tÃºi váº£i khÃ´ng dá»‡t Ä‘ang trá»Ÿ thÃ nh', $this->plainFromDoc($doc1));
        self::assertSame($this->plainFromDoc($doc1), $this->plainFromDoc($doc2));
        self::assertStringContainsString('vÃ¬ <strong>', $cleaned);
        self::assertStringContainsString('</strong> Ä‘ang', $cleaned);
        $nodes = $this->textNodes($doc1);
        self::assertContains('vÃ¬ ', $nodes);
        self::assertContains(' Ä‘ang trá»Ÿ thÃ nh', $nodes);
    }

    public function test_em_and_link_keep_spaces(): void
    {
        $cases = [
            '<p>alpha <em>beta</em> gamma</p>',
            '<p>alpha <a href="https://example.com/x">beta</a> gamma</p>',
        ];
        foreach ($cases as $html) {
            [$doc1, $cleaned, $doc2] = $this->roundTrip($html);
            self::assertSame('alpha beta gamma', $this->plainFromDoc($doc1), $html);
            self::assertSame($this->plainFromDoc($doc1), $this->plainFromDoc($doc2), $html);
            self::assertStringContainsString('alpha ', $cleaned);
            self::assertMatchesRegularExpression('/<\/(?:em|a)> gamma/', $cleaned);
        }
    }

    public function test_punctuation_not_extra_spaced(): void
    {
        $html = '<p><strong>Tá»« khÃ³a</strong>, vÃ­ dá»¥</p>';
        [$doc1, $cleaned] = $this->roundTrip($html);
        self::assertSame('Tá»« khÃ³a, vÃ­ dá»¥', $this->plainFromDoc($doc1));
        self::assertStringContainsString('</strong>, vÃ­ dá»¥', $cleaned);
    }

    public function test_nested_marks_and_vietnamese(): void
    {
        $html = '<p>trÆ°á»›c <strong><em>lá»“ng nhau</em></strong> sau</p>';
        [$doc1] = $this->roundTrip($html);
        self::assertSame('trÆ°á»›c lá»“ng nhau sau', $this->plainFromDoc($doc1));
    }

    public function test_table_cell_heading_list_blockquote(): void
    {
        $html = '<h2>TiÃªu Ä‘á» <strong>Ä‘áº­m</strong> nhÃ©</h2>'
            .'<ul><li>má»¥c <em>nghiÃªng</em> Ä‘Ã¢y</li></ul>'
            .'<blockquote><p>trÃ­ch <a href="https://ex.test/a">link</a> xong</p></blockquote>'
            .'<table><tr><td>Ã´ <strong>Ä‘áº­m</strong> cuá»‘i</td></tr></table>';
        [$doc1, $cleaned, $doc2] = $this->roundTrip($html);
        self::assertStringContainsString('TiÃªu Ä‘á» Ä‘áº­m nhÃ©', $this->plainFromDoc($doc1));
        self::assertStringContainsString('má»¥c nghiÃªng Ä‘Ã¢y', $this->plainFromDoc($doc1));
        self::assertStringContainsString('trÃ­ch link xong', $this->plainFromDoc($doc1));
        self::assertStringContainsString('Ã´ Ä‘áº­m cuá»‘i', $this->plainFromDoc($doc1));
        self::assertSame($this->plainFromDoc($doc1), $this->plainFromDoc($doc2));
        self::assertStringContainsString('<table>', $cleaned);
        self::assertStringContainsString('<h2>', $cleaned);
    }

    public function test_bootstrap_rejects_json_that_lost_mark_spaces(): void
    {
        $writerSource = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleEditorDocumentWriter::class))->getFileName(),
        );
        self::assertStringContainsString('hasInlineWhitespaceCorruption', $writerSource);
        self::assertStringContainsString('body_html_repaired', $writerSource);
        self::assertStringContainsString('InlineMarkBoundaryWhitespace', $writerSource);

        $method = new ReflectionMethod(ArticleEditorDocumentWriter::class, 'hasInlineWhitespaceCorruption');
        $writer = (new ReflectionClass(ArticleEditorDocumentWriter::class))->newInstanceWithoutConstructor();
        self::assertTrue($method->invoke(
            $writer,
            'vÃ¬ Mix & Match tÃºi váº£i khÃ´ng dá»‡t Ä‘ang trá»Ÿ thÃ nh',
            'vÃ¬Mix & Match tÃºi váº£i khÃ´ng dá»‡tÄ‘ang trá»Ÿ thÃ nh',
        ));
        self::assertFalse($method->invoke(
            $writer,
            'vÃ¬ Mix Match Ä‘ang',
            'vÃ¬ Mix Match Ä‘ang',
        ));
        // Single intentional space delete must not trip mass-corruption.
        self::assertFalse($method->invoke(
            $writer,
            'alpha beta gamma',
            'alphabeta gamma',
            2,
        ));
    }

    public function test_glued_mark_boundary_repair_is_surgical(): void
    {
        $repair = new \Omnichannel\Addons\Content\Services\ArticleEditor\Document\InlineMarkBoundaryWhitespace;
        $broken = '<p>vÃ¬<strong>Mix &amp; Match tÃºi váº£i khÃ´ng dá»‡t nam</strong>Ä‘ang trá»Ÿ thÃ nh <em>Streetwear</em>chÃ­nh hiá»‡u</p>';
        $report = $repair->repairWithReport($broken);
        self::assertTrue($report['repaired']);
        self::assertSame(0, $report['glued_after']);
        self::assertStringContainsString('vÃ¬ <strong>', $report['html']);
        self::assertStringContainsString('</strong> Ä‘ang', $report['html']);
        self::assertStringContainsString('</em> chÃ­nh', $report['html']);

        $punct = '<p><strong>Tá»« khÃ³a</strong>, vÃ­ dá»¥</p>';
        $punctReport = $repair->repairWithReport($punct);
        self::assertFalse($punctReport['repaired']);
        self::assertStringContainsString('</strong>, vÃ­ dá»¥', $punctReport['html']);
    }

    public function test_client_preserve_whitespace_and_hydration_guards_exist(): void
    {
        $guard = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/inlineWhitespaceGuard.js',
        );
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        $blockEditor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ActiveBlockEditor.jsx',
        );
        $saveQueue = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorSaveQueue.js',
        );
        $bootstrap = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorBootstrap.js',
        );
        $events = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorExternalEventsBridge.js',
        );
        $docJs = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorDocument.js',
        );
        $shell = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );

        self::assertStringContainsString('repairGluedInlineMarkBoundaryWhitespace', $guard);
        self::assertStringContainsString('countGluedInlineMarkBoundaries', $guard);
        self::assertStringContainsString("preserveWhitespace: 'full'", $guard);
        self::assertStringContainsString('TIPTAP_HTML_PARSE_OPTIONS', $editor);
        self::assertStringContainsString('parseOptions: TIPTAP_HTML_PARSE_OPTIONS', $blockEditor);
        self::assertStringContainsString('acceptUpdatesRef', $blockEditor);
        self::assertStringContainsString('INLINE_WHITESPACE_CORRUPTION_CODE', $editor);
        self::assertStringContainsString('assertWritableDocumentNotWhitespaceCorrupted', $editor);
        self::assertStringContainsString('__seoCollectEditorHeavyBundle', $events);
        self::assertStringContainsString('__seoAssertEditorWhitespaceSafe', $events);
        self::assertStringContainsString('__seoAssertEditorWhitespaceSafe', $shell);
        self::assertStringContainsString('hasInlineWhitespaceCorruption', $docJs);
        self::assertStringContainsString('skipNextAutosave.current = true', $bootstrap);
        self::assertStringContainsString('persistLocalRecoverySnapshot', $saveQueue);
    }

    public function test_ingest_keeps_whitespace_only_text_nodes_inline(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleEditorDocumentHtmlIngest::class))->getFileName(),
        );
        self::assertStringContainsString('Keep whitespace-only text when non-empty', $source);
        self::assertStringContainsString("if (\$text === '')", $source);
        self::assertDoesNotMatchRegularExpression(
            '/DOMText[\s\S]{0,200}trim\(\$text\)\s*===\s*[\'\"]{2}/',
            $source,
        );
    }
}
