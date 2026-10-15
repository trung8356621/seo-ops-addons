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

    public function test_proper_name_code_is_not_a_quality_tag(): void
    {
        self::assertFalse(KeywordQualityCode::isKnown('SUSPECT_PROPER_NAME'));
        self::assertNotContains('SUSPECT_PROPER_NAME', KeywordQualityCode::all());
        self::assertSame([], $this->classifier->classify('Balo anh văn phạm nguyễn'));
        self::assertSame([], $this->classifier->classify('Balo Adidas nữ'));
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
            'Công ty may ba lô túi xách quận 12',
            'BALO HỌC SINH CÔNG ĐOÀN QUẬN 6',
            'Top 10 balo đẹp',
        ] as $phrase) {
            self::assertNotContains(KeywordQualityCode::TRAILING_SEQUENCE_NUMBER, $this->classifier->classify($phrase), $phrase);
        }

        $once = $this->classifier->classify('Balo học sinh 2');
        self::assertSame($once, $this->classifier->classify('Balo học sinh 2'));
    }

    public function test_proper_name_and_promotional_sentence_heuristics(): void
    {
        self::assertSame([], $this->classifier->classify('Balo Nguyễn Văn An'));
        self::assertSame([], $this->classifier->classify('Balo Adidas nữ'));
        self::assertSame([], $this->classifier->classify('Balo Nike Air Max 90'));
        self::assertSame([], $this->classifier->classify('Nguyễn Văn An'));

        $promo = 'Balo adidas nữ - khẳng định phong cách thời trang cá tính tuổi teen.';
        self::assertContains(KeywordQualityCode::SENTENCE_LIKE, $this->classifier->classify($promo));
        self::assertNotContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('balo học sinh loại nào tốt nhất cho bé đi học mẫu giáo'),
        );
    }

    public function test_structural_sentence_question_and_short_period(): void
    {
        self::assertContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('Balo chống nước, trải nghiệm tuyệt vời cho mỗi chuyến đi'),
        );
        self::assertNotContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('Cách chọn size balo phù hợp theo giới tính và mục đích sử dụng'),
        );
        self::assertNotContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('Balo họa tiết vs trơn: Phong cách nào phù hợp với học sinh?'),
        );
        self::assertContains(KeywordQualityCode::MALFORMED_TEXT, $this->classifier->classify('Hợp Phát.'));
        self::assertSame([], $this->classifier->classify('Balo chống nước 2026'));
        self::assertSame([], $this->classifier->classify('Balo Adidas nữ'));
        self::assertSame([], $this->classifier->classify('bảo dưỡng balo da'));
        self::assertSame([], $this->classifier->classify('các mẫu sản phẩm tại Hợp Phát'));
        self::assertSame([], $this->classifier->classify('cách phối đồ cho tuổi 13'));
        self::assertSame([], $this->classifier->classify('Cặp sách học sinh cấp 3'));
        self::assertContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('Màu sắc balo phù hợp với thương hiệu công ty'),
        );
    }

    public function test_cta_requires_anchor_wording_or_transactional_destination(): void
    {
        self::assertContains(KeywordQualityCode::CTA_ANCHOR, $this->classifier->classify('Liên hệ ngay'));
        self::assertContains(KeywordQualityCode::CTA_ANCHOR, $this->classifier->classify('Nhận báo giá'));
        self::assertNotContains(
            KeywordQualityCode::CTA_ANCHOR,
            $this->classifier->classify('Bắt đầu chiến dịch Marketing túi vải ngay'),
        );
        self::assertContains(
            KeywordQualityCode::CTA_ANCHOR,
            $this->classifier->classify('Bắt đầu chiến dịch Marketing túi vải ngay', false, false, false, true),
        );
        self::assertNotContains(
            KeywordQualityCode::CTA_ANCHOR,
            $this->classifier->classify('Balo học sinh', false, false, false, true),
        );
        self::assertNotContains(
            KeywordQualityCode::CTA_ANCHOR,
            $this->classifier->classify('Công nghệ in Flexo', false, false, false, false),
        );

        $ids = array_fill_keys($this->classifier->transactionalDestinationKeywordIds([
            ['keyword_id' => 1, 'target_external_url' => 'https://xuongmaytuikhongdet.com/lien-he', 'link_type' => 'internal', 'destination_kind' => 'content'],
            ['keyword_id' => 2, 'target_external_url' => 'https://xuongmaytuikhongdet.com/bao-gia', 'link_type' => 'internal', 'destination_kind' => 'content'],
            ['keyword_id' => 3, 'target_external_url' => 'https://xuongmaytuikhongdet.com/', 'link_type' => 'internal', 'destination_kind' => 'content'],
            ['keyword_id' => 4, 'target_external_url' => 'https://xuongmaytuikhongdet.com/cong-nghe-in-flexo', 'link_type' => 'internal', 'destination_kind' => 'content'],
            ['keyword_id' => 5, 'target_external_url' => '', 'link_type' => 'internal', 'destination_kind' => 'content'],
            ['keyword_id' => 6, 'target_external_url' => 'https://xuongmaytuikhongdet.com/', 'link_type' => 'internal', 'destination_kind' => 'content'],
            ['keyword_id' => 6, 'target_external_url' => 'https://xuongmaytuikhongdet.com/tui-vai', 'link_type' => 'internal', 'destination_kind' => 'content'],
            ['keyword_id' => 7, 'target_external_url' => 'https://xuongmaytuikhongdet.com/', 'link_type' => 'internal', 'destination_kind' => 'content'],
            ['keyword_id' => 7, 'target_external_url' => 'https://xuongmaytuikhongdet.com/lien-he', 'link_type' => 'internal', 'destination_kind' => 'content'],
        ]), true);
        self::assertArrayHasKey(1, $ids);
        self::assertArrayHasKey(2, $ids);
        self::assertArrayHasKey(7, $ids);
        self::assertArrayNotHasKey(3, $ids);
        self::assertArrayNotHasKey(4, $ids);
        self::assertArrayNotHasKey(5, $ids);
        self::assertArrayNotHasKey(6, $ids);
    }

    public function test_school_name_truncation_and_punctuation_duplicate(): void
    {
        self::assertNotContains('SUSPECT_PROPER_NAME', $this->classifier->classify('Balo trường mầm non Happy Kids'));
        self::assertNotContains('SUSPECT_PROPER_NAME', $this->classifier->classify('may balo trung tâm anh ngữ'));

        $normalized = [
            3 => $this->classifier->normalized('Xưởng may balo trẻ em Hợp Phát'),
            8 => $this->classifier->normalized('ưởng may balo trẻ em Hợp Phát'),
            9 => $this->classifier->normalized('sửa balo túi xách tại tphcm.'),
            10 => $this->classifier->normalized('sửa balo túi xách tại tphcm'),
        ];
        self::assertContains(8, $this->classifier->truncatedCopyIds($normalized));
        self::assertNotContains(3, $this->classifier->truncatedCopyIds($normalized));
        $duplicates = $this->classifier->duplicateKeywordIds($normalized);
        self::assertContains(10, $duplicates);
        self::assertNotContains(9, $duplicates);
    }

    public function test_recall_flags_uncertain_anchors_and_keeps_product_variants(): void
    {
        self::assertContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('Balo da thật, canvas dày cho bé đi học'),
        );
        self::assertContains(
            KeywordQualityCode::CTA_ANCHOR,
            $this->classifier->classify('Xưởng may balo học sinh mua ngay tại cửa hàng'),
        );
        self::assertContains(KeywordQualityCode::MALFORMED_TEXT, $this->classifier->classify('Balo học sinh | xem thêm'));
        self::assertNotContains(
            KeywordQualityCode::SENTENCE_LIKE,
            $this->classifier->classify('Cách chọn size balo phù hợp theo giới tính và mục đích sử dụng'),
        );
        self::assertSame([], $this->classifier->classify('Balo Adidas nữ'));
        self::assertSame([], $this->classifier->classify('Balo Nike Air Max 90'));

        $copies = $this->classifier->containedCopyIds([
            1 => 'balo học sinh',
            2 => 'balo học sinh cấp 2',
            3 => 'balo học sinh của trường viettel rất đẹp',
            4 => 'làm sao chọn balo học sinh phù hợp với trẻ',
        ]);
        self::assertContains(3, $copies);
        self::assertNotContains(1, $copies);
        self::assertNotContains(2, $copies);
        self::assertNotContains(4, $copies);
    }
}
