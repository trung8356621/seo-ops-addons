<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentKeywordFallback;
use Omnichannel\Addons\Content\Support\InternalLinkFinalAnchorReconstructor;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use ReflectionClass;
use Tests\TestCase;

/**
 * SEARCH PROBE ≠ FINAL ANCHOR — reconstruction contracts + locality robustness.
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
                strip_tags($html),
            )
        );
    }

    public function test_c_malformed_cross_token_windows_rejected(): void
    {
        $plain = 'Người dùng nên sử dụng linh hoạt, tận dụng tính năng nổi bật, khả năng ứng dụng rộng và hệ thống ngăn chứa thông minh.';

        foreach (['dụng linh', 'chứa thông', 'năng ứng', 'năng nổi'] as $bad) {
            self::assertTrue(
                KeywordPhraseMatcher::contains($plain, $bad),
                $bad.' must appear in fixture'
            );
            self::assertTrue(
                InternalLinkFinalAnchorReconstructor::looksLikeBrokenTokenWindow($bad, $plain),
                $bad.' should look broken in context'
            );

            $html = '<p>'.$plain.'</p>';
            $recon = InternalLinkFinalAnchorReconstructor::reconstruct($html, $bad);
            if ($recon !== null) {
                self::assertNotSame($bad, $recon['anchor'], $bad.' must not be final text');
            }
        }

        self::assertTrue(InternalLinkFinalAnchorReconstructor::hasConnectorBoundary('Thiết kế và'));
        self::assertFalse(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor('8h - 17h15', 'Giờ 8h - 17h15')
        );
        self::assertFalse(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor('Thiết kế và', $plain, $plain)
        );
    }

    public function test_valid_phrases_starting_with_former_blacklist_tokens(): void
    {
        $plain = 'Pin năng lượng mặt trời và các ứng dụng AI giúp chứa đồ cá nhân cùng dụng cụ học tập tạo sự khác biệt.';

        // Lexical start-token blacklist must not flag these as broken windows.
        foreach (['ứng dụng AI', 'năng lượng mặt trời', 'chứa đồ cá nhân', 'dụng cụ học tập', 'sự khác biệt'] as $good) {
            self::assertFalse(
                InternalLinkFinalAnchorReconstructor::looksLikeBrokenTokenWindow($good, $plain),
                $good.' must not be treated as a mid-window fragment'
            );
            self::assertFalse(
                InternalLinkFinalAnchorReconstructor::hasConnectorBoundary($good),
                $good.' must not fail connector-boundary'
            );
        }

        // Full structural+quality gate for phrases quality already accepts.
        foreach (['năng lượng mặt trời', 'chứa đồ cá nhân', 'sự khác biệt'] as $good) {
            self::assertTrue(
                InternalLinkFinalAnchorReconstructor::isValidFinalAnchor($good, $plain, $plain),
                $good.' must remain a valid final anchor'
            );
        }
    }

    public function test_nang_ung_may_reconstruct_to_local_kha_nang_ung_dung(): void
    {
        $html = '<p>Sản phẩm có khả năng ứng dụng linh hoạt trong nhiều môi trường.</p>';

        self::assertTrue(
            InternalLinkFinalAnchorReconstructor::looksLikeBrokenTokenWindow(
                'năng ứng',
                strip_tags($html),
            )
        );

        $recon = InternalLinkFinalAnchorReconstructor::reconstruct($html, 'năng ứng');
        self::assertNotNull($recon);
        self::assertNotSame('năng ứng', $recon['anchor']);
        self::assertTrue(
            KeywordPhraseMatcher::contains($recon['anchor'], 'ứng dụng')
            || KeywordPhraseMatcher::contains('khả năng ứng dụng', $recon['anchor'])
            || $recon['anchor'] === 'khả năng ứng dụng'
            || $recon['anchor'] === 'khả năng ứng dụng linh hoạt'
        );
    }

    public function test_destination_focus_only_elsewhere_must_not_replace_local_probe(): void
    {
        $html = '<p>Đoạn này nói về khả năng ứng dụng linh hoạt của sản phẩm.</p>'
            .'<p>Riêng đoạn sau đề cập năng lượng mặt trời cho dự án xanh.</p>';

        $recon = InternalLinkFinalAnchorReconstructor::reconstruct($html, 'năng ứng', [
            'destination_focus_keyword' => 'năng lượng mặt trời',
        ]);

        self::assertNotNull($recon);
        self::assertNotSame('năng lượng mặt trời', $recon['anchor']);
        self::assertNotSame(
            InternalLinkFinalAnchorReconstructor::REASON_DESTINATION_FOCUS,
            $recon['reason'],
        );
    }

    public function test_destination_focus_in_same_block_may_be_preferred(): void
    {
        $html = '<p>Khả năng ứng dụng và năng lượng mặt trời đều được tối ưu trên thiết bị này.</p>';

        $recon = InternalLinkFinalAnchorReconstructor::reconstruct($html, 'năng ứng', [
            'destination_focus_keyword' => 'năng lượng mặt trời',
        ]);

        self::assertNotNull($recon);
        self::assertSame('năng lượng mặt trời', $recon['anchor']);
        self::assertSame(
            InternalLinkFinalAnchorReconstructor::REASON_DESTINATION_FOCUS,
            $recon['reason'],
        );
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
        self::assertTrue(KeywordPhraseMatcher::contains($plain, $recon['anchor']));

        self::assertFalse(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor('hệ thống linh hoạt', $plain)
        );
    }

    public function test_block_locality_is_enforced_when_block_provided(): void
    {
        $plain = 'Đoạn A có túi xách. Đoạn B có năng lượng mặt trời.';
        $block = 'Đoạn A có túi xách.';

        self::assertTrue(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor('túi xách', $plain, $block)
        );
        self::assertFalse(
            InternalLinkFinalAnchorReconstructor::isValidFinalAnchor('năng lượng mặt trời', $plain, $block)
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
        if ($recon !== null) {
            self::assertNotSame('dụng linh', $recon['anchor']);
        }

        $fromLinh = InternalLinkFinalAnchorReconstructor::reconstruct($html, 'linh hoạt');
        self::assertNotNull($fromLinh);
        self::assertTrue(in_array($fromLinh['anchor'], ['linh hoạt', 'tính linh hoạt'], true));
    }
}
