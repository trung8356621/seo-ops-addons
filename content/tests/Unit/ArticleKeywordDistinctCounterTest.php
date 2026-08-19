<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleKeywordDistinctCounter;
use PHPUnit\Framework\TestCase;

final class ArticleKeywordDistinctCounterTest extends TestCase
{
    public function test_counts_distinct_phrases_across_groups(): void
    {
        $raw = json_encode([
            'Synonymy' => ['balo nam', 'balo nữ', 'Balo Nam'],
            'Holonymy' => ['balo nữ', 'túi xách'],
        ], JSON_UNESCAPED_UNICODE);

        self::assertSame(3, ArticleKeywordDistinctCounter::count($raw));
    }

    public function test_missing_or_empty_returns_zero(): void
    {
        self::assertSame(0, ArticleKeywordDistinctCounter::count(null));
        self::assertSame(0, ArticleKeywordDistinctCounter::count(''));
        self::assertSame(0, ArticleKeywordDistinctCounter::count('[]'));
        self::assertSame(0, ArticleKeywordDistinctCounter::count([]));
    }

    public function test_does_not_scan_article_body(): void
    {
        $src = (string) file_get_contents(
            (string) (new \ReflectionClass(ArticleKeywordDistinctCounter::class))->getFileName(),
        );

        self::assertStringContainsString("META_KEY = 'seo_article_keywords'", $src);
        self::assertStringNotContainsString('$article->body', $src);
        self::assertStringNotContainsString('wp_post_content', $src);
        self::assertStringNotContainsString('strip_tags', $src);
    }

    public function test_list_payload_and_object_items(): void
    {
        self::assertSame(2, ArticleKeywordDistinctCounter::count(['Alpha', 'alpha', 'Beta']));
        self::assertSame(1, ArticleKeywordDistinctCounter::count([
            'Group' => [['keyword' => 'focus phrase'], ['phrase' => 'Focus Phrase']],
        ]));
    }
}
