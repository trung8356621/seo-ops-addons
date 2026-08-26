<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guard: never persist an AI JSON dump into seo_project_tasks.source_content.
 */
final class NewContentSuggestionParserDumpGuardTest extends TestCase
{
    public function test_parses_plain_json_array(): void
    {
        $json = json_encode([
            [
                'keyword' => 'túi giữ nhiệt',
                'suggested_title' => 'Túi giữ nhiệt in logo',
                'description' => 'Brief',
                'product_type' => 'túi giữ nhiệt',
                'gallery_description' => 'Ảnh góc chính',
                'suggestion_reason' => 'gap',
                'source_signal' => 'cluster_gap',
            ],
        ], JSON_UNESCAPED_UNICODE);

        $parsed = (new NewContentSuggestionParser)->parse($json, 10);

        self::assertSame(1, $parsed['generated']);
        self::assertSame(0, $parsed['invalid']);
        self::assertSame('túi giữ nhiệt', $parsed['candidates'][0]['keyword']);
        self::assertSame('túi giữ nhiệt', $parsed['candidates'][0]['product_type']);
    }

    public function test_extracts_json_from_prose_wrapper(): void
    {
        $payload = <<<'TXT'
Here are product planning suggestions:
[
  {"keyword":"balo học sinh","suggested_title":"Balo học sinh Sư Tử","description":"Trang sản phẩm","product_type":"balo","gallery_description":"Góc trước","suggestion_reason":"gap","source_signal":"keyword_gap"}
]
TXT;

        $parsed = (new NewContentSuggestionParser)->parse($payload, 10);

        // Prose preamble is rejected — importer must not scrape the first [...] from commentary.
        self::assertSame([], $parsed['candidates']);
        self::assertSame(0, $parsed['generated']);
    }

    public function test_rejects_reasoning_preamble_without_json_root(): void
    {
        $payload = "We need to produce JSON array of objects...\nDetermine content_type...\nWe need to propose about 10 planning suggestions...";

        $parsed = (new NewContentSuggestionParser)->parse($payload, 10);

        self::assertSame([], $parsed['candidates']);
        self::assertSame(0, $parsed['generated']);
    }

    public function test_unwraps_double_encoded_json_string(): void
    {
        $inner = json_encode([
            ['keyword' => 'túi canvas', 'suggested_title' => 'Túi vải canvas'],
        ], JSON_UNESCAPED_UNICODE);
        $wrapped = json_encode($inner, JSON_UNESCAPED_UNICODE);

        $parsed = (new NewContentSuggestionParser)->parse($wrapped, 10);

        self::assertCount(1, $parsed['candidates']);
        self::assertSame('túi canvas', $parsed['candidates'][0]['keyword']);
    }

    public function test_rejects_raw_json_blob_as_single_keyword(): void
    {
        $blob = json_encode([
            ['keyword' => 'a', 'suggested_title' => 'A', 'suggestion_reason' => 'x', 'source_signal' => 'cluster_gap'],
            ['keyword' => 'b', 'suggested_title' => 'B', 'suggestion_reason' => 'y', 'source_signal' => 'cluster_gap'],
        ], JSON_UNESCAPED_UNICODE);

        // Simulate failed structured parse falling through as one string row (legacy path).
        $parsed = (new NewContentSuggestionParser)->parse([
            $blob,
        ], 10);

        // flattenRows on array-of-string: each string goes through parseRow, not flattenRows again.
        // Blob must be rejected as structured dump — not become a candidate.
        self::assertSame([], $parsed['candidates']);
        self::assertSame(1, $parsed['invalid']);
    }

    public function test_rejects_planned_items_list_echo_as_keyword(): void
    {
        $dump = 'Planned items list includes many: "túi vải không dệt có dây kéo", "vải voan", "vải oxford", '
            .'"in túi vải không dệt", "túi không dệt dây rút", "túi vải dây rút", "balo quảng cáo", '
            .'"quà tặng doanh nghiệp", "vải pp", "khóa kéo ykk", "túi tote canvas" etc.';

        $parsed = (new NewContentSuggestionParser)->parse([
            [
                'keyword' => $dump,
                'suggested_title' => 'Túi vải không dệt',
                'description' => 'Brief',
                'product_type' => 'túi',
                'gallery_description' => 'Ảnh',
                'source_signal' => 'cluster_gap',
            ],
        ], 10);

        self::assertSame([], $parsed['candidates']);
        self::assertSame(1, $parsed['invalid']);
    }

    public function test_strips_markdown_fence(): void
    {
        $fenced = "```json\n".json_encode([
            ['keyword' => 'may balo', 'suggested_title' => 'Xưởng may balo'],
        ], JSON_UNESCAPED_UNICODE)."\n```";

        $parsed = (new NewContentSuggestionParser)->parse($fenced, 5);

        self::assertCount(1, $parsed['candidates']);
        self::assertSame('may balo', $parsed['candidates'][0]['keyword']);
    }

    public function test_persist_caps_source_content_to_column_width(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );

        self::assertStringContainsString('MAX_SOURCE_CONTENT_CHARS', $src);
        self::assertStringContainsString("'source_content' => \$sourceContent", $src);
        self::assertSame(500, NewContentSuggestionParser::MAX_SOURCE_CONTENT_CHARS);
        self::assertSame(500, NewContentSuggestionParser::MAX_KEYWORD_CHARS);
    }
}
