<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\LinkIntelligence\LinkExtractor;
use PHPUnit\Framework\TestCase;

final class LinkExtractorTest extends TestCase
{
    private LinkExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new LinkExtractor;
    }

    public function test_extracts_real_href_from_html_anchor(): void
    {
        $html = '<a href="https://s.shopee.vn/2BCY1X75a4?share_channel_code=8">s.shopee.vn/2BCY1…</a>';
        $links = $this->extractor->extract('s.shopee.vn/2BCY1…', $html);

        self::assertCount(1, $links);
        self::assertSame(
            'https://s.shopee.vn/2BCY1X75a4?share_channel_code=8',
            $links[0]->normalizedUrl,
        );
        self::assertSame('html_anchor', $links[0]->source);
    }

    public function test_extracts_markdown_target_url_not_display_text(): void
    {
        $text = 'See [display](https://example.com/real) please';
        $links = $this->extractor->extract($text);

        self::assertCount(1, $links);
        self::assertSame('https://example.com/real', $links[0]->normalizedUrl);
        self::assertSame('markdown', $links[0]->source);
    }

    public function test_extracts_plain_https_url(): void
    {
        $links = $this->extractor->extract('Visit https://example.com/a today');

        self::assertCount(1, $links);
        self::assertSame('https://example.com/a', $links[0]->normalizedUrl);
        self::assertSame('plain', $links[0]->source);
    }

    public function test_shopee_markdown_case_extracts_real_url_only(): void
    {
        $text = 'Đợt trước mình cũng tìm đúng kiểu này, cuối cùng chọn mẫu này và không hối hận. [s.shopee.vn/2BCY1…](https://s.shopee.vn/2BCY1X75a4?share_channel_code=8)';
        $links = $this->extractor->extract($text);
        $urls = array_map(static fn ($link): string => $link->normalizedUrl, $links);

        self::assertSame(
            ['https://s.shopee.vn/2BCY1X75a4?share_channel_code=8'],
            $urls,
        );
        self::assertFalse(
            (bool) preg_grep('#^https?://s\.shopee\.vn/2BCY1…#u', $urls),
        );
    }

    public function test_extracts_multiple_urls(): void
    {
        $text = "A https://example.com/a\nB https://example.com/b";
        $links = $this->extractor->extract($text);

        self::assertCount(2, $links);
        self::assertSame('https://example.com/a', $links[0]->normalizedUrl);
        self::assertSame('https://example.com/b', $links[1]->normalizedUrl);
    }

    public function test_dedupes_same_url_across_html_and_markdown(): void
    {
        $text = 'Check [x](https://example.com/same) and https://example.com/same';
        $html = '<a href="https://example.com/same">same</a>';
        $links = $this->extractor->extract($text, $html);

        self::assertCount(1, $links);
        self::assertSame('https://example.com/same', $links[0]->normalizedUrl);
        self::assertSame('html_anchor', $links[0]->source);
    }

    public function test_accepts_http_and_https(): void
    {
        $links = $this->extractor->extract('http://example.com/a https://example.com/b');

        self::assertCount(2, $links);
        self::assertSame('http://example.com/a', $links[0]->normalizedUrl);
        self::assertSame('https://example.com/b', $links[1]->normalizedUrl);
    }

    public function test_rejects_dangerous_schemes(): void
    {
        $html = implode("\n", [
            '<a href="javascript:alert(1)">x</a>',
            '<a href="data:text/html,hi">y</a>',
            '<a href="file:///etc/passwd">z</a>',
            '<a href="ftp://files.example.com/a">f</a>',
        ]);
        $text = 'javascript:alert(1) data:text/html,hi file:///tmp ftp://files.example.com/a';

        self::assertSame([], $this->extractor->extract($text, $html));
    }

    public function test_does_not_invent_url_from_bare_host_display_text(): void
    {
        $links = $this->extractor->extract('s.shopee.vn/2BCY1… only');

        self::assertSame([], $links);
    }
}
