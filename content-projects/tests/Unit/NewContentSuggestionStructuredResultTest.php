<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionStructuredResult;
use PHPUnit\Framework\TestCase;

final class NewContentSuggestionStructuredResultTest extends TestCase
{
    public function test_accepts_raw_json_array(): void
    {
        $decoded = NewContentSuggestionStructuredResult::decode(
            '[{"keyword":"a","suggested_title":"A"}]',
        );

        self::assertTrue($decoded['ok']);
        self::assertIsArray($decoded['value']);
        self::assertSame('a', $decoded['value'][0]['keyword']);
    }

    public function test_rejects_prose_preamble(): void
    {
        $decoded = NewContentSuggestionStructuredResult::decode(
            "We need to produce JSON array of objects...\n[{\"keyword\":\"a\"}]",
        );

        self::assertFalse($decoded['ok']);
        self::assertSame(NewContentSuggestionStructuredResult::CODE_PROSE, $decoded['code']);
    }

    public function test_detects_truncated_array(): void
    {
        $decoded = NewContentSuggestionStructuredResult::decode(
            '[{"keyword":"a","suggested_title":"A"',
        );

        self::assertFalse($decoded['ok']);
        self::assertSame(NewContentSuggestionStructuredResult::CODE_INCOMPLETE, $decoded['code']);
    }

    public function test_strips_one_markdown_fence_only(): void
    {
        $decoded = NewContentSuggestionStructuredResult::decode(
            "```json\n[{\"keyword\":\"x\",\"suggested_title\":\"X\"}]\n```",
        );

        self::assertTrue($decoded['ok']);
        self::assertSame('x', $decoded['value'][0]['keyword']);
    }

    public function test_unwraps_double_encoded_json_string(): void
    {
        $inner = json_encode([['keyword' => 'y', 'suggested_title' => 'Y']], JSON_UNESCAPED_UNICODE);
        $wrapped = json_encode($inner, JSON_UNESCAPED_UNICODE);
        self::assertIsString($wrapped);

        $decoded = NewContentSuggestionStructuredResult::decode($wrapped);

        self::assertTrue($decoded['ok']);
        self::assertSame('y', $decoded['value'][0]['keyword']);
    }

    public function test_output_contract_footer_is_mode_aware(): void
    {
        $post = NewContentSuggestionStructuredResult::outputContractFooter('post', 5);
        $product = NewContentSuggestionStructuredResult::outputContractFooter('product', 5);

        self::assertStringContainsString('Mode: POST', $post);
        self::assertStringContainsString('FIRST non-whitespace character', $post);
        self::assertStringNotContainsString('Mode: PRODUCT', $post);

        self::assertStringContainsString('Mode: PRODUCT', $product);
        self::assertStringContainsString('"product_type"', $product);
        self::assertStringContainsString('"gallery_description"', $product);
    }

    public function test_repair_brief_is_format_only(): void
    {
        $brief = NewContentSuggestionStructuredResult::repairBrief('not json', 'product', 3);

        self::assertStringContainsString('REPAIR TASK — FORMAT ONLY', $brief);
        self::assertStringContainsString('Do not add new suggestions', $brief);
        self::assertStringContainsString('INVALID PREVIOUS RESPONSE:', $brief);
        self::assertStringContainsString('not json', $brief);
    }
}
