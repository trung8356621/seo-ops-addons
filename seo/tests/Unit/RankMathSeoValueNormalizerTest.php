<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Support\RankMathSeoValueNormalizer;
use PHPUnit\Framework\TestCase;

final class RankMathSeoValueNormalizerTest extends TestCase
{
    public function test_normalize_title_returns_null_for_empty_value(): void
    {
        $this->assertNull(RankMathSeoValueNormalizer::normalizeTitle(''));
        $this->assertNull(RankMathSeoValueNormalizer::normalizeTitle('   '));
    }

    public function test_normalize_title_returns_null_for_rank_math_template(): void
    {
        $this->assertNull(RankMathSeoValueNormalizer::normalizeTitle('%title% %sep% %sitename%'));
        $this->assertNull(RankMathSeoValueNormalizer::normalizeTitle('%title%'));
    }

    public function test_normalize_title_keeps_plain_text(): void
    {
        $this->assertSame(
            'Túi Xách Du Lịch Nam GB-DL05',
            RankMathSeoValueNormalizer::normalizeTitle('Túi Xách Du Lịch Nam GB-DL05'),
        );
    }

    public function test_normalize_title_rejects_bogus_markdown_labels(): void
    {
        $this->assertNull(RankMathSeoValueNormalizer::normalizeTitle('Meta Description'));
        $this->assertTrue(RankMathSeoValueNormalizer::isBogusSeoTitleLabel('Meta Description'));
    }

    public function test_normalize_slug_rejects_rank_math_template(): void
    {
        $this->assertNull(RankMathSeoValueNormalizer::normalizeSlug('%slug%'));
        $this->assertSame('tui-xach-nam', RankMathSeoValueNormalizer::normalizeSlug('tui-xach-nam'));
    }
}
