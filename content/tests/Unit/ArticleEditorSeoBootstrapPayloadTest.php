<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleEditorSeoPayloadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit/contract only — không gọi forEditorBootstrap runtime (tránh DB omi_seo_ai / focus keyword query).
 */
final class ArticleEditorSeoBootstrapPayloadTest extends TestCase
{
    public function test_for_editor_bootstrap_is_light_and_defers_heavy_catalogs(): void
    {
        $ref = new ReflectionClass(ArticleEditorSeoPayloadService::class);
        $method = $ref->getMethod('forEditorBootstrap');
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));
        $body = implode("\n", array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringNotContainsString('ArticleInternalLinkSuggestionService', $body);
        self::assertStringNotContainsString('DomainLinkListEditorService', $body);
        self::assertStringNotContainsString('DomainCtaEditorService', $body);
        self::assertStringNotContainsString('suggestCatalog', $body);
        self::assertStringNotContainsString('$this->contentBonus', $body);
        self::assertStringNotContainsString('resolveExtractedLinks', $body);

        self::assertStringContainsString("'bootstrap_mode' => 'light'", $body);
        self::assertStringContainsString("'status' => 'cached'", $body);
        self::assertStringContainsString("'suggested_internal_links' => []", $body);
        self::assertStringContainsString("'suggested_internal_links_catalog' => []", $body);
        self::assertStringContainsString("'domain_link_list' => []", $body);
        self::assertStringContainsString("'domain_link_list_catalog' => []", $body);
        self::assertStringContainsString("'domain_cta_list' => []", $body);
        self::assertStringContainsString("'content_bonus' => null", $body);
        self::assertStringContainsString("'extracted_links' => ['internal' => [], 'external' => []]", $body);
    }

    public function test_for_article_full_payload_method_still_exists(): void
    {
        $ref = new ReflectionClass(ArticleEditorSeoPayloadService::class);
        self::assertTrue($ref->hasMethod('forArticle'));
        self::assertTrue($ref->hasMethod('forEditorBootstrap'));

        $forArticle = $ref->getMethod('forArticle');
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));
        $body = implode("\n", array_slice(
            $lines,
            $forArticle->getStartLine() - 1,
            $forArticle->getEndLine() - $forArticle->getStartLine() + 1,
        ));

        // Full path vẫn giữ suggestion catalogs (on-demand endpoint).
        self::assertStringContainsString('ArticleInternalLinkSuggestionService', $body);
    }
}
