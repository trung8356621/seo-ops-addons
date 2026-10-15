<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityCode;
use PHPUnit\Framework\TestCase;

final class KeywordQualityClassifierTest extends TestCase
{
    private KeywordQualityClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new KeywordQualityClassifier;
    }

    public function test_cta_and_generic_anchors(): void
    {
        self::assertSame([KeywordQualityCode::CTA_ANCHOR], $this->classifier->classify('mua ngay'));
        self::assertSame([KeywordQualityCode::CTA_ANCHOR], $this->classifier->classify('click tại đây'));
        self::assertSame([KeywordQualityCode::GENERIC_ANCHOR], $this->classifier->classify('tại đây'));
    }

    public function test_malformed_trailing_comma_is_not_a_sentence(): void
    {
        self::assertSame(
            [KeywordQualityCode::MALFORMED_TEXT],
            $this->classifier->classify('Balo cho trung tâm anh ngữ,'),
        );
    }

    public function test_sentence_like_needs_clause_and_filler(): void
    {
        self::assertContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('Balo bóng rổ, sự lựa chọn hợp lý của người yêu thích thể thao.'),
        );
    }

    public function test_valid_keywords_and_questions_stay_untagged(): void
    {
        self::assertSame([], $this->classifier->classify('balo học sinh'));
        self::assertNotContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('làm sao chọn balo học sinh phù hợp với trẻ tiểu học'),
        );
        self::assertNotContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('balo học sinh loại nào tốt nhất cho bé đi học mẫu giáo'),
        );
        self::assertSame([], $this->classifier->classify('Nguyễn Văn An'));
    }

    public function test_suspect_proper_name_needs_a_name_inside_other_text(): void
    {
        self::assertContains(
            KeywordQualityCode::SUSPECT_PROPER_NAME,
            $this->classifier->classify('Balo anh văn phạm nguyễn'),
        );
        self::assertNotContains(
            KeywordQualityCode::SUSPECT_PROPER_NAME,
            $this->classifier->classify('balo học sinh'),
        );
    }

    public function test_encoding_error_and_duplicate_ids(): void
    {
        self::assertContains(KeywordQualityCode::ENCODING_ERROR, $this->classifier->classify('balo &amp; túi'));
        $normalized = [
            2 => $this->classifier->normalized('Balo Học Sinh'),
            5 => $this->classifier->normalized('balo học sinh'),
            9 => $this->classifier->normalized('túi xách'),
        ];
        self::assertSame([5], $this->classifier->duplicateKeywordIds($normalized));
        self::assertContains(
            KeywordQualityCode::DUPLICATE_VARIANT,
            $this->classifier->classify('balo học sinh', true),
        );
    }

    public function test_trailing_sequence_number_ignores_year_measure_rank_and_model(): void
    {
        foreach ([
            'Ba lô thời trang hợp phát 1',
            'Balo học sinh 2',
            'Balo chống nước 12',
            'Balo quà tặng 3',
        ] as $phrase) {
            self::assertContains(KeywordQualityCode::TRAILING_SEQUENCE_NUMBER, $this->classifier->classify($phrase), $phrase);
        }

        foreach ([
            'Balo học sinh 2026',
            'Balo 15 inch',
            'Balo 20L',
            'Balo Nike Air Max 90',
            'Balo size 12',
            'Top 10 balo đẹp',
        ] as $phrase) {
            self::assertNotContains(KeywordQualityCode::TRAILING_SEQUENCE_NUMBER, $this->classifier->classify($phrase), $phrase);
        }

        $once = $this->classifier->classify('Balo học sinh 2');
        self::assertSame($once, $this->classifier->classify('Balo học sinh 2'));
    }

    public function test_proper_name_and_promotional_sentence_heuristics(): void
    {
        self::assertContains(KeywordQualityCode::SUSPECT_PROPER_NAME, $this->classifier->classify('Balo anh văn phạm nguyễn'));
        self::assertContains(KeywordQualityCode::SUSPECT_PROPER_NAME, $this->classifier->classify('Balo Nguyễn Văn An'));
        self::assertContains(KeywordQualityCode::SUSPECT_PROPER_NAME, $this->classifier->classify('Balo John Smith'));
        self::assertContains(KeywordQualityCode::SUSPECT_PROPER_NAME, $this->classifier->classify('Balo anh văn global'));
        self::assertContains(KeywordQualityCode::SUSPECT_PROPER_NAME, $this->classifier->classify('Balo anh ngữ australia – vietnam'));
        self::assertNotContains(KeywordQualityCode::SUSPECT_PROPER_NAME, $this->classifier->classify('Balo Adidas nữ'));
        self::assertNotContains(KeywordQualityCode::SUSPECT_PROPER_NAME, $this->classifier->classify('Balo Nike Air Max 90'));
        self::assertSame([], $this->classifier->classify('Nguyễn Văn An'));

        $promo = 'Balo adidas nữ - khẳng định phong cách thời trang cá tính tuổi teen.';
        self::assertContains(KeywordQualityCode::SENTENCE_LIKE, $this->classifier->classify($promo));
        self::assertNotContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('balo học sinh loại nào tốt nhất cho bé đi học mẫu giáo'),
        );
    }
}
