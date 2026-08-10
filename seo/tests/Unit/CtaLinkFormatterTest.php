<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\CtaLinkFormatter;
use PHPUnit\Framework\TestCase;

final class CtaLinkFormatterTest extends TestCase
{
    public function test_formats_phone_as_tel_link(): void
    {
        $this->assertSame('tel:0783330209', CtaLinkFormatter::format('phone', '078.333.0209'));
    }

    public function test_formats_email_as_mailto(): void
    {
        $this->assertSame('mailto:hello@example.com', CtaLinkFormatter::format('email', 'hello@example.com'));
    }

    public function test_formats_zalo_with_digits_only(): void
    {
        $this->assertSame('https://zalo.me/0901234567', CtaLinkFormatter::format('zalo', '0901 234 567'));
    }

    public function test_formats_address_as_google_maps_search(): void
    {
        $href = CtaLinkFormatter::format('address', '123 Nguyen Trai, HCM');

        $this->assertStringStartsWith('https://www.google.com/maps/search/?api=1&query=', $href);
        $this->assertStringContainsString('123', $href);
    }

    public function test_formats_website_with_https_prefix(): void
    {
        $this->assertSame('https://example.com', CtaLinkFormatter::format('website', 'example.com'));
        $this->assertSame('https://example.com/page', CtaLinkFormatter::format('website', 'https://example.com/page'));
    }
}
