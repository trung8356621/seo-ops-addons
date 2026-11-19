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
            'Chat [[cta:zalo]] / [[cta:facebook]] / [[cta:phone_1]] / [[cta:phone_2]] / [[cta:email]] / [[cta:address]] / [[cta:missing]]',
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
                    'type' => 'phone_2',
                    'value' => '0987654321',
                    'label' => '0987654321',
                    'href' => 'tel:0987654321',
                    'plain_text' => false,
                    'can_insert' => true,
                    'usable' => true,
                    'is_blank' => false,
                ],
                [
                    'type' => 'email_1',
                    'value' => 'sales@example.test',
                    'label' => 'sales@example.test',
                    'href' => 'mailto:sales@example.test',
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
        self::assertStringContainsString('<a href="tel:0987654321">0987654321</a>', $result['html']);
        self::assertStringContainsString('<a href="mailto:sales@example.test">Email</a>', $result['html']);
        self::assertStringContainsString('12 Nguyen Hue, Q1', $result['html']);
        self::assertStringNotContainsString('google.com/maps', $result['html']);
        self::assertStringContainsString('[[cta:missing]]', $result['html']);
        self::assertContains('missing', $result['unsupported']);
    }

    public function test_token_renderer_rejects_javascript_urls(): void
    {
        $renderer = new SiteCtaTokenHtmlRenderer;
        $out = $renderer->render('Go [[cta:zalo]] [[cta:phone_1]] [[cta:email_1]]', [
            'zalo' => [
                'value' => 'javascript:alert(1)',
                'href' => 'javascript:alert(1)',
                'plain_text' => false,
            ],
            'phone_1' => [
                'value' => 'javascript:alert(1)',
                'href' => 'javascript:alert(1)',
                'plain_text' => false,
            ],
            'email_1' => [
                'value' => 'not-an-email',
                'href' => '',
                'plain_text' => false,
            ],
        ]);
        self::assertStringNotContainsString('<a ', $out['html']);
        self::assertStringContainsString('javascript:alert(1)', $out['html']);
    }

    public function test_cta_link_formatter_formats_numbered_phone_and_email_slots(): void
    {
        self::assertSame('tel:0988693337', \Omnichannel\Addons\Seo\Support\CtaLinkFormatter::format('phone_1', '0988 693 337'));
        self::assertSame('tel:0901167668', \Omnichannel\Addons\Seo\Support\CtaLinkFormatter::format('phone_2', '0901167668'));
        self::assertSame('mailto:info@example.test', \Omnichannel\Addons\Seo\Support\CtaLinkFormatter::format('email_1', 'info@example.test'));
        self::assertSame('mailto:sales@example.test', \Omnichannel\Addons\Seo\Support\CtaLinkFormatter::format('email_2', 'sales@example.test'));
        self::assertSame('', \Omnichannel\Addons\Seo\Support\CtaLinkFormatter::format('email_1', 'javascript:alert(1)'));
        self::assertSame('', \Omnichannel\Addons\Seo\Support\CtaLinkFormatter::format('phone_1', 'javascript:alert(1)'));

        $resolver = new SiteCtaTemplatePreviewResolver(
            new DomainCtaEditorService(
                new SiteDomainPromptContextService,
                new SeoDomainCtaGlobalSettingsService,
            ),
            new SiteCtaTokenHtmlRenderer,
        );
        $site = new Site(['domain' => 'example.test']);
        $result = $resolver->preview($site, 'Call [[cta:phone_1]] / [[cta:phone_2]] or [[cta:email_1]] / [[cta:email_2]]', [
            ['type' => 'phone_1', 'value' => '0988693337', 'href' => '0988693337', 'plain_text' => false, 'usable' => true],
            ['type' => 'phone_2', 'value' => '0901167668', 'href' => '0901167668', 'plain_text' => false, 'usable' => true],
            ['type' => 'email_1', 'value' => 'info@example.test', 'href' => 'info@example.test', 'plain_text' => false, 'usable' => true],
            ['type' => 'email_2', 'value' => 'sales@example.test', 'href' => 'sales@example.test', 'plain_text' => false, 'usable' => true],
        ]);
        self::assertStringContainsString('<a href="tel:0988693337">0988693337</a>', $result['html']);
        self::assertStringContainsString('<a href="tel:0901167668">0901167668</a>', $result['html']);
        self::assertStringContainsString('<a href="mailto:info@example.test">Email</a>', $result['html']);
        self::assertStringContainsString('<a href="mailto:sales@example.test">Email</a>', $result['html']);
    }

    public function test_legacy_tokens_and_site_website_fallback_render_consistently(): void
    {
        $resolver = new SiteCtaTemplatePreviewResolver(
            new DomainCtaEditorService(
                new SiteDomainPromptContextService,
                new SeoDomainCtaGlobalSettingsService,
            ),
            new SiteCtaTokenHtmlRenderer,
        );
        $site = new Site(['domain' => 'baloquatang.net', 'ssl' => true]);
        $template = 'Xem [website], chat [zalo], gọi [phone], gửi [email], ghé [address], hoặc [[cta:zalo]].';
        $contacts = [
            ['type' => 'zalo', 'value' => 'https://zalo.me/0901167668', 'href' => 'https://zalo.me/0901167668', 'plain_text' => false, 'usable' => true],
            ['type' => 'phone_1', 'value' => '0988693337', 'href' => 'tel:0988693337', 'plain_text' => false, 'usable' => true],
            ['type' => 'email_1', 'value' => 'info@baloquatang.net', 'href' => 'mailto:info@baloquatang.net', 'plain_text' => false, 'usable' => true],
            ['type' => 'address', 'value' => '48/1 Duong So 3', 'href' => '', 'plain_text' => true, 'usable' => true],
        ];

        $result = $resolver->preview($site, $template, $contacts);
        self::assertStringContainsString('<a href="https://baloquatang.net/">Website</a>', $result['html']);
        self::assertStringContainsString('<a href="https://zalo.me/0901167668">Zalo</a>', $result['html']);
        self::assertStringContainsString('<a href="tel:0988693337">0988693337</a>', $result['html']);
        self::assertStringContainsString('<a href="mailto:info@baloquatang.net">Email</a>', $result['html']);
        self::assertStringContainsString('48/1 Duong So 3', $result['html']);
        self::assertStringNotContainsString('<a href="48/1 Duong So 3">', $result['html']);
        self::assertSame([], $result['unresolved']);

        // Updating site contact updates rendered preview without mutating stored template.
        $contacts[1]['value'] = '0909999888';
        $contacts[1]['href'] = 'tel:0909999888';
        $updated = $resolver->preview($site, $template, $contacts);
        self::assertStringContainsString('<a href="tel:0909999888">0909999888</a>', $updated['html']);
    }
}
