<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentHtmlIngest;
use Omnichannel\Addons\Content\Services\ArticleEditorPersistService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ArticleEditorTableIngestContractTest extends TestCase
{
    public function test_html_ingest_converts_table_rows_not_empty_stub(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleEditorDocumentHtmlIngest::class))->getFileName(),
        );
        self::assertStringContainsString('convertTable', $source);
        self::assertStringNotContainsString(
            "return ['type' => 'table', 'content' => [], 'attrs' => ['htmlPreview' => true]];",
            $source,
        );
        self::assertStringContainsString("'type' => 'tableRow'", $source);
        self::assertStringContainsString("'tableHeader' : 'tableCell'", $source);
        self::assertStringContainsString('tableHeader', $source);
        self::assertStringContainsString('tableCell', $source);
    }

    public function test_client_compat_converts_table_rows(): void
    {
        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/htmlDocumentCompat.js',
        );
        self::assertStringContainsString('function convertTableElement', $js);
        self::assertStringContainsString("type: 'tableRow'", $js);
        self::assertStringNotContainsString('attrs: { htmlPreview: true }', $js);
    }

    public function test_preview_html_and_bootstrap_gate_keep_tables(): void
    {
        $doc = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorDocument.js',
        );
        self::assertStringContainsString("case 'table':", $doc);
        self::assertStringContainsString('bodyHasTable && !jsonHasTableContent', $doc);
        self::assertStringContainsString('tipTapHasTableContent', $doc);

        $writer = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditor/Document/ArticleEditorDocumentWriter.php',
        );
        self::assertStringContainsString('$bodyHasTable && ! $jsonHasTableContent', $writer);
    }

    public function test_persist_refuses_to_wipe_tables_from_empty_json(): void
    {
        $persist = $this->methodSource(new ReflectionMethod(ArticleEditorPersistService::class, 'writeArticleRow'));
        self::assertStringContainsString('htmlHasTableCells', $persist);
        self::assertStringContainsString('$clientHtml', $persist);
        self::assertStringContainsString('$previousBody', $persist);
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
