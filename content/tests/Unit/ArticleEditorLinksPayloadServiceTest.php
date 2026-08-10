<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use Omnichannel\Addons\Content\Services\ArticleEditorSeoPayloadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source/contract tests only (no DB, no app()) — asserts the Links panel never
 * routes through the heavy ArticleEditorSeoPayloadService::forArticle() bundle
 * and that suggestions are computed via ONE suggestBundle() pass, not four
 * separate suggest()/suggestCatalog()/suggestExternal()/suggestExternalCatalog() calls.
 */
final class ArticleEditorLinksPayloadServiceTest extends TestCase
{
    public function test_base_extracts_links_and_domain_lists_without_suggestion_service(): void
    {
        $body = $this->methodBody(ArticleEditorLinksPayloadService::class, 'base');

        self::assertStringContainsString('resolveExtractedLinks', $body);
        self::assertStringContainsString('DomainLinkListEditorService', $body);
        self::assertStringContainsString('DomainCtaEditorService', $body);
        self::assertStringContainsString("'suggested_internal_links' => []", $body);
        self::assertStringContainsString("'suggested_external_links' => []", $body);

        self::assertStringNotContainsString('suggestionService', $body);
        self::assertStringNotContainsString('suggestBundle', $body);
    }

    public function test_with_suggestions_calls_suggest_bundle_exactly_once(): void
    {
        $body = $this->methodBody(ArticleEditorLinksPayloadService::class, 'withSuggestions');

        self::assertSame(1, substr_count($body, 'suggestBundle('));
        self::assertStringNotContainsString('->suggest(', $body);
        self::assertStringNotContainsString('->suggestCatalog(', $body);
        self::assertStringNotContainsString('->suggestExternal(', $body);
        self::assertStringNotContainsString('->suggestExternalCatalog(', $body);
    }

    public function test_service_never_calls_for_article(): void
    {
        $ref = new ReflectionClass(ArticleEditorLinksPayloadService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringNotContainsString('forArticle(', $source);
        self::assertStringNotContainsString('forEditorBootstrap(', $source);
    }

    public function test_for_article_uses_suggest_bundle_once_instead_of_four_calls(): void
    {
        $body = $this->methodBody(ArticleEditorSeoPayloadService::class, 'forArticle');

        self::assertSame(1, substr_count($body, 'suggestBundle('));
        self::assertStringNotContainsString('->suggest(', $body);
        self::assertStringNotContainsString('->suggestCatalog(', $body);
        self::assertStringNotContainsString('->suggestExternal(', $body);
        self::assertStringNotContainsString('->suggestExternalCatalog(', $body);
    }

    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = $ref->getMethod($method);
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));

        return implode("\n", array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1,
        ));
    }
}
