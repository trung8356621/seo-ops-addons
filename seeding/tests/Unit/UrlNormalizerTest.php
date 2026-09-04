<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\LinkIntelligence\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    private UrlNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new UrlNormalizer;
    }

    public function test_normalizes_host_casing_and_drops_fragment(): void
    {
        $result = $this->normalizer->normalize('HTTPS://WWW.Example.com/path#frag');

        self::assertNotNull($result);
        self::assertSame('https://www.example.com/path', $result['normalized_url']);
        self::assertSame('example.com', $result['domain']);
        self::assertSame('HTTPS://WWW.Example.com/path#frag', $result['original_url']);
    }

    public function test_preserves_query_string(): void
    {
        $result = $this->normalizer->normalize(
            'https://s.shopee.vn/2BCY1X75a4?share_channel_code=8',
        );

        self::assertSame(
            'https://s.shopee.vn/2BCY1X75a4?share_channel_code=8',
            $result['normalized_url'] ?? null,
        );
    }

    public function test_rejects_non_http_schemes(): void
    {
        self::assertNull($this->normalizer->normalize('ftp://example.com/a'));
        self::assertNull($this->normalizer->normalize('javascript:alert(1)'));
    }
}
