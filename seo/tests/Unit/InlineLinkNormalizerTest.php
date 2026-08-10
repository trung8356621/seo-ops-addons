<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\InlineLinkNormalizer;
use PHPUnit\Framework\TestCase;

final class InlineLinkNormalizerTest extends TestCase
{
    private InlineLinkNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new InlineLinkNormalizer;
    }

    public function test_case_a_merges_plain_and_strong_same_url(): void
    {
        $input = '<p><a href="https://example.com/x">công nghệ</a><strong><a href="https://example.com/x">DWR</a></strong></p>';
        $out = $this->normalizer->normalize($input);

        $this->assertSame(1, substr_count(strtolower($out), '<a '));
        $this->assertStringContainsString('<strong>DWR</strong>', $out);
        $this->assertStringContainsString('công nghệ', $out);
        $this->assertStringNotContainsString('<strong><a', $out);
    }

    public function test_case_b_idempotent_roundtrip(): void
    {
        $good = '<p>Đây là <a href="https://example.com/x">công nghệ <strong>DWR</strong></a> cao cấp.</p>';
        $once = $this->normalizer->normalize($good);
        $twice = $this->normalizer->normalize($once);
        $thrice = $this->normalizer->normalize($twice);

        $this->assertSame($once, $twice);
        $this->assertSame($twice, $thrice);
        $this->assertSame(1, substr_count(strtolower($once), '<a '));
    }

    public function test_case_c_bold_inside_existing_single_anchor_untouched(): void
    {
        $html = '<p><a href="https://example.com/x">công nghệ <strong>DWR</strong></a></p>';
        $out = $this->normalizer->normalize($html);

        $this->assertSame(1, substr_count(strtolower($out), '<a '));
        $this->assertStringContainsString('<strong>DWR</strong>', $out);
    }

    public function test_case_d_em_strong_span_merged(): void
    {
        $input = '<p><a href="https://example.com/x">alpha</a><em><a href="https://example.com/x">beta</a></em><span><a href="https://example.com/x">gamma</a></span></p>';
        $out = $this->normalizer->normalize($input);

        $this->assertSame(1, substr_count(strtolower($out), '<a '));
        $this->assertStringContainsString('<em>beta</em>', $out);
        $this->assertStringContainsString('<span>gamma</span>', $out);
    }

    public function test_case_e_different_urls_not_merged(): void
    {
        $input = '<p><a href="https://example.com/a">one</a><strong><a href="https://example.com/b">two</a></strong></p>';
        $out = $this->normalizer->normalize($input);

        $this->assertSame(2, substr_count(strtolower($out), '<a '));
        $this->assertStringContainsString('https://example.com/a', $out);
        $this->assertStringContainsString('https://example.com/b', $out);
    }

    public function test_case_f_does_not_merge_across_paragraphs(): void
    {
        $input = '<p><a href="https://example.com/x">one</a></p><p><a href="https://example.com/x">two</a></p>';
        $out = $this->normalizer->normalize($input);

        $this->assertSame(2, substr_count(strtolower($out), '<a '));
        $this->assertStringContainsString('</p><p>', preg_replace('/\s+/', '', $out) ?? $out);
    }

    public function test_case_g_preserves_whitespace_between_segments(): void
    {
        $input = '<p><a href="https://example.com/x">công nghệ</a> <strong><a href="https://example.com/x">DWR</a></strong></p>';
        $out = $this->normalizer->normalize($input);

        $this->assertMatchesRegularExpression('/công nghệ\s+<strong>DWR<\/strong>/u', $out);
        $this->assertStringNotContainsString('công nghệDWR', $out);
    }

    public function test_case_h_save_reload_idempotent_three_times(): void
    {
        $input = '<p><a href="https://example.com/x" rel="nofollow">A</a><strong><a href="https://example.com/x" rel="nofollow">B</a></strong></p>';
        $html = $input;
        for ($i = 0; $i < 3; $i++) {
            $html = $this->normalizer->normalize($html);
        }

        $this->assertSame(1, substr_count(strtolower($html), '<a '));
        $this->assertSame($this->normalizer->normalize($html), $html);
    }

    public function test_unwraps_nested_anchors(): void
    {
        $input = '<p><a href="https://example.com/x">outer <a href="https://example.com/x">inner</a></a></p>';
        $out = $this->normalizer->normalize($input);

        $this->assertSame(1, substr_count(strtolower($out), '<a '));
        $this->assertStringContainsString('outer', $out);
        $this->assertStringContainsString('inner', $out);
    }

    public function test_analyze_reports_duplicate_adjacent(): void
    {
        $input = '<p><a href="https://example.com/x">công nghệ</a><strong><a href="https://example.com/x">DWR</a></strong></p>';
        $analysis = $this->normalizer->analyze($input);

        $this->assertSame(2, $analysis->anchorCount);
        $this->assertGreaterThan(0, $analysis->duplicateAdjacentCount);
        $this->assertNotEmpty($analysis->splitGroups);
    }

    public function test_leading_wrapper_then_anchor_merged(): void
    {
        $input = '<p><strong><a href="https://example.com/x">DWR</a></strong><a href="https://example.com/x"> tech</a></p>';
        $out = $this->normalizer->normalize($input);

        $this->assertSame(1, substr_count(strtolower($out), '<a '));
        $this->assertStringContainsString('<strong>DWR</strong>', $out);
    }
}
