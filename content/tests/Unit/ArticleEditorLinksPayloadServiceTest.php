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
        $ref = new ReflectionClass(ArticleEditorLinksPayloadService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());
        $body = $this->methodBody(ArticleEditorLinksPayloadService::class, 'base');

        self::assertStringContainsString(
            'use Omnichannel\\Addons\\Seo\\Services\\DomainLinkListEditorService;',
            $source,
        );
        self::assertStringContainsString(
            'use Omnichannel\\Addons\\Seo\\Services\\DomainCtaEditorService;',
            $source,
        );
        self::assertStringContainsString('resolveExtractedLinks', $body);
        self::assertStringContainsString('DomainLinkListEditorService', $body);
        self::assertStringContainsString('DomainCtaEditorService', $body);
        self::assertStringContainsString("'suggested_internal_links' => []", $body);
        self::assertStringContainsString("'suggested_external_links' => []", $body);
        self::assertStringNotContainsString("'suggested_orphan_links'", $body);

        self::assertStringNotContainsString('suggestionService', $body);
        self::assertStringNotContainsString('suggestBundle', $body);
    }

    public function test_with_suggestions_uses_v2_not_the_legacy_bundle(): void
    {
        $body = $this->methodBody(ArticleEditorLinksPayloadService::class, 'withSuggestions');

        self::assertStringNotContainsString('suggestBundle(', $body);
        self::assertStringContainsString('mergeSemanticSuggestions', $body);
        self::assertStringNotContainsString('withOrphanSuggestions', $body);
        self::assertStringNotContainsString('->suggestCatalog(', $body);
        self::assertStringNotContainsString('->suggestExternal(', $body);
        self::assertStringNotContainsString('->suggestExternalCatalog(', $body);
    }

    public function test_service_never_calls_seo_payload_bundle(): void
    {
        $ref = new ReflectionClass(ArticleEditorLinksPayloadService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());

        // Docblock may mention ArticleEditorSeoPayloadService; code must not call it.
        self::assertStringNotContainsString('app(ArticleEditorSeoPayloadService', $source);
        self::assertDoesNotMatchRegularExpression(
            '/^use\s+.+\\\\ArticleEditorSeoPayloadService\s*;/m',
            $source,
        );
        self::assertStringNotContainsString('forEditorBootstrap(', $source);
    }

    public function test_for_article_does_not_call_the_legacy_suggestion_bundle(): void
    {
        $body = $this->methodBody(ArticleEditorSeoPayloadService::class, 'forArticle');

        self::assertStringNotContainsString('suggestBundle(', $body);
        self::assertStringNotContainsString('ArticleInternalLinkSuggestionService', $body);
        self::assertStringContainsString('DomainLinkListEditorService', $body);
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
