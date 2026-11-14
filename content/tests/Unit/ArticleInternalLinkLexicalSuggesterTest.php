<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkLexicalSuggester;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
        self::assertStringNotContainsString('TopicGroup', $source);
        self::assertStringNotContainsString('SeoKeywordGroup', $source);
        self::assertStringNotContainsString('semantic.url', $source);
        self::assertTrue(class_exists(ArticleInternalLinkSearchService::class));
    }
}
