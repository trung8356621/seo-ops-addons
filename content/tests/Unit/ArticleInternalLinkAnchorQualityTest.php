<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use Omnichannel\Addons\Content\Support\InternalLinkAnchorQuality;
use Omnichannel\Addons\Content\Support\InternalLinkFocusRelevance;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;
use Tests\TestCase;

/**
 * Advanced content_deep — anchor quality gate + focus-keyword destination ranking.
 */
final class ArticleInternalLinkAnchorQualityTest extends TestCase
{
    private ArticleInternalLinkSearchService $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = app(ArticleInternalLinkSearchService::class);
    }

    public function test_a_generic_anchor_ly_do_is_rejected(): void
    {
        $anchor = InternalLinkAnchorQuality::evaluate('Lý do');

        self::assertFalse($anchor['accepted']);
        self::assertSame(InternalLinkAnchorQuality::REASON_GENERIC_ABSTRACT, $anchor['reason']);
        self::assertLessThan(InternalLinkAnchorQuality::MIN_ACCEPT, $anchor['score']);
    }

    public function test_d_generic_title_hit_does_not_rescue_poor_anchor(): void
    {
        $anchor = InternalLinkAnchorQuality::evaluate('Lý do');
        $dest = $this->search->scoreLikeFallbackRelevance(
            'Lý do',
            'Lý do chọn túi xách doanh nghiệp',
            'ly-do-chon-tui-xach',
            'túi xách doanh nghiệp',
        );

        // Destination may score via title contains — still must not accept the anchor.
        self::assertFalse($anchor['accepted']);
        self::assertGreaterThanOrEqual(LinkSuggestionScoreScale::TITLE_CONTAINS, $dest['score']);
    }

    public function test_b_focus_keyword_exact_outranks_title_contains(): void
    {
        $focusExact = $this->search->scoreLikeFallbackRelevance(
            'Vải Polyester Nguyên Sinh',
            'Vải Polyester Nguyên Sinh Là Gì? Vì Sao Các Xưởng May Balo Hàng Đầu Ưu Tiên Sử Dụng?',
            'vai-polyester-nguyen-sinh',
            'Vải Polyester Nguyên Sinh',
        );
        $titleOnly = $this->search->scoreLikeFallbackRelevance(
            'Vải Polyester Nguyên Sinh',
            'Vải Polyester Nguyên Sinh Là Gì? Vì Sao Các Xưởng May Balo Hàng Đầu Ưu Tiên Sử Dụng?',
            'vai-polyester-nguyen-sinh',
            '',
        );

        self::assertSame(LinkSuggestionScoreScale::FOCUS_KEYWORD, $focusExact['score']);
        self::assertSame(ArticleInternalLinkSearchService::REASON_FOCUS_KEYWORD, $focusExact['match_reason']);
        self::assertSame(ArticleInternalLinkSearchService::REASON_TITLE_BOUNDARY, $titleOnly['match_reason']);
        self::assertTrue($focusExact['score'] > $titleOnly['score']);
    }

    public function test_c_focus_keyword_overlap_polyester_600d(): void
    {
        $scored = $this->search->scoreLikeFallbackRelevance(
            'Polyester 600D',
            'Vải Polyester Nguyên Sinh Là Gì?',
            'vai-polyester-nguyen-sinh',
            'Vải Polyester Nguyên Sinh',
        );

        self::assertSame(LinkSuggestionScoreScale::FOCUS_OVERLAP, $scored['score']);
        self::assertSame(ArticleInternalLinkSearchService::REASON_FOCUS_OVERLAP, $scored['match_reason']);
        self::assertGreaterThanOrEqual(LinkSuggestionScoreScale::fallbackMinAccept(), $scored['score']);

        $overlap = InternalLinkFocusRelevance::overlapScore(
            'Polyester 600D',
            'Vải Polyester Nguyên Sinh',
        );
        self::assertSame(LinkSuggestionScoreScale::FOCUS_OVERLAP, $overlap);
    }

    public function test_e_strong_entity_anchor_passes_without_focus_keyword(): void
    {
        foreach (['Túi xách', 'Polyester 600D', 'chất liệu Polyester', 'túi xách quà tặng', 'logo cá nhân hóa'] as $phrase) {
            $anchor = InternalLinkAnchorQuality::evaluate($phrase, ['source' => 'strong_window']);
            self::assertTrue($anchor['accepted'], 'expected pass: '.$phrase.' got '.json_encode($anchor));
        }

        $titleHit = $this->search->scoreLikeFallbackRelevance(
            'Túi xách',
            'Cách chọn túi xách quà tặng doanh nghiệp',
            'cach-chon-tui-xach',
            '',
        );
        self::assertGreaterThanOrEqual(LinkSuggestionScoreScale::fallbackMinAccept(), $titleHit['score']);
    }

    public function test_noi_bat_generic_anchor_rejected(): void
    {
        $anchor = InternalLinkAnchorQuality::evaluate('nổi bật');

        self::assertFalse($anchor['accepted']);
        self::assertSame(InternalLinkAnchorQuality::REASON_GENERIC_ABSTRACT, $anchor['reason']);
    }

    public function test_inventory_keyword_boosts_borderline_anchor(): void
    {
        $plain = InternalLinkAnchorQuality::evaluate('Độ bền');
        $boosted = InternalLinkAnchorQuality::evaluate('Độ bền', [
            'is_inventory_keyword' => true,
            'source' => 'keyword',
        ]);

        self::assertTrue($boosted['accepted']);
        self::assertGreaterThan($plain['score'], $boosted['score']);
    }
}
