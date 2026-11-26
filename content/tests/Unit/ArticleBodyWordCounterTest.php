<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleBodyWordCounter;
use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;
use PHPUnit\Framework\TestCase;

final class ArticleBodyWordCounterTest extends TestCase
{
    public function test_empty_body_returns_null_not_zero(): void
    {
        self::assertNull(ArticleBodyWordCounter::countOrNull(null));
        self::assertNull(ArticleBodyWordCounter::countOrNull(''));
        self::assertNull(ArticleBodyWordCounter::countOrNull('   '));
        self::assertNull(ArticleBodyWordCounter::countOrNull('<p></p>'));
        self::assertNull(ArticleBodyWordCounter::countOrNull('<style>.x{color:red}</style>'));
        self::assertSame('—', ArticleBodyWordCounter::format(null));
        self::assertSame('—', ArticleBodyWordCounter::format(0));
    }

    public function test_matches_editor_whitespace_split_after_html_strip(): void
    {
        $html = '<p>một hai <strong>ba</strong></p><script>ignore me</script>';
        self::assertSame(3, ArticleBodyWordCounter::count($html));
        self::assertSame(3, ArticleBodyWordCounter::countOrNull($html));
        self::assertSame(
            PromptTextMetrics::wordCount('một hai ba'),
            ArticleBodyWordCounter::count($html),
        );
    }

    public function test_shortcode_syntax_not_counted(): void
    {
        $html = '<p>Hello world</p>[gallery ids="1,2,3"][faq]inner text[/faq]';
        // "Hello world" + "inner text" = 4; gallery/faq tag tokens excluded.
        self::assertSame(4, ArticleBodyWordCounter::count($html));
    }

    public function test_format_uses_dot_thousands_separator(): void
    {
        self::assertSame('3.125', ArticleBodyWordCounter::format(3125));
        self::assertSame('1', ArticleBodyWordCounter::format(1));
    }
}
