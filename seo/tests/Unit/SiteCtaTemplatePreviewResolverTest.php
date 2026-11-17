<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTemplatePreviewResolver;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTokenHtmlRenderer;
use Omnichannel\Addons\Seo\Services\DomainCtaEditorService;
use Omnichannel\Addons\Seo\Services\SeoDomainCtaGlobalSettingsService;
use PHPUnit\Framework\TestCase;

final class SiteCtaTemplatePreviewResolverTest extends TestCase
{
    public function test_preview_renders_social_anchors_phone_tel_and_address_plain_text(): void
    {
        $resolver = new SiteCtaTemplatePreviewResolver(
            new DomainCtaEditorService(
                new SiteDomainPromptContextService,
                new SeoDomainCtaGlobalSettingsService,
            ),
            new SiteCtaTokenHtmlRenderer,
        );
        $site = new Site(['domain' => 'example.test']);
        $result = $resolver->preview(
            $site,
            'Chat [[cta:zalo]] / [[cta:facebook]] / [[cta:phone_1]] / [[cta:address]] / [[cta:missing]]',
            [
                [
                    'type' => 'zalo',
                    'value' => 'https://zalo.me/123?ref=cta',
                    'label' => 'https://zalo.me/123?ref=cta',
                    'href' => 'https://zalo.me/123?ref=cta',
                    'plain_text' => false,
                    'can_insert' => true,
                    'usable' => true,
                    'is_blank' => false,
                ],
                [
                    'type' => 'facebook',
                    'value' => 'https://facebook.com/page',
                    'label' => 'https://facebook.com/page',
                    'href' => 'https://facebook.com/page',
                    'plain_text' => false,
                    'can_insert' => true,
                    'usable' => true,
                    'is_blank' => false,
                ],
                [
                    'type' => 'phone_1',
                    'value' => '0901234567',
                    'label' => '0901234567',
                    'href' => 'tel:0901234567',
                    'plain_text' => false,
                    'can_insert' => true,
                    'usable' => true,
                    'is_blank' => false,
                ],
                [
                    'type' => 'address',
                    'value' => '12 Nguyen Hue, Q1',
                    'label' => '12 Nguyen Hue, Q1',
                    'href' => '',
                    'plain_text' => true,
                    'can_insert' => true,
                    'usable' => true,
                    'is_blank' => false,
                ],
            ],
        );

        self::assertStringContainsString('<a href="https://zalo.me/123?ref=cta">Zalo</a>', $result['html']);
        self::assertStringContainsString('<a href="https://facebook.com/page">Facebook</a>', $result['html']);
        self::assertStringContainsString('<a href="tel:0901234567">0901234567</a>', $result['html']);
        self::assertStringContainsString('12 Nguyen Hue, Q1', $result['html']);
        self::assertStringNotContainsString('google.com/maps', $result['html']);
        self::assertStringContainsString('[[cta:missing]]', $result['html']);
        self::assertContains('missing', $result['unsupported']);
    }

    public function test_token_renderer_rejects_javascript_urls(): void
    {
        $renderer = new SiteCtaTokenHtmlRenderer;
        $out = $renderer->render('Go [[cta:zalo]]', [
            'zalo' => [
                'value' => 'javascript:alert(1)',
                'href' => 'javascript:alert(1)',
                'plain_text' => false,
            ],
        ]);
        self::assertStringNotContainsString('<a ', $out['html']);
        self::assertStringContainsString('javascript:alert(1)', $out['html']);
    }
}
