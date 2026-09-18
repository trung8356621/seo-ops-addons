<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\EditorAnchorOccurrenceMatcher;
use PHPUnit\Framework\TestCase;

final class EditorAnchorOccurrenceMatcherTest extends TestCase
{
    public function test_case_a_no_body_occurrence_removes_suggestion(): void
    {
        $out = EditorAnchorOccurrenceMatcher::filterActionable(
            [
                [
                    'text' => 'May Balo Laptop',
                    'href' => '/may-balo-laptop',
                    'target_url' => '/may-balo-laptop',
                ],
                [
                    'text' => 'balo laptop',
                    'href' => '/may-balo-laptop',
                    'target_url' => '/may-balo-laptop',
                ],
            ],
            '<p>Chúng tôi nhận may balo quà tặng cho doanh nghiệp.</p>',
        );

        self::assertSame([], $out);
    }

    public function test_case_b_alias_occurrence_becomes_suggestion_text(): void
    {
        $out = EditorAnchorOccurrenceMatcher::filterActionable(
            [
                [
                    'text' => 'May Balo Laptop',
                    'href' => '/may-balo-laptop',
                    'target_url' => '/may-balo-laptop',
                    'source' => 'product_cat',
                ],
                [
                    'text' => 'balo laptop',
                    'href' => '/may-balo-laptop',
                    'target_url' => '/may-balo-laptop',
                    'source' => 'custom',
                ],
            ],
            '<p>Các mẫu balo laptop phù hợp cho nhân viên văn phòng.</p>',
        );

        self::assertCount(1, $out);
        self::assertSame('balo laptop', $out[0]['text']);
        self::assertSame('/may-balo-laptop', $out[0]['href']);
        self::assertSame('/may-balo-laptop', $out[0]['target_url']);
    }

    public function test_case_c_longest_match_wins_for_overlapping_range(): void
    {
        $out = EditorAnchorOccurrenceMatcher::filterActionable(
            [
                ['text' => 'May Balo Laptop', 'href' => '/may-balo-laptop'],
                ['text' => 'Balo Laptop', 'href' => '/may-balo-laptop'],
                ['text' => 'Laptop', 'href' => '/may-balo-laptop'],
            ],
            '<p>Dịch vụ May Balo Laptop được thực hiện theo yêu cầu.</p>',
        );

        self::assertCount(1, $out);
        self::assertSame('May Balo Laptop', $out[0]['text']);
    }

    public function test_case_d_shorter_phrase_survives_separate_occurrence(): void
    {
        $out = EditorAnchorOccurrenceMatcher::filterActionable(
            [
                ['text' => 'may balo laptop', 'href' => '/may-balo-laptop'],
                ['text' => 'balo laptop', 'href' => '/may-balo-laptop'],
            ],
            '<p>Dịch vụ may balo laptop dành cho doanh nghiệp. Các mẫu balo laptop có nhiều kích thước.</p>',
        );

        $texts = array_map(static fn (array $row): string => (string) $row['text'], $out);
        sort($texts);

        self::assertSame(['balo laptop', 'may balo laptop'], $texts);
        self::assertSame('/may-balo-laptop', $out[0]['href']);
        self::assertSame('/may-balo-laptop', $out[1]['href']);
    }

    public function test_case_e_already_linked_anchor_excluded(): void
    {
        $out = EditorAnchorOccurrenceMatcher::filterActionable(
            [
                ['text' => 'balo laptop', 'href' => '/may-balo-laptop'],
            ],
            '<p>Xem <a href="/old">balo laptop</a> tại đây.</p>',
        );

        self::assertSame([], $out);
    }

    public function test_case_f_case_insensitive_preserves_article_casing(): void
    {
        $out = EditorAnchorOccurrenceMatcher::filterActionable(
            [
                ['text' => 'May Balo Laptop', 'href' => '/may-balo-laptop'],
            ],
            '<p>Dịch vụ MAY BALO LAPTOP theo yêu cầu.</p>',
        );

        self::assertCount(1, $out);
        self::assertSame('MAY BALO LAPTOP', $out[0]['text']);
        self::assertSame('/may-balo-laptop', $out[0]['href']);
    }

    public function test_duplicate_phrase_occurrences_do_not_create_duplicate_rows(): void
    {
        $out = EditorAnchorOccurrenceMatcher::filterActionable(
            [
                ['text' => 'balo laptop', 'href' => '/may-balo-laptop'],
            ],
            '<p>Một balo laptop. Hai balo laptop. Ba balo laptop.</p>',
        );

        self::assertCount(1, $out);
        self::assertSame('balo laptop', $out[0]['text']);
    }

    public function test_unicode_normalization_matches_vietnamese_nfc(): void
    {
        // "túi" as precomposed vs combining sequences — Normalizer FORM_C + KeywordPhraseMatcher.
        $precomposed = 'túi giữ nhiệt';
        $out = EditorAnchorOccurrenceMatcher::filterActionable(
            [
                ['text' => $precomposed, 'href' => '/tui-giu-nhiet'],
            ],
            '<p>Các mẫu '.$precomposed.' cao cấp.</p>',
        );

        self::assertCount(1, $out);
        self::assertSame($precomposed, $out[0]['text']);
        self::assertSame('/tui-giu-nhiet', $out[0]['href']);
    }
}
