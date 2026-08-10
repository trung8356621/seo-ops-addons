<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Hollow editor_document (images + empty TipTap) must not win over body HTML at bootstrap.
 */
final class ArticleEditorBootstrapJsonFallbackTest extends TestCase
{
    public function test_resolve_for_bootstrap_gates_usable_document(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleEditorDocumentWriter::class))->getFileName(),
        );
        self::assertStringContainsString('isUsableBootstrapDocument', $source);
        self::assertStringContainsString('bootstrap_json_rejected_fallback_html', $source);
        self::assertStringContainsString('htmlHasMeaningfulText', $source);
        self::assertStringContainsString('tipTapHasMeaningfulText', $source);

        $resolve = $this->methodSource(new ReflectionMethod(ArticleEditorDocumentWriter::class, 'resolveForBootstrap'));
        self::assertStringContainsString('isUsableBootstrapDocument', $resolve);
        self::assertTrue(
            strpos($resolve, 'isUsableBootstrapDocument') < strpos($resolve, "'source' => 'editor_document'"),
        );
    }

    public function test_usable_gate_rejects_textless_json_when_body_has_prose(): void
    {
        $usable = $this->methodSource(new ReflectionMethod(ArticleEditorDocumentWriter::class, 'isUsableBootstrapDocument'));
        self::assertStringContainsString('$bodyPlainLength > 0', $usable);
        self::assertStringContainsString('! $hasMeaningfulText', $usable);
        self::assertStringContainsString("\$blocks === []", $usable);
        self::assertStringContainsString('$jsonPlainLength * 2 < $bodyPlainLength', $usable);
        self::assertStringContainsString('$emptyTextBlockCount * 2 >= $textBlockCount', $usable);
        self::assertStringContainsString('$bodyHasTable && ! $jsonHasTableContent', $usable);
    }

    public function test_client_mirrors_usable_envelope_gate(): void
    {
        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorDocument.js',
        );
        self::assertStringContainsString('export function isUsableEditorDocumentEnvelope', $js);
        self::assertStringContainsString('export function isUsableTipTapDocument', $js);
        self::assertStringContainsString('tipTapDocToPreviewHtml', $js);
        self::assertStringContainsString('jsonPlainLength * 2 < bodyPlainLength', $js);
        self::assertStringContainsString('emptyTextBlockCount * 2 >= textBlockCount', $js);

        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('blocksFromEditorDocumentEnvelope(initialEditorDocument, initialHtml)', $editor);
        self::assertStringContainsString('isUsableTipTapDocument(block.editorDocument)', $editor);

        $boot = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString('isUsableEditorDocumentEnvelope(initialEditorDocument, initialHtml)', $boot);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
