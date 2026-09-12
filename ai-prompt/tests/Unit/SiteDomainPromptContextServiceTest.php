<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner;
use Omnichannel\Addons\AiPrompt\Services\WritingSectionPromptCompiler;
use PHPUnit\Framework\TestCase;

final class SiteDomainPromptContextServiceTest extends TestCase
{
    public function test_count_words_and_format_cta(): void
    {
        $service = new SiteDomainPromptContextService;

        $this->assertSame(3, $service->countWords('một hai ba'));
        $merged = $service->formatCtaForPrompt(
            [['type' => 'phone_1', 'value' => '090']],
            'Hướng dẫn CTA',
            new \App\Models\Site(['domain' => 'example.com']),
        );
        $this->assertStringContainsString('Hướng dẫn CTA', $merged);
        $this->assertStringContainsString('Resolved Contact Context', $merged);
        $this->assertStringContainsString('phone: 090', $merged);
        $this->assertStringContainsString('website: example.com', $merged);
        $this->assertStringNotContainsString('[phone]', $merged);
        $this->assertStringNotContainsString('Giá trị đã cấu hình trên domain', $merged);
        $this->assertStringContainsString(
            'báo giá → https://example.com/bao-gia',
            $service->formatLinksForPrompt([
                ['keyword' => 'báo giá', 'link' => 'https://example.com/bao-gia'],
            ]),
        );
    }

    public function test_a_fs_instruction_never_enters_cta_context(): void
    {
        $service = new SiteDomainPromptContextService;
        $fs = SiteDomainPromptContextService::DEFAULT_FEATURED_SNIPPET_INSTRUCTION;

        $siteCta = $service->formatCtaForPrompt(
            [
                ['type' => 'zalo', 'value' => 'https://zalo.me/1'],
                ['type' => 'facebook', 'value' => 'https://facebook.com/x'],
                ['type' => 'email_1', 'value' => 'a@b.c'],
                ['type' => 'address', 'value' => '1 Street'],
            ],
            SiteDomainPromptContextService::LEGACY_CONTAMINATED_CTA_INTRO,
            new \App\Models\Site(['domain' => 'example.com']),
        );

        $writingPrompt = "* CTA Context: `{$siteCta}`\n\n## CONCLUSION & ISOLATED CTA FORMAT\n";

        $this->assertStringNotContainsString('CTA Context: `'.$fs, $writingPrompt);
        $this->assertStringNotContainsString($fs, $siteCta);
        $this->assertStringNotContainsString('Tạo một bảng so sánh hoặc danh sách liệt kê', $siteCta);
        $this->assertStringNotContainsString('Featured Snippet', $siteCta);
    }

    public function test_b_legitimate_resolved_contact_context_remains(): void
    {
        $service = new SiteDomainPromptContextService;

        $siteCta = $service->formatCtaForPrompt(
            [
                ['type' => 'zalo', 'value' => 'https://zalo.me/1'],
                ['type' => 'facebook', 'value' => 'https://facebook.com/x'],
                ['type' => 'email_1', 'value' => 'a@b.c'],
                ['type' => 'address', 'value' => '1 Street'],
            ],
            SiteDomainPromptContextService::LEGACY_CONTAMINATED_CTA_INTRO,
            new \App\Models\Site(['domain' => 'example.com']),
        );

        $this->assertStringContainsString('Resolved Contact Context', $siteCta);
        $this->assertStringContainsString('zalo: https://zalo.me/1', $siteCta);
        $this->assertStringContainsString('facebook: https://facebook.com/x', $siteCta);
        $this->assertStringContainsString('email: a@b.c', $siteCta);
        $this->assertStringContainsString('address: 1 Street', $siteCta);
        $this->assertStringContainsString('website: example.com', $siteCta);
        $this->assertStringContainsString('Kêu gọi hành động (CTA)', $siteCta);
    }

    public function test_c_fs_micro_prompt_receives_fs_instruction_field(): void
    {
        $service = new SiteDomainPromptContextService;
        $fsVars = $service->featuredSnippetVariables();
        $fs = SiteDomainPromptContextService::DEFAULT_FEATURED_SNIPPET_INSTRUCTION;

        $this->assertSame($fs, $fsVars['featured_snippet_instruction']);
        $this->assertSame($fs, $fsVars['featured_snippet_context']);
        $this->assertSame($fs, $fsVars['featured_snippet_format']);
        $this->assertStringContainsString('Tạo một bảng so sánh hoặc danh sách liệt kê', $fsVars['featured_snippet_instruction']);
    }

    public function test_d_namespace_isolation_fs_and_cta(): void
    {
        $service = new SiteDomainPromptContextService;
        $fs = SiteDomainPromptContextService::DEFAULT_FEATURED_SNIPPET_INSTRUCTION;

        $ctaBefore = $service->formatCtaForPrompt(
            [['type' => 'zalo', 'value' => 'https://zalo.me/1']],
            'Nhắc liên hệ tự nhiên.',
            new \App\Models\Site(['domain' => 'example.com']),
        );
        $fsBefore = $service->featuredSnippetVariables();

        // Changing FS instruction constant ownership must not mutate CTA contacts.
        $this->assertSame($fs, $fsBefore['featured_snippet_instruction']);
        $this->assertStringNotContainsString($fs, $ctaBefore);
        $this->assertStringContainsString('Resolved Contact Context', $ctaBefore);
        $this->assertStringContainsString('zalo: https://zalo.me/1', $ctaBefore);

        // Changing CTA/domain contact must not mutate FS instruction.
        $ctaAfter = $service->formatCtaForPrompt(
            [['type' => 'email_1', 'value' => 'new@x.com']],
            'CTA khác.',
            new \App\Models\Site(['domain' => 'other.com']),
        );
        $fsAfter = $service->featuredSnippetVariables();

        $this->assertSame($fsBefore, $fsAfter);
        $this->assertStringContainsString('email: new@x.com', $ctaAfter);
        $this->assertStringNotContainsString('zalo:', $ctaAfter);
        $this->assertStringNotContainsString($fs, $ctaAfter);
    }

    public function test_strip_featured_snippet_from_domain_variant_cta_intro(): void
    {
        $service = new SiteDomainPromptContextService;
        $domainVariant = 'Tạo một bảng so sánh hoặc danh sách liệt kê (bullet points) để tăng khả năng đạt Featured Snippet. Kêu gọi hành động (CTA) ở mỗi heading — dùng placeholder [phone], [website], [email], … (xem danh sách bên dưới), không tự đặt tên khác.';

        $cleaned = $service->stripFeaturedSnippetFromCtaIntro($domainVariant);
        $this->assertStringNotContainsString('Tạo một bảng so sánh', $cleaned);
        $this->assertStringNotContainsString('Featured Snippet', $cleaned);
        $this->assertStringContainsString('Kêu gọi hành động (CTA)', $cleaned);

        $siteCta = $service->formatCtaForPrompt(
            [['type' => 'zalo', 'value' => 'https://zalo.me/1']],
            $domainVariant,
            new \App\Models\Site(['domain' => 'mayhopphat.com']),
        );
        $this->assertStringNotContainsString('Tạo một bảng so sánh hoặc danh sách liệt kê', $siteCta);
        $this->assertStringContainsString('Resolved Contact Context', $siteCta);
    }

    public function test_writing_section_compile_does_not_label_fs_as_cta_context(): void
    {
        $service = new SiteDomainPromptContextService;
        $fs = SiteDomainPromptContextService::DEFAULT_FEATURED_SNIPPET_INSTRUCTION;
        $siteCta = $service->formatCtaForPrompt(
            [
                ['type' => 'zalo', 'value' => 'https://zalo.me/1'],
                ['type' => 'facebook', 'value' => 'https://facebook.com/x'],
            ],
            SiteDomainPromptContextService::LEGACY_CONTAMINATED_CTA_INTRO,
            new \App\Models\Site(['domain' => 'example.com']),
        );

        $runner = $this->createMock(PromptRunnerService::class);
        $runner->method('compilePrompt')->willReturnCallback(
            static function (SeoPrompt $prompt, array $vars): string {
                return "* CTA Context: `".($vars['site_cta'] ?? '')."`\n\n"
                    ."## CONCLUSION & ISOLATED CTA FORMAT\n"
                    .($vars['input'] ?? '');
            },
        );

        $rows = (new \Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer())
            ->normalize("[MỞ BÀI]\nIntro only.\n\n## Heading A\nPoint A1\n");
        $plan = (new WritingMultiplePassStepPlanner())->planFromRows($rows);
        $unit = $plan->units[0];

        $compiled = (new WritingSectionPromptCompiler($runner))->compile(
            new SeoPrompt(['markdown_content' => '* CTA Context: `{{site_cta}}`']),
            [
                'site_cta' => $siteCta,
                'article_title' => 'Test title',
            ],
            $unit,
        );

        $this->assertStringNotContainsString('CTA Context: `'.$fs, $compiled);
        $this->assertStringNotContainsString('Tạo một bảng so sánh hoặc danh sách liệt kê', $compiled);
        $this->assertStringContainsString('Resolved Contact Context', $compiled);
    }

    public function test_merge_global_cta_into_rows(): void
    {
        $service = new SiteDomainPromptContextService;

        $merged = $service->mergeGlobalCtaIntoRows(
            [['type' => 'zalo', 'value' => 'https://zalo.me/123']],
            [['type' => 'working_hours', 'value' => '8h-17h']],
        );

        $this->assertCount(2, $merged);
        $this->assertSame('working_hours', $merged[1]['type']);
        $this->assertSame('8h-17h', $merged[1]['value']);
    }

    public function test_global_cta_ignores_legacy_domain_rows(): void
    {
        $service = new SiteDomainPromptContextService;

        $merged = $service->mergeGlobalCtaIntoRows(
            [['type' => 'working_hours', 'value' => 'domain hours']],
            [['type' => 'working_hours', 'value' => 'global hours']],
        );

        $this->assertCount(1, $merged);
        $this->assertSame('global hours', $merged[0]['value']);
    }
}
