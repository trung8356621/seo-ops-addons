<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleCtaPlaceholderService;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use PHPUnit\Framework\TestCase;

final class ArticleCtaPlaceholderServiceTest extends TestCase
{
    public function test_placeholder_guide_lists_all_types(): void
    {
        $guide = (new ArticleCtaPlaceholderService(new SiteDomainPromptContextService))
            ->placeholderGuideForPrompt();

        foreach (array_keys(ArticleCtaPlaceholderService::PLACEHOLDER_TYPES) as $type) {
            $this->assertStringContainsString("[{$type}]", $guide);
        }

        $this->assertStringNotContainsString('—', $guide);
        $this->assertStringNotContainsString('[Website/Hotline]', $guide);
    }

    public function test_format_cta_for_prompt_uses_resolved_contacts_not_placeholder_guide(): void
    {
        $service = new SiteDomainPromptContextService;

        $text = $service->formatCtaForPrompt(
            [['type' => 'phone_1', 'value' => '090'], ['type' => 'email_1', 'value' => 'a@b.c']],
            'Nhắc liên hệ tự nhiên.',
        );

        $this->assertStringContainsString('Resolved Contact Context', $text);
        $this->assertStringContainsString('phone: 090', $text);
        $this->assertStringContainsString('email: a@b.c', $text);
        $this->assertStringNotContainsString('[phone]', $text);
        $this->assertStringNotContainsString('[website]', $text);
    }

    public function test_replace_without_site_leaves_placeholders(): void
    {
        $service = new ArticleCtaPlaceholderService(new SiteDomainPromptContextService);

        $this->assertSame(
            '<p>[phone]</p>',
            $service->replaceInHtml('<p>[phone]</p>', null),
        );
    }

    public function test_detect_placeholder_types_is_case_insensitive_and_scans_faqs(): void
    {
        $service = new ArticleCtaPlaceholderService(new SiteDomainPromptContextService);

        $types = $service->detectPlaceholderTypes(
            '<p>Liên hệ [PHONE] hoặc ghé [Address].</p>',
            'Gọi [email] để nhận tư vấn',
        );

        sort($types);

        $this->assertSame(['address', 'email', 'phone'], $types);
    }

    public function test_apply_for_publish_without_site_is_noop(): void
    {
        $service = new ArticleCtaPlaceholderService(new SiteDomainPromptContextService);

        $result = $service->applyForPublish(null, '<p>[address]</p>', [
            ['question' => 'A?', 'answer' => '<p>[phone]</p>'],
        ]);

        $this->assertSame('<p>[address]</p>', $result['html']);
        $this->assertSame([], $result['added_blank_types']);
    }

    public function test_strip_blank_placeholder_markup_restores_brackets(): void
    {
        $service = new ArticleCtaPlaceholderService(new SiteDomainPromptContextService);

        $html = '<p><span class="seo-cta-blank-placeholder" data-cta-type="website">[website]</span></p>';
        $stripped = $service->stripBlankPlaceholderMarkup($html);

        $this->assertSame('<p>[website]</p>', $stripped);
        $this->assertStringNotContainsString('seo-cta-blank-placeholder', $stripped);
    }

    public function test_website_placeholder_uses_domain_without_manual_value(): void
    {
        $site = new \App\Models\Site(['domain' => 'example.com']);

        $promptContext = SiteDomainPromptContextService::withTestPayload([
            'cta' => [],
        ]);

        $service = new ArticleCtaPlaceholderService($promptContext);

        $html = $service->replaceInHtml('<p>Visit [website]</p>', $site);

        $this->assertStringContainsString('example.com', $html);
        $this->assertStringNotContainsString('[website]', $html);
    }

    public function test_phone_placeholder_uses_one_of_configured_slots(): void
    {
        $site = new \App\Models\Site(['domain' => 'example.com']);

        $promptContext = SiteDomainPromptContextService::withTestPayload([
            'cta' => [
                ['type' => 'phone_1', 'value' => '111'],
                ['type' => 'phone_2', 'value' => '222'],
            ],
        ]);

        $service = new ArticleCtaPlaceholderService($promptContext);

        $html = $service->replaceInHtml('<p>Call [phone] now</p>', $site);

        $this->assertTrue(
            str_contains($html, '111') || str_contains($html, '222'),
        );
        $this->assertStringNotContainsString('[phone]', $html);
    }

    public function test_email_placeholder_uses_one_of_configured_slots(): void
    {
        $site = new \App\Models\Site(['domain' => 'example.com']);

        $promptContext = SiteDomainPromptContextService::withTestPayload([
            'cta' => [
                ['type' => 'email_1', 'value' => 'a@example.com'],
                ['type' => 'email_2', 'value' => 'b@example.com'],
            ],
        ]);

        $service = new ArticleCtaPlaceholderService($promptContext);

        $html = $service->replaceInHtml('<p>Mail [email] now</p>', $site);

        $this->assertTrue(
            str_contains($html, 'a@example.com') || str_contains($html, 'b@example.com'),
        );
        $this->assertStringNotContainsString('[email]', $html);
    }

    public function test_working_hours_placeholder_uses_global_value(): void
    {
        $site = new \App\Models\Site(['domain' => 'example.com']);

        $promptContext = SiteDomainPromptContextService::withTestPayload([
            'cta' => [
                ['type' => 'working_hours', 'value' => 'Mon-Fri 8-17'],
            ],
        ]);

        $service = new ArticleCtaPlaceholderService($promptContext);

        $html = $service->replaceInHtml('<p>Hours: [working_hours]</p>', $site);

        $this->assertStringContainsString('Mon-Fri 8-17', $html);
        $this->assertStringNotContainsString('[working_hours]', $html);
    }

    public function test_website_glued_right_inserts_space(): void
    {
        $html = $this->replaceWithCta(
            '[website]hoặc liên hệ',
            [],
            'maytuicanvas.com',
        );

        $this->assertSame('maytuicanvas.com hoặc liên hệ', $this->visible($html));
        $this->assertStringNotContainsString('comhoặc', $html);
    }

    public function test_email_glued_right_inserts_space(): void
    {
        $html = $this->replaceWithCta(
            '[email]để nhận báo giá',
            [['type' => 'email_1', 'value' => 'info@example.com']],
        );

        $this->assertSame('info@example.com để nhận báo giá', $this->visible($html));
        $this->assertStringNotContainsString('comđể', $html);
    }

    public function test_left_and_right_glue(): void
    {
        $html = $this->replaceWithCta(
            'email[email]để nhận báo giá',
            [['type' => 'email_1', 'value' => 'info@example.com']],
        );

        $this->assertSame('email info@example.com để nhận báo giá', $this->visible($html));
    }

    public function test_website_punctuation_no_extra_space(): void
    {
        $html = $this->replaceWithCta(
            'Website: [website].',
            [],
            'maytuicanvas.com',
        );

        $this->assertSame('Website: maytuicanvas.com.', $this->visible($html));
        $this->assertStringNotContainsString('com .', $this->visible($html));
    }

    public function test_phone_comma_no_extra_space(): void
    {
        $html = $this->replaceWithCta(
            'Hotline: [phone], gọi ngay.',
            [['type' => 'phone_1', 'value' => '0901234567']],
        );

        $this->assertSame('Hotline: 0901234567, gọi ngay.', $this->visible($html));
    }

    public function test_phone_parentheses_no_extra_space(): void
    {
        $html = $this->replaceWithCta(
            '([phone])',
            [['type' => 'phone_1', 'value' => '0901234567']],
        );

        $this->assertSame('(0901234567)', $this->visible($html));
    }

    public function test_address_comma_no_extra_space(): void
    {
        $html = $this->replaceWithCta(
            '[address], TP.HCM',
            [['type' => 'address', 'value' => '123 Nguyễn Văn A']],
        );

        $this->assertSame('123 Nguyễn Văn A, TP.HCM', $this->visible($html));
    }

    public function test_existing_spaces_not_doubled(): void
    {
        $html = $this->replaceWithCta(
            '[website] hoặc [email]',
            [['type' => 'email_1', 'value' => 'info@example.com']],
            'maytuicanvas.com',
        );

        $this->assertSame('maytuicanvas.com hoặc info@example.com', $this->visible($html));
        $this->assertStringNotContainsString('  ', $this->visible($html));
    }

    public function test_multiple_tokens_with_glue(): void
    {
        $html = $this->replaceWithCta(
            'Gọi[phone]hoặc email[email]để được hỗ trợ.',
            [
                ['type' => 'phone_1', 'value' => '0901234567'],
                ['type' => 'email_1', 'value' => 'info@example.com'],
            ],
        );

        $this->assertSame(
            'Gọi 0901234567 hoặc email info@example.com để được hỗ trợ.',
            $this->visible($html),
        );
    }

    public function test_address_vietnamese_unicode_glue(): void
    {
        $html = $this->replaceWithCta(
            '[address]để nhận tư vấn.',
            [['type' => 'address', 'value' => '12 Nguyễn Thị Minh Khai']],
        );

        $this->assertSame('12 Nguyễn Thị Minh Khai để nhận tư vấn.', $this->visible($html));
    }

    public function test_url_value_remains_atomic(): void
    {
        $url = 'https://example.com/a,b?q=x.y';
        $html = $this->replaceWithCta(
            'Xem [facebook] ngay',
            [['type' => 'facebook', 'value' => $url]],
        );

        $this->assertSame('Xem '.$url.' ngay', $this->visible($html));
        $this->assertStringContainsString('href="'.htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"', $html);
        $this->assertStringNotContainsString('a, b', $this->visible($html));
    }

    public function test_email_value_remains_atomic(): void
    {
        $html = $this->replaceWithCta(
            'Mail [email] now',
            [['type' => 'email_1', 'value' => 'support.sales@example.com']],
        );

        $this->assertSame('Mail support.sales@example.com now', $this->visible($html));
        $this->assertStringContainsString('support.sales@example.com', $html);
    }

    public function test_unrelated_missing_space_not_mutated(): void
    {
        $html = $this->replaceWithCta(
            'Đây là nội dung,bị thiếu space nhưng không liên quan placeholder.',
            [['type' => 'phone_1', 'value' => '0901234567']],
        );

        $this->assertSame(
            'Đây là nội dung,bị thiếu space nhưng không liên quan placeholder.',
            $this->visible($html),
        );
    }

    public function test_real_glued_website_and_email_case(): void
    {
        $html = $this->replaceWithCta(
            'Bạn có thể truy cập [website]hoặc email[email]để nhận báo giá.',
            [['type' => 'email_1', 'value' => 'info.mayhopphat@gmail.com']],
            'maytuicanvas.com',
        );

        $visible = $this->visible($html);
        $this->assertStringContainsString('maytuicanvas.com hoặc', $visible);
        $this->assertStringContainsString('email info.mayhopphat@gmail.com để', $visible);
        $this->assertStringNotContainsString('comhoặc', $visible);
        $this->assertStringNotContainsString('comđể', $visible);
    }

    /**
     * @param  list<array{type: string, value: string}>  $cta
     */
    private function replaceWithCta(string $html, array $cta, string $domain = 'example.com'): string
    {
        $site = new \App\Models\Site(['domain' => $domain]);
        $promptContext = SiteDomainPromptContextService::withTestPayload(['cta' => $cta]);
        $service = new ArticleCtaPlaceholderService($promptContext);

        return $service->replaceInHtml($html, $site);
    }

    private function visible(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
