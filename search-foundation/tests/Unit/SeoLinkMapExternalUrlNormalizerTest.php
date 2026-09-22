<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Support\SeoLinkMapExternalUrlNormalizer;
use PHPUnit\Framework\TestCase;

final class SeoLinkMapExternalUrlNormalizerTest extends TestCase
{
    public function test_unwraps_facebook_lphp_and_strips_fbclid(): void
    {
        $destination = 'https://baloquatang.net/?fbclid='.str_repeat('A', 400);
        $wrapped = 'https://l.facebook.com/l.php?u='.rawurlencode($destination).'&h=AT123';

        $normalized = SeoLinkMapExternalUrlNormalizer::forStorage($wrapped);

        self::assertSame('https://baloquatang.net/', $normalized);
    }

    public function test_strips_tracking_keeps_business_query(): void
    {
        $normalized = SeoLinkMapExternalUrlNormalizer::forStorage(
            'https://shop.test/item?utm_source=fb&fbclid=xyz&sku=42'
        );

        self::assertSame('https://shop.test/item?sku=42', $normalized);
    }

    public function test_caps_length_at_max(): void
    {
        $url = 'https://example.test/path?q='.str_repeat('x', 3000);

        $normalized = SeoLinkMapExternalUrlNormalizer::forStorage($url);

        self::assertNotNull($normalized);
        self::assertLessThanOrEqual(SeoLinkMapExternalUrlNormalizer::MAX_LENGTH, mb_strlen($normalized));
        self::assertSame(SeoLinkMapExternalUrlNormalizer::MAX_LENGTH, mb_strlen($normalized));
    }

    public function test_null_and_blank_become_null(): void
    {
        self::assertNull(SeoLinkMapExternalUrlNormalizer::forStorage(null));
        self::assertNull(SeoLinkMapExternalUrlNormalizer::forStorage('   '));
    }
}
