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
}
