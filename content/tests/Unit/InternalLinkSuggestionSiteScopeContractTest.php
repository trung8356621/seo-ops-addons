<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Internal Link V2 reads the article row site, not the editor header/query site.
 */
final class InternalLinkSuggestionSiteScopeContractTest extends TestCase
{
    public function test_suggester_uses_article_site_id_not_request_header(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/InternalLinkV2Suggester.php');

        self::assertStringContainsString('$siteId = (int) ($article->site_id ?? 0);', $source);
        self::assertStringNotContainsString('X-Site-ID', $source);
        self::assertStringNotContainsString('globalSiteId', $source);
    }

    public function test_suggestions_endpoint_passes_the_article_not_the_query_site(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Http/Controllers/ArticleEditorLazyPayloadController.php');
        $method = $this->methodSource($source, 'function linksSuggestions');

        self::assertStringContainsString('->withSuggestions(', $method);
        self::assertStringContainsString('$article', $method);
        self::assertStringNotContainsString('X-Site-ID', $method);
        self::assertStringNotContainsString("input('site_id'", $method);
    }

    private function methodSource(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        self::assertNotFalse($start);
        $next = strpos($source, 'private function ', $start + strlen($signature));
        self::assertNotFalse($next);

        return substr($source, $start, $next - $start);
    }
}
