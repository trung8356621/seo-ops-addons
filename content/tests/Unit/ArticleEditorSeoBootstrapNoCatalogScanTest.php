<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleEditorSeoPayloadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArticleEditorSeoBootstrapNoCatalogScanTest extends TestCase
{
    public function test_for_editor_bootstrap_exists_and_for_article_remains_for_on_demand(): void
    {
        $ref = new ReflectionClass(ArticleEditorSeoPayloadService::class);
        self::assertTrue($ref->hasMethod('forEditorBootstrap'));
        self::assertTrue($ref->hasMethod('forArticle'));

        $bootstrapSource = file_get_contents($ref->getFileName());
        self::assertIsString($bootstrapSource);

        $bootstrapMethod = $ref->getMethod('forEditorBootstrap');
        $start = $bootstrapMethod->getStartLine();
        $end = $bootstrapMethod->getEndLine();
        $lines = explode("\n", $bootstrapSource);
        $body = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));

        self::assertStringNotContainsString('ArticleInternalLinkSuggestionService', $body);
        self::assertStringNotContainsString('suggestCatalog', $body);
        self::assertStringNotContainsString('DomainLinkListEditorService', $body);
        self::assertStringContainsString("'bootstrap_mode' => 'light'", $body);
    }
}
