<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\AssistantWidgetHealthRules;
use Omnichannel\Addons\Seo\Support\SeoReasonPresentation;
use PHPUnit\Framework\TestCase;

final class SeoReasonPresentationAndAssistantHealthTest extends TestCase
{
    public function test_image_ratio_metrics_exposes_missing_count(): void
    {
        $words = implode(' ', array_fill(0, 1150, 'word'));
        $html = '<p>'.$words.'</p>'
            .'<img src="https://cdn.example/a.jpg" alt="a" />'
            .'<img src="https://cdn.example/b.jpg" alt="b" />';

        $metrics = SeoReasonPresentation::imageRatioMetrics($html);

        self::assertSame(2, $metrics['current_image_count']);
        self::assertSame(6, $metrics['recommended_image_count']);
        self::assertSame(4, $metrics['missing_image_count']);
        self::assertSame(1150, $metrics['current_word_count']);
        self::assertSame(200, $metrics['target_words_per_image']);
    }

    public function test_image_ratio_enough_images_no_missing(): void
    {
        $words = implode(' ', array_fill(0, 1150, 'word'));
        $imgs = '';
        for ($i = 0; $i < 6; $i++) {
            $imgs .= '<img src="https://cdn.example/'.$i.'.jpg" alt="'.$i.'" />';
        }

        $metrics = SeoReasonPresentation::imageRatioMetrics('<p>'.$words.'</p>'.$imgs);

        self::assertSame(6, $metrics['current_image_count']);
        self::assertSame(6, $metrics['recommended_image_count']);
        self::assertSame(0, $metrics['missing_image_count']);
    }

    public function test_image_ratio_five_images_missing_one_for_1150_words(): void
    {
        $words = implode(' ', array_fill(0, 1150, 'word'));
        $imgs = '';
        for ($i = 0; $i < 5; $i++) {
            $imgs .= '<img src="https://cdn.example/'.$i.'.jpg" alt="'.$i.'" />';
        }

        $metrics = SeoReasonPresentation::imageRatioMetrics('<p>'.$words.'</p>'.$imgs);

        self::assertSame(5, $metrics['current_image_count']);
        self::assertSame(6, $metrics['recommended_image_count']);
        self::assertSame(1, $metrics['missing_image_count']);
    }

    public function test_image_ratio_ignores_figcaption_and_alt_inflation(): void
    {
        $words = implode(' ', array_fill(0, 1150, 'word'));
        $html = '<p>'.$words.'</p>'
            .'<figure><img src="https://cdn.example/a.jpg" alt="'.str_repeat('altword ', 80).'" />'
            .'<figcaption>'.str_repeat('caption ', 80).'</figcaption></figure>';

        $metrics = SeoReasonPresentation::imageRatioMetrics($html);

        self::assertSame(1150, $metrics['current_word_count']);
        self::assertSame(6, $metrics['recommended_image_count']);
    }

    public function test_invalid_image_blocks_are_excluded_from_count(): void
    {
        $words = implode(' ', array_fill(0, 500, 'word'));
        $html = '<p>'.$words.'</p>'
            .'<img src="" alt="empty" />'
            .'<img src="https://cdn.example/placeholder-1.jpg" alt="ph" />'
            .'<img src="https://cdn.example/ok.jpg" alt="ok" />';

        $metrics = SeoReasonPresentation::imageRatioMetrics($html);

        self::assertSame(1, $metrics['current_image_count']);
    }

    public function test_content_length_metrics(): void
    {
        $metrics = SeoReasonPresentation::contentLengthMetrics(1150, 1500);

        self::assertSame(1150, $metrics['current_word_count']);
        self::assertSame(1500, $metrics['recommended_word_count']);
        self::assertSame(350, $metrics['missing_word_count']);
    }

    public function test_present_image_ratio_low_vietnamese_without_technical_key(): void
    {
        $presented = SeoReasonPresentation::present('image_ratio_low', [
            'current_image_count' => 5,
            'recommended_image_count' => 6,
            'missing_image_count' => 1,
            'current_word_count' => 1150,
        ], 'vi');

        self::assertStringNotContainsString('image_ratio_low', $presented['summary']);
        self::assertStringContainsString('5', $presented['summary']);
        self::assertStringContainsString('6', $presented['summary']);
        self::assertStringContainsString('1.150', $presented['summary']);
        self::assertNotSame('', $presented['label']);
        self::assertNotSame('', $presented['detail']);
    }

    public function test_present_content_length_low_vietnamese(): void
    {
        $presented = SeoReasonPresentation::present('content_length_low', [
            'current_word_count' => 1150,
            'recommended_word_count' => 1500,
            'missing_word_count' => 350,
        ], 'vi');

        self::assertStringNotContainsString('content_length_low', $presented['summary']);
        self::assertStringContainsString('350', $presented['summary']);
    }

    public function test_safe_fallback_never_returns_snake_case(): void
    {
        $fallback = SeoReasonPresentation::safeFallback('totally_unknown_reason_xyz', 'vi');

        self::assertStringNotContainsString('_', $fallback);
        self::assertNotSame('totally_unknown_reason_xyz', $fallback);
    }

    public function test_links_below_minimum_is_error(): void
    {
        $health = AssistantWidgetHealthRules::buildLinksHealth([
            'internal' => [
                ['href' => 'https://example.com/a'],
                ['href' => 'https://example.com/b'],
                ['href' => 'https://example.com/c'],
                ['href' => 'https://example.com/d'],
            ],
            'external' => [],
        ], 'vi');

        self::assertSame('error', $health['status']);
        self::assertSame(4, $health['item_count']);
        self::assertSame('links_below_minimum', $health['reasons'][0]['code']);
    }

    public function test_links_reach_minimum_clears_error(): void
    {
        $links = [];
        for ($i = 0; $i < 5; $i++) {
            $links[] = ['href' => 'https://example.com/'.$i];
        }

        $health = AssistantWidgetHealthRules::buildLinksHealth([
            'internal' => $links,
            'external' => [],
        ]);

        self::assertSame('success', $health['status']);
        self::assertSame(0, $health['issue_count']);
    }

    public function test_tel_mailto_not_counted_as_valid_links(): void
    {
        $count = AssistantWidgetHealthRules::countValidHttpLinks([
            'internal' => [
                ['href' => 'tel:0909938333'],
                ['href' => 'mailto:a@b.com'],
                ['href' => 'https://example.com/ok'],
            ],
            'external' => [
                ['href' => 'zalo:0909'],
            ],
        ]);

        self::assertSame(1, $count);
    }

    public function test_missing_focus_keyword_seo_error(): void
    {
        $health = AssistantWidgetHealthRules::buildSeoFocusKeywordHealth('');

        self::assertSame('error', $health['status']);
        self::assertSame('focus_keyword_missing', $health['reasons'][0]['code']);
    }

    public function test_locale_files_contain_image_ratio_and_content_length_keys(): void
    {
        $vi = SeoReasonPresentation::resolvedSeoRulesLines('vi');
        $en = SeoReasonPresentation::resolvedSeoRulesLines('en');

        foreach (['content_length_low', 'content_length_low_label', 'content_length_low_detail', 'image_ratio_low', 'image_ratio_low_label', 'image_ratio_low_detail'] as $key) {
            self::assertArrayHasKey($key, $vi);
            self::assertArrayHasKey($key, $en);
            self::assertIsString($vi[$key]);
            self::assertIsString($en[$key]);
            self::assertStringNotContainsString($key, $vi[$key]);
        }

        // Soft image-ratio copy uses current/recommended/words (not "Thiếu :missing").
        self::assertStringContainsString(':current', $vi['image_ratio_low']);
        self::assertStringContainsString(':recommended', $vi['image_ratio_low']);
        self::assertStringContainsString(':words', $vi['image_ratio_low']);
        self::assertStringContainsString(':current', $en['image_ratio_low']);
        self::assertStringContainsString(':recommended', $en['image_ratio_low']);
        self::assertStringContainsString(':current', $vi['image_ratio_low_detail']);
        self::assertStringContainsString(':recommended', $vi['content_length_low_detail']);
        self::assertStringContainsString(':missing', $vi['content_length_low']);
    }
}
