<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Tests\Unit;

use Omnichannel\Addons\Social\Services\SocialSupportedDomainService;
use Omnichannel\Addons\Social\Services\SocialUrlNormalizer;
use PHPUnit\Framework\TestCase;

final class SocialUrlNormalizerTest extends TestCase
{
    private SocialUrlNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new SocialUrlNormalizer(
            SocialSupportedDomainService::withSupportedDomains(['facebook.com', 'x.com']),
        );
    }

    public function test_normalizes_hostname_to_lowercase_and_removes_fragment(): void
    {
        $first = $this->normalizer->normalize('HTTPS://WWW.Facebook.com/post/1#section');
        $second = $this->normalizer->normalize('https://www.facebook.com/post/1');

        self::assertNotNull($first);
        self::assertSame('https://www.facebook.com/post/1', $first['url']);
        self::assertSame('facebook.com', $first['domain']);
        self::assertSame($first['url_hash'], $second['url_hash'] ?? '');
    }

    public function test_rejects_unsupported_protocol(): void
    {
        self::assertNull($this->normalizer->normalize('ftp://facebook.com/post/1'));
    }

    public function test_drops_default_ports(): void
    {
        $http = $this->normalizer->normalize('http://facebook.com:80/post/1');
        $https = $this->normalizer->normalize('https://facebook.com:443/post/1');

        self::assertSame('http://facebook.com/post/1', $http['url'] ?? null);
        self::assertSame('https://facebook.com/post/1', $https['url'] ?? null);
    }

    public function test_preserves_query_string_in_canonical_url(): void
    {
        $normalized = $this->normalizer->normalize('https://facebook.com/share?story=abc&id=1');

        self::assertSame('https://facebook.com/share?story=abc&id=1', $normalized['url'] ?? null);
    }
}
