<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\OutlineSkipListMatcher;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use PHPUnit\Framework\TestCase;

final class CtaKeywordBlacklistFilterTest extends TestCase
{
    private OutlineSkipListMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = new OutlineSkipListMatcher;
    }

    public function test_matches_exact_phrase_case_insensitive(): void
    {
        $this->assertTrue(CtaKeywordBlacklistFilter::matchesPhrase(
            'Tại ĐÂY',
            ['tại đây'],
            $this->matcher,
        ));
    }

    public function test_matches_contains_fragment(): void
    {
        $this->assertTrue(CtaKeywordBlacklistFilter::matchesPhrase(
            'Yêu cầu catalogue mẫu miễn phí tại đây',
            ['tại đây', 'catalogue mẫu'],
            $this->matcher,
        ));
    }

    public function test_supports_sql_prefix_pattern(): void
    {
        $this->assertTrue(CtaKeywordBlacklistFilter::matchesPhrase(
            'Liên hệ ngay với Hợp Phát',
            ['liên hệ%'],
            $this->matcher,
        ));
    }

    public function test_prefix_pattern_is_case_insensitive_for_keyword_phrase(): void
    {
        $this->assertTrue(CtaKeywordBlacklistFilter::matchesPhrase(
            'Báo giá in túi vải không dệt hột xoài',
            ['báo giá%'],
            $this->matcher,
        ));

        $this->assertTrue(CtaKeywordBlacklistFilter::matchesPhrase(
            'Báo giá in túi vải không dệt hột xoài',
            ['BÁO GIÁ%'],
            $this->matcher,
        ));
    }

    public function test_does_not_match_unrelated_phrase(): void
    {
        $this->assertFalse(CtaKeywordBlacklistFilter::matchesPhrase(
            'balo quảng cáo',
            ['tại đây', 'click vào'],
            $this->matcher,
        ));
    }

    public function test_decodes_html_entities_before_match(): void
    {
        $this->assertTrue(CtaKeywordBlacklistFilter::matchesPhrase(
            'In túi vải &amp; balo tại đây',
            ['tại đây'],
            $this->matcher,
        ));
    }

    public function test_matching_blacklist_entries_returns_original_rules(): void
    {
        $matches = CtaKeywordBlacklistFilter::matchingBlacklistEntries(
            'Yêu cầu catalogue mẫu miễn phí tại đây',
            ['tại đây', 'catalogue mẫu', 'liên hệ%'],
            $this->matcher,
        );

        $this->assertSame(['tại đây', 'catalogue mẫu'], $matches);
    }
}
