<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Support\CommentReviewPayloadParser;
use PHPUnit\Framework\TestCase;

final class CommentReviewPayloadParserTest extends TestCase
{
    public function test_parses_split_lines_with_pipe_delimiter(): void
    {
        $parser = new CommentReviewPayloadParser();

        $raw = <<<'TEXT'
Họ và tên | Email | Nội dung bình luận
Nguyễn Văn A | nva@example.com | Balo đẹp và chắc chắn
Trần B | tranb@example.com | Đeo êm, đáng tiền
TEXT;

        $items = $parser->parse($raw);

        $this->assertCount(2, $items);
        $this->assertSame('Nguyễn Văn A', $items[0]['author']);
        $this->assertSame('nva@example.com', $items[0]['email']);
        $this->assertSame('Balo đẹp và chắc chắn', $items[0]['content']);
        $this->assertSame('Trần B', $items[1]['author']);
    }

    public function test_prioritizes_split_lines_before_json(): void
    {
        $parser = new CommentReviewPayloadParser();

        $raw = <<<'TEXT'
Lê C | lec@example.com | Ưu tiên dòng split

[
  {"comment":"JSON fallback","author":"Json User","email":"json@example.com"}
]
TEXT;

        $items = $parser->parse($raw);

        $this->assertCount(1, $items);
        $this->assertSame('Lê C', $items[0]['author']);
        $this->assertSame('lec@example.com', $items[0]['email']);
        $this->assertSame('Ưu tiên dòng split', $items[0]['content']);
    }

    public function test_parses_vietnamese_keys_from_json_array(): void
    {
        $parser = new CommentReviewPayloadParser();

        $raw = <<<'JSON'
[
  {
    "comment": "Balo xịn quá",
    "Họ và tên": "Nguyễn Thu Thảo",
    "Email": "thuthao@example.com"
  }
]
JSON;

        $items = $parser->parse($raw);

        $this->assertCount(1, $items);
        $this->assertSame('Balo xịn quá', $items[0]['content']);
        $this->assertSame('Nguyễn Thu Thảo', $items[0]['author']);
        $this->assertSame('thuthao@example.com', $items[0]['email']);
        $this->assertArrayNotHasKey('rating', $items[0]);
    }
}
