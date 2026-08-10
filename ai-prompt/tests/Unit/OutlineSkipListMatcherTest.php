<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\OutlineSkipListMatcher;
use PHPUnit\Framework\TestCase;

class OutlineSkipListMatcherTest extends TestCase
{
    private OutlineSkipListMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new OutlineSkipListMatcher;
    }

    public function test_normalize_wraps_plain_text_with_wildcards(): void
    {
        $this->assertSame(
            ['%giới thiệu%', '%FAQ%'],
            $this->matcher->normalizeSqlPatterns(['giới thiệu', 'FAQ']),
        );
    }

    public function test_normalize_preserves_user_sql_wildcards(): void
    {
        $this->assertSame(
            ['So sánh%', '%Kết luận'],
            $this->matcher->normalizeSqlPatterns(['So sánh%', '%Kết luận']),
        );
    }

    public function test_is_skipped_layer_one_with_str_is(): void
    {
        $patterns = $this->matcher->normalizeSqlPatterns(['So sánh%', 'giới thiệu']);

        $this->assertTrue($this->matcher->isSkipped('So Sánh Cặp Học Sinh', $patterns));
        $this->assertTrue($this->matcher->isSkipped('Giới thiệu về sản phẩm', $patterns));
        $this->assertFalse($this->matcher->isSkipped('Quy trình sản xuất', $patterns));
    }

    public function test_matches_sql_like_pattern_is_case_insensitive(): void
    {
        $this->assertTrue($this->matcher->matchesSqlLikePattern(
            'Báo giá in túi vải',
            'báo giá%',
        ));

        $this->assertTrue($this->matcher->matchesSqlLikePattern(
            'Phối theo phong cách All-Black: điểm nhấn rực rỡ',
            '%NHẤN%',
        ));
    }

    public function test_is_skipped_ignores_leading_numbering(): void
    {
        $patterns = $this->matcher->normalizeSqlPatterns(['So sánh%', 'Bảng so sánh%']);

        $this->assertTrue($this->matcher->isSkipped('3. So sánh các mẫu cặp học sinh Cự Giải', $patterns));
        $this->assertTrue($this->matcher->isSkipped('2.1 Bảng so sánh các dòng cặp học sinh', $patterns));
        $this->assertTrue($this->matcher->isSkipped('- So sánh nhanh', $patterns));
        $this->assertFalse($this->matcher->isSkipped('3. Hướng dẫn bảo quản cặp sách', $patterns));
    }

    public function test_strip_leading_noise(): void
    {
        $this->assertSame('so sánh abc', $this->matcher->stripLeadingNoise('3. so sánh abc'));
        $this->assertSame('bảng so sánh', $this->matcher->stripLeadingNoise('1.2) bảng so sánh'));
        $this->assertSame('kết luận', $this->matcher->stripLeadingNoise('kết luận'));
    }
}
