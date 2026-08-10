<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Contract: bootstrap must return + destructure initialEditorDocument to avoid ReferenceError on mount.
 */
final class ArticleEditorBootstrapDocumentWireContractTest extends TestCase
{
    public function test_bootstrap_returns_and_destructures_editor_document(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );

        self::assertStringContainsString('let initialEditorDocument = null;', $source);
        self::assertStringContainsString('initialEditorDocument,', $source);
        self::assertStringContainsString('initialEditorDocumentHash,', $source);
        self::assertStringContainsString('initialEditorDocument={initialEditorDocument}', $source);
        self::assertStringContainsString('initialEditorDocumentHash={initialEditorDocumentHash}', $source);

        $returnPos = strpos($source, "return {\n        initialHtml,\n        initialEditorDocument,");
        self::assertNotFalse($returnPos, 'readArticleEditorBootstrap must return initialEditorDocument');

        $destructurePos = strpos($source, "const {\n        initialHtml,\n        initialEditorDocument,");
        self::assertNotFalse($destructurePos, 'mount must destructure initialEditorDocument from bootstrap');
    }
}
