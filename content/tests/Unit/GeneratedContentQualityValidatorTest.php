<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\DataTransfer\GeneratedContentQualityResult;
use Omnichannel\Addons\Content\Services\GeneratedContentQualityValidator;
use PHPUnit\Framework\TestCase;

final class GeneratedContentQualityValidatorTest extends TestCase
{
    private function validator(): GeneratedContentQualityValidator
    {
        return new GeneratedContentQualityValidator;
    }

    private function vi(string $content, bool $isHtml = false): GeneratedContentQualityResult
    {
        return $this->validator()->validate($content, [
            'language' => 'vi',
            'is_html' => $isHtml,
        ]);
    }

    public function test_vietnamese_normal_and_accents_and_emoji_pass(): void
    {
        self::assertTrue($this->vi('Túi vải không dệt có độ bền cao và có thể tái sử dụng nhiều lần.')->passed);
        self::assertTrue($this->vi('Độ bền, khả năng chống nước và tính ứng dụng.')->passed);
        self::assertTrue($this->vi("✅ Độ bền cao\n🎒 Phù hợp cho học sinh")->passed);
    }

    public function test_unexpected_cjk_corruption_rejects(): void
    {
        $result = $this->vi('Viền may đôi, 補強 góc giúp túi chắc chắn hơn.');
        self::assertFalse($result->passed);
        self::assertContains('unexpected_script', $result->rejectRules());
        self::assertStringContainsString('補強', $result->primarySample());
    }

    public function test_multiple_foreign_script_clusters_reject(): void
    {
        $result = $this->vi('Viền may 補強 chắc chắn. Góc đáy 補強 thêm một lớp vải.');
        self::assertFalse($result->passed);
        self::assertContains('unexpected_script', $result->rejectRules());
    }

    public function test_legit_brand_entity_does_not_reject(): void
    {
        $result = $this->vi('Điện thoại Xiaomi 小米 rất phổ biến tại Việt Nam.');
        self::assertTrue($result->passed);
        $severities = array_column($result->issues, 'severity');
        self::assertNotContains(GeneratedContentQualityResult::SEVERITY_REJECT, $severities);
    }

    public function test_replacement_and_control_chars_reject(): void
    {
        $replacement = $this->vi("Chất liệu vải \u{FFFD} cao cấp");
        self::assertFalse($replacement->passed);
        self::assertContains('replacement_char', $replacement->rejectRules());

        $control = $this->vi("Chất liệu vải\x00 cao cấp");
        self::assertFalse($control->passed);
        self::assertContains('control_char', $control->rejectRules());
    }

    public function test_suspicious_dot_warns_without_rewrite(): void
    {
        $input = 'sinh viên.lhọc tập mỗi ngày';
        $result = $this->vi($input);
        self::assertTrue($result->passed);
        self::assertNotEmpty($result->issues);
        self::assertSame('suspicious_dot_glue', $result->issues[0]['rule']);
        self::assertSame(GeneratedContentQualityResult::SEVERITY_WARNING, $result->issues[0]['severity']);
    }

    public function test_urls_domains_emails_numbers_tech_pass(): void
    {
        foreach ([
            'example.com',
            'https://example.com/path',
            'support@example.com',
            '3.14',
            '192.168.1.1',
            'Node.js',
            'Vue.js',
            'app.js',
            'style.css',
            'v1.2.3',
        ] as $sample) {
            self::assertTrue($this->vi($sample)->passed, $sample);
        }
    }

    public function test_html_attributes_and_code_ignored(): void
    {
        $html = '<a href="https://example.com/a.b" data-name="foo.bar">Xem chi tiết</a>';
        self::assertTrue($this->vi($html, true)->passed);

        self::assertTrue($this->vi('<pre>foo.bar()</pre>', true)->passed);
        self::assertTrue($this->vi('<code>object.method</code>', true)->passed);
        self::assertFalse($this->vi('<p>Viền may đôi, 補強 góc.</p>', true)->passed);
    }

    public function test_unknown_locale_skips_aggressive_script_reject(): void
    {
        $result = $this->validator()->validate('Hello 補強 world', [
            'language' => '',
            'is_html' => false,
        ]);
        self::assertTrue($result->passed);
    }
}
