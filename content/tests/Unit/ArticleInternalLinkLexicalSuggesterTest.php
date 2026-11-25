<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkLexicalSuggester;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ArticleInternalLinkLexicalSuggesterTest extends TestCase
{
    public function test_extract_phrases_prefers_multi_word_content_spans(): void
    {
        $service = (new ReflectionClass(ArticleInternalLinkLexicalSuggester::class))
            ->newInstanceWithoutConstructor();

        $phrases = $service->extractPhrases(
            'May Balo Ngoại Ngữ bền đẹp. Balo da thật cao cấp cho doanh nghiệp.',
        );

        self::assertNotEmpty($phrases);
        self::assertTrue(
            collect($phrases)->contains(static fn (string $p): bool => str_contains(mb_strtolower($p), 'balo')),
        );
        self::assertFalse(
            collect($phrases)->contains(static fn (string $p): bool => mb_strtolower($p) === 'doanh nghiệp'),
        );
    }

    public function test_suggester_uses_ordinary_search_service_not_topic_groups(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleInternalLinkLexicalSuggester::class))->getFileName(),
        );

        self::assertStringContainsString('ArticleInternalLinkSearchService', $source);
        self::assertStringContainsString('extractPhrases', $source);
        self::assertStringContainsString('DomainLinkListEditorService', $source);
        self::assertStringContainsString('EditorAnchorOccurrenceMatcher', $source);
        self::assertStringContainsString('MAX_ARTICLE_DESTINATIONS', $source);
        self::assertStringNotContainsString('TopicGroup', $source);
        self::assertStringNotContainsString('SeoKeywordGroup', $source);
        self::assertStringNotContainsString('semantic.url', $source);
        self::assertStringNotContainsString('InternalLinkV2Suggester', $source);
        self::assertTrue(class_exists(ArticleInternalLinkSearchService::class));
    }

    public function test_destination_driven_pipeline_and_diagnostics_contract(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleInternalLinkLexicalSuggester::class))->getFileName(),
        );

        self::assertStringContainsString('articleCatalogCandidates', $source);
        self::assertStringContainsString('domainLinkCandidates', $source);
        self::assertStringContainsString('expandAnchorTexts', $source);
        self::assertStringContainsString('relationshipForUrl', $source);
        self::assertStringContainsString("'cross_domain'", $source);
        self::assertStringContainsString('anchors_matched_in_content', $source);
        self::assertStringContainsString('no_authoritative_permalink', $source);
        self::assertStringContainsString('already_linked', $source);
        self::assertStringContainsString('self_link', $source);
    }

    public function test_expand_anchor_texts_exposes_destination_cores(): void
    {
        $service = (new ReflectionClass(ArticleInternalLinkLexicalSuggester::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ArticleInternalLinkLexicalSuggester::class, 'expandAnchorTexts');
        $method->setAccessible(true);

        $expanded = $method->invoke($service, 'May Túi Vải Bố Canvas');
        $lower = array_map(static fn (string $p): string => mb_strtolower($p), $expanded);

        self::assertContains('may túi vải bố canvas', $lower);
        self::assertContains('túi canvas', $lower);
        self::assertNotContains('thời trang', $lower);
    }
}
