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
            '<a href="https://zalo.me/1">Zalo</a>',
        );
        $once = $renderer->apply($html, [
            ['id' => 'remove_legacy_1', 'kind' => 'remove', 'text' => $detected[0]['text']],
            ['id' => 'insert_cta_001', 'kind' => 'insert', 'section_id' => 'section_1', 'html' => $block],
        ]);
        self::assertStringContainsString('Nylon resists water', $once['html']);
        self::assertStringContainsString('torso length', $once['html']);
        self::assertStringNotContainsString('facebook.com', $once['html']);
        self::assertSame(1, substr_count($once['html'], 'seo-managed-cta'));

        $twice = $renderer->apply($once['html'], [
            ['id' => 'clear_auto', 'kind' => 'remove_managed', 'placement_id' => ''],
            ['id' => 'insert_cta_001', 'kind' => 'insert', 'section_id' => 'section_1', 'html' => $block],
        ]);
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
        self::assertStringContainsString('data-cta-manual', $rendered['html']);
        self::assertStringContainsString('Keep this paragraph', $rendered['html']);
    }
}
