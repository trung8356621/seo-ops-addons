<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use PHPUnit\Framework\TestCase;

final class SiteDomainPromptContextPatchTest extends TestCase
{
    public function test_clamp_company_short_identity_respects_80_char_limit(): void
    {
        $service = new SiteDomainPromptContextService;
        $long = 'May Hợp Phát | Công Ty May Balo Túi Xách Giá Rẻ TP.HCM | Thêm ký tự vượt giới hạn';
        $clamped = $service->clampCompanyShortIdentity($long);

        $this->assertLessThanOrEqual(SiteDomainPromptContextService::COMPANY_SHORT_IDENTITY_MAX, mb_strlen($clamped));
        $this->assertSame(
            mb_substr(trim(preg_replace('/\s+/u', ' ', $long) ?? $long), 0, SiteDomainPromptContextService::COMPANY_SHORT_IDENTITY_MAX),
            $clamped,
        );
    }

    public function test_count_words_rejects_over_limit_short_description(): void
    {
        $service = new SiteDomainPromptContextService;
        $words = implode(' ', array_fill(0, SiteDomainPromptContextService::MAX_SHORT_DESCRIPTION_WORDS + 1, 'word'));

        $this->assertGreaterThan(
            SiteDomainPromptContextService::MAX_SHORT_DESCRIPTION_WORDS,
            $service->countWords($words),
        );
    }

    public function test_patch_for_site_method_exists_and_documents_allowed_keys(): void
    {
        $reflection = new \ReflectionClass(SiteDomainPromptContextService::class);
        $method = $reflection->getMethod('patchForSite');
        $this->assertTrue($method->isPublic());
        $source = (string) file_get_contents($reflection->getFileName());
        $this->assertStringContainsString("'company_short_identity'", $source);
        $this->assertStringContainsString("'short_description'", $source);
        $this->assertStringContainsString('getRawPayloadForSite', $source);
        $this->assertStringContainsString('saveForSite', $source);
        $this->assertStringContainsString('array_intersect_key', $source);
    }
}
