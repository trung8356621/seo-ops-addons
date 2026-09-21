<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentKeywordFallback;
use Omnichannel\Addons\Content\Support\InternalLinkFinalAnchorReconstructor;
use ReflectionClass;
use Tests\TestCase;

/**
 * SEARCH PROBE ≠ FINAL ANCHOR — reconstruction contracts A–G.
 */
final class ArticleInternalLinkFinalAnchorTest extends TestCase
{
    public function test_a_thiet_ke_va_never_emitted_as_final_anchor(): void
    {
        $html = '<p>Thiết kế và chất liệu là hai yếu tố quan trọng khi chọn túi.</p>';

        self::assertFalse(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor('Thiết kế và', $html, $html)
        );

        $recon = InternalLinkFinalAnchorReconstructor::reconstruct($html, 'Thiết kế và');
        if ($recon !== null) {
            self::assertNotSame('Thiết kế và', $recon['anchor']);
            self::assertFalse(
                InternalLinkFinalAnchorReconstructor::hasConnectorBoundary($recon['anchor'])
            );
        }
    }

    public function test_b_may_reconstruct_ngan_chua_thong_minh(): void
    {
        $html = '<p>Hệ thống ngăn chứa thông minh giúp tăng tính linh hoạt khi mang theo.</p>';

        $recon = InternalLinkFinalAnchorReconstructor::reconstruct($html, 'chứa thông');

        self::assertNotNull($recon);
        self::assertSame('ngăn chứa thông minh', $recon['anchor']);
        self::assertTrue(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor(
                $recon['anchor'],
                strip_tags($html),
            )
        );
    }

    public function test_c_malformed_cross_token_windows_rejected(): void
    {
        $plain = 'Hệ thống ngăn chứa thông minh giúp tăng tính linh hoạt và khả năng ứng dụng nổi bật.';

        foreach (['dụng linh', 'chứa thông', 'năng ứng', 'năng nổi'] as $bad) {
            self::assertTrue(
                InternalLinkFinalAnchorReconstructor::looksLikeBrokenTokenWindow($bad),
                $bad.' should look broken'
            );
            self::assertFalse(
                InternalLinkFinalAnchorReconstructor::isValidFinalAnchor($bad, $plain),
                $bad.' must not be a final anchor'
            );
        }

        self::assertTrue(InternalLinkFinalAnchorReconstructor::hasIncompleteEdgeToken('tối ưu hóa ngân'));
        self::assertTrue(InternalLinkFinalAnchorReconstructor::hasIncompleteEdgeToken('năng nổi bật'));
        self::assertFalse(InternalLinkFinalAnchorReconstructor::hasIncompleteEdgeToken('Polyester 600D'));
    }

    public function test_d_polyester_600d_remains_valid(): void
    {
        $html = '<p>Chất liệu Polyester 600D bền chắc, chống thấm tốt.</p>';

        $recon = InternalLinkFinalAnchorReconstructor::reconstruct($html, 'Polyester 600D');

        self::assertNotNull($recon);
        self::assertSame('Polyester 600D', $recon['anchor']);
        self::assertTrue(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor('Polyester 600D', strip_tags($html))
        );
    }

    public function test_e_time_and_measurement_cannot_be_final_anchors(): void
    {
        $plain = 'Giờ làm việc 8h - 17h15. Kích thước 45 x 30 x 15 cm.';

        self::assertTrue(InternalLinkFinalAnchorReconstructor::looksLikeTimeOrMeasurement('8h - 17h15'));
        self::assertTrue(InternalLinkFinalAnchorReconstructor::looksLikeTimeOrMeasurement('45 x 30 x 15 cm'));
        self::assertFalse(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor('8h - 17h15', $plain)
        );
        self::assertFalse(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor('45 x 30 x 15 cm', $plain)
        );
    }

    public function test_f_final_anchor_must_occur_contiguously_in_source(): void
    {
        $html = '<p>Hệ thống ngăn chứa thông minh giúp tăng tính linh hoạt.</p>';
        $plain = strip_tags($html);

        $recon = InternalLinkFinalAnchorReconstructor::reconstruct($html, 'linh hoạt');
        self::assertNotNull($recon);
        self::assertTrue(
            \Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher::contains(
                $plain,
                $recon['anchor'],
            )
        );

        // Invented non-contiguous mash-up must fail.
        self::assertFalse(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor('hệ thống linh hoạt', $plain)
        );
    }

    public function test_g_progressive_probe_final_separation_still_wired(): void
    {
        $fallbackSrc = (string) file_get_contents(
            (new ReflectionClass(ArticleLinkSuggestionContentKeywordFallback::class))->getFileName()
        );
        self::assertStringContainsString('InternalLinkFinalAnchorReconstructor::reconstruct', $fallbackSrc);
        self::assertStringContainsString("'probe_phrase' => \$probePhrase", $fallbackSrc);
        self::assertStringContainsString('rejected_anchor_reconstruction', $fallbackSrc);

        $order = ArticleInternalLinkPipeline::ADVANCED_STAGE_ORDER;
        self::assertContains(ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP, $order);
        self::assertContains(ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP_EXTENDED, $order);
        self::assertContains(ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP_DEEPER, $order);
    }

    public function test_tinh_linh_hoat_reconstruction_from_probe(): void
    {
        $html = '<p>Hệ thống ngăn chứa thông minh giúp tăng tính linh hoạt khi mang.</p>';
        $recon = InternalLinkFinalAnchorReconstructor::reconstruct($html, 'dụng linh');
        // "dụng linh" may not occur — if probe missing, null is OK.
        if ($recon !== null) {
            self::assertNotSame('dụng linh', $recon['anchor']);
        }

        $fromLinh = InternalLinkFinalAnchorReconstructor::reconstruct($html, 'linh hoạt');
        self::assertNotNull($fromLinh);
        self::assertTrue(in_array($fromLinh['anchor'], ['linh hoạt', 'tính linh hoạt'], true));
    }
}
