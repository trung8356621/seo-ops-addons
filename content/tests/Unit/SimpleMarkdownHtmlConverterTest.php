<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;
use PHPUnit\Framework\TestCase;

final class SimpleMarkdownHtmlConverterTest extends TestCase
{
    private function converter(): SimpleMarkdownHtmlConverter
    {
        return new SimpleMarkdownHtmlConverter;
    }

    public function test_markdown_soft_breaks_become_spaces_not_newlines(): void
    {
        $md = "✅ Độ tin cậy cao: Tốt.\n✅ Công nghệ hiện đại: Hiện đại.\n✅ Hỗ trợ tùy biến: Linh hoạt.";
        $html = $this->converter()->toHtml($md);

        self::assertSame(1, substr_count(strtolower($html), '<p'));
        self::assertStringNotContainsString('<br', strtolower($html));
        // Soft break must not leave a literal newline before the next emoji.
        self::assertDoesNotMatchRegularExpression('/\n\s*✅/u', $html);
        self::assertStringContainsString('Tốt. ✅ Công nghệ', $html);
        self::assertStringContainsString('Hiện đại. ✅ Hỗ trợ', $html);
    }

    public function test_markdown_blank_line_keeps_two_paragraphs(): void
    {
        $html = $this->converter()->toHtml("Dòng thứ nhất.\n\nDòng thứ hai.");

        self::assertSame(2, substr_count(strtolower($html), '<p'));
        self::assertStringContainsString('<p>Dòng thứ nhất.</p>', $html);
        self::assertStringContainsString('<p>Dòng thứ hai.</p>', $html);
    }

    public function test_h2_renders_as_h2(): void
    {
        $html = $this->converter()->toHtml("## Tiêu đề phần\n\nĐoạn.");

        $this->assertStringContainsString('<h2>Tiêu đề phần</h2>', $html);
        $this->assertStringNotContainsString('<h3>Tiêu đề phần</h3>', $html);
    }

    public function test_h3_renders_as_h3(): void
    {
        $html = $this->converter()->toHtml("### Tiêu đề mục con\n\nĐoạn.");

        $this->assertStringContainsString('<h3>Tiêu đề mục con</h3>', $html);
    }

    public function test_markdown_table_renders(): void
    {
        $html = $this->converter()->toHtml(<<<'MD'
| A | B |
| --- | --- |
| 1 | 2 |
MD);

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>A</th>', $html);
        $this->assertStringContainsString('<td>1</td>', $html);
    }

    public function test_bold_is_not_heading(): void
    {
        $html = $this->converter()->toHtml("**Main Content:**\n\nParagraph.");

        $this->assertStringNotContainsString('<h2>', $html);
        $this->assertStringNotContainsString('<h3>', $html);
        $this->assertStringContainsString('<strong>Main Content:</strong>', $html);
    }

    public function test_ordered_and_unordered_lists(): void
    {
        $html = $this->converter()->toHtml(<<<'MD'
- một
- hai

1. alpha
2. beta
MD);

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>một</li>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<li>alpha</li>', $html);
    }

    public function test_raw_html_is_stripped(): void
    {
        $html = $this->converter()->toHtml("Hello <script>alert(1)</script> world");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('world', $html);
    }

    public function test_vietnamese_unicode(): void
    {
        $html = $this->converter()->toHtml("## Đường phố Hà Nội\n\nNghĩa đen **rất** đẹp.");

        $this->assertStringContainsString('<h2>Đường phố Hà Nội</h2>', $html);
        $this->assertStringContainsString('<strong>rất</strong>', $html);
    }

    public function test_prepare_import_extracts_h1_and_meta(): void
    {
        $markdown = <<<'MD'
# Tiêu đề bài

Meta Description: Mô tả ngắn

## Phần chính

Nội dung.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Tiêu đề bài', $prepared['h1_title']);
        $this->assertSame('Mô tả ngắn', $prepared['meta_description']);
        $this->assertStringContainsString('## Phần chính', $prepared['markdown']);
        $this->assertStringNotContainsString('# Tiêu đề bài', $prepared['markdown']);
    }

    public function test_prepare_import_removes_main_content_label(): void
    {
        $markdown = <<<'MD'
## Main Content:

## Phần thật

Body.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertStringNotContainsString('Main Content', $prepared['markdown']);
        $this->assertStringContainsString('## Phần thật', $prepared['markdown']);
    }

    public function test_legacy_promote_orphan_h3_helper_still_exists(): void
    {
        $markdown = "### Phần chính\n\nNội dung.";
        $promoted = $this->converter()->promoteOrphanH3HeadingsToH2($markdown);

        $this->assertStringContainsString('## Phần chính', $promoted);
    }

    public function test_does_not_auto_promote_during_to_html(): void
    {
        $html = $this->converter()->toHtml("### Phần chính\n\nNội dung.");

        $this->assertStringContainsString('<h3>Phần chính</h3>', $html);
        $this->assertStringNotContainsString('<h2>Phần chính</h2>', $html);
    }
}
