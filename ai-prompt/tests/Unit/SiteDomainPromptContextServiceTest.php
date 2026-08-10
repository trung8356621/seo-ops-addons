<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
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
