<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\CtaAutomation\CtaBlockRenderer;
use Omnichannel\Addons\Content\Services\CtaAutomation\CtaOutputValidator;
use Omnichannel\Addons\Content\Services\CtaAutomation\CtaShortcodeRegistry;
use Omnichannel\Addons\Content\Services\CtaAutomation\LegacyCtaDetector;
use Omnichannel\Addons\Content\Services\ArticleCtaPlaceholderService;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use PHPUnit\Framework\TestCase;

final class CtaAutomationPipelineTest extends TestCase
{
    public function test_legacy_contact_spam_is_detected_and_replaced_without_touching_neighbors(): void
    {
        $html = '<p>Nylon resists water when the weave is tight.</p>'
            .'<blockquote>Contact us via Zalo https://zalo.me/1, Facebook https://facebook.com/a, email a@b.com, address 1 Street, and website https://shop.test.</blockquote>'
            .'<p>Choose a size that matches the torso length.</p>';
        $detected = (new LegacyCtaDetector())->detect($html);
        self::assertCount(1, $detected);
        self::assertSame('high', $detected[0]['confidence']);
        self::assertSame('standalone_blockquote', $detected[0]['structural_signal']);

        $renderer = new CtaBlockRenderer();
        $block = $renderer->blockHtml(
            'cta_001',
            'section_1',
            'consultation',
            'zalo',
            'improved',
            'Để chọn size phù hợp, có thể nhắn qua [zalo].',
            'consultation',
        );
        $once = $renderer->apply($html, [
            ['id' => 'remove_legacy_1', 'kind' => 'remove', 'section_id' => 'section_1', 'text' => $detected[0]['text'], 'occurrence' => 1],
            ['id' => 'insert_cta_001', 'kind' => 'insert', 'section_id' => 'section_1', 'placement_id' => 'cta_001', 'html' => $block],
        ]);
        self::assertTrue($once['ok']);
        self::assertStringContainsString('Nylon resists water', $once['html']);
        self::assertStringContainsString('torso length', $once['html']);
        self::assertStringNotContainsString('facebook.com', $once['html']);
        self::assertStringContainsString('[seo_ops_cta style="consultation"', $once['html']);
        self::assertStringContainsString('[zalo]', $once['html']);
        self::assertSame(1, substr_count($once['html'], 'seo-managed-cta'));

        $twice = $renderer->apply($once['html'], [
            ['id' => 'insert_cta_001', 'kind' => 'insert', 'section_id' => 'section_1', 'placement_id' => 'cta_001', 'html' => $block],
        ]);
        self::assertTrue($twice['ok']);
        self::assertSame(1, substr_count($twice['html'], 'seo-managed-cta'));
        self::assertStringContainsString('Nylon resists water', $twice['html']);
    }

    public function test_mixed_promotional_paragraph_is_uncertain(): void
    {
        $html = '<p>The fabric stays dry in light rain and lasts for years of school use, then contact us via Zalo if you want a catalog.</p>';
        $detected = (new LegacyCtaDetector())->detect($html);
        self::assertCount(1, $detected);
        self::assertSame('uncertain', $detected[0]['confidence']);
        self::assertSame('mixed_paragraph', $detected[0]['structural_signal']);
    }

    public function test_validator_rejects_raw_contacts_and_extra_shortcodes(): void
    {
        $validator = new CtaOutputValidator();
        $result = $validator->validate(
            [['placement_id' => 'cta_001', 'section_id' => 'section_1', 'alias' => 'website']],
            [[
                'placement_id' => 'cta_001',
                'section_id' => 'section_1',
                'text' => 'Xem thêm balo phù hợp tại [website] hoặc [facebook] https://evil.test.',
            ]],
            'vi',
        );
        self::assertFalse($result['ok']);
        $codes = array_column($result['errors'], 'code');
        self::assertContains('multiple_shortcodes', $codes);
        self::assertContains('raw_url', $codes);
    }

    public function test_shortcode_assignment_uses_only_enabled_aliases(): void
    {
        $registry = new CtaShortcodeRegistry(new ArticleCtaPlaceholderService(new SiteDomainPromptContextService()));
        $alias = $registry->assign('consultation', ['zalo', 'website'], []);
        self::assertSame('zalo', $alias);
        $next = $registry->assign('product_discovery', ['zalo', 'website'], ['zalo']);
        self::assertSame('website', $next);
        self::assertNull($registry->assign('consultation', ['website'], []));
    }

    public function test_manual_cta_is_not_removed(): void
    {
        $html = '<p>Keep this paragraph.</p><blockquote data-cta-manual="1" class="seo-managed-cta">Contact us via Zalo https://zalo.me/9.</blockquote>';
        $detected = (new LegacyCtaDetector())->detect($html);
        self::assertSame([], $detected);
        $rendered = (new CtaBlockRenderer())->apply($html, [
            ['id' => 'clear_auto', 'kind' => 'remove_managed', 'placement_id' => ''],
        ]);
        self::assertFalse($rendered['ok']);
        self::assertStringContainsString('data-cta-manual', $rendered['html']);
        self::assertStringContainsString('Keep this paragraph', $rendered['html']);
    }

    public function test_h3_insert_does_not_land_in_another_h2(): void
    {
        $html = '<p>Intro stays here.</p>'
            .'<h2>Materials</h2><p>Nylon and polyester.</p>'
            .'<h3>In jet</h3><p>Digital printing notes.</p>'
            .'<h2>Conclusion</h2><p>Choose the weave.</p>'
            .'<p>[faq]</p>';
        $sections = (new \Omnichannel\Addons\Content\Services\CtaAutomation\CtaArticleSections())->extract($html);
        $jet = null;
        foreach ($sections as $section) {
            if ($section['heading'] === 'In jet') {
                $jet = $section['section_id'];
            }
        }
        self::assertNotNull($jet);
        $renderer = new CtaBlockRenderer();
        $block = $renderer->blockHtml('cta_jet', $jet, 'product_discovery', 'website', 'improved', 'Xem [website].', 'soft', 'run-1');
        $applied = $renderer->apply($html, [
            ['id' => 'insert_cta_jet', 'kind' => 'insert', 'section_id' => $jet, 'placement_id' => 'cta_jet', 'html' => $block],
        ]);
        self::assertTrue($applied['ok']);
        $position = strpos($applied['html'], 'data-cta-placement="cta_jet"');
        $conclusion = strpos($applied['html'], '<h2>Conclusion</h2>');
        $jetHeading = strpos($applied['html'], '<h3>In jet</h3>');
        self::assertNotFalse($position);
        self::assertGreaterThan($jetHeading, $position);
        self::assertLessThan($conclusion, $position);
        $other = $renderer->apply($applied['html'], [
            ['id' => 'remove_other', 'kind' => 'remove_managed', 'placement_id' => 'cta_other'],
        ]);
        self::assertFalse($other['ok']);
        self::assertStringContainsString('cta_jet', $other['html']);
    }

    public function test_similar_paragraph_in_another_section_is_not_removed(): void
    {
        $text = 'Contact us via Zalo https://zalo.me/1 and website https://shop.test.';
        $html = '<h2>One</h2><p>'.$text.'</p><h2>Two</h2><p>'.$text.'</p>';
        $detected = (new LegacyCtaDetector())->detect($html);
        self::assertCount(2, $detected);
        self::assertNotSame($detected[0]['section_id'], $detected[1]['section_id']);
        $rendered = (new CtaBlockRenderer())->apply($html, [[
            'id' => 'remove_one',
            'kind' => 'remove',
            'section_id' => $detected[0]['section_id'],
            'text' => $detected[0]['text'],
            'occurrence' => 1,
        ]]);
        self::assertTrue($rendered['ok']);
        self::assertSame(1, substr_count($rendered['html'], 'zalo.me'));
    }

    public function test_regeneration_keeps_selected_style_preset(): void
    {
        $renderer = new CtaBlockRenderer();
        $html = $renderer->blockHtml('cta_001', 'section_1', 'consultation', 'zalo', 'improved', 'Nhắn [zalo].', 'conversion');
        $styles = $renderer->readStylePresets($html);
        self::assertSame('conversion', $styles['cta_001'] ?? null);
        $replaced = $renderer->blockHtml('cta_001', 'section_1', 'consultation', 'website', 'generated', 'Xem [website].', $styles['cta_001']);
        self::assertStringContainsString('data-cta-style="conversion"', $replaced);
        self::assertStringContainsString('style="conversion"', $replaced);
    }
}
