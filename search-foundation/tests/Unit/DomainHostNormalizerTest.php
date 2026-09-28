<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Support\DomainHostNormalizer;
use PHPUnit\Framework\TestCase;

final class DomainHostNormalizerTest extends TestCase
{
    public function test_normal_domain_url_with_path_and_query(): void
    {
        // 1. HTTPS://WWW.Example.COM/path?q=1 -> example.com
        self::assertSame('example.com', DomainHostNormalizer::normalizeStrict('HTTPS://WWW.Example.COM/path?q=1'));
    }

    public function test_normal_domain_with_www(): void
    {
        // 2. www.example.com -> example.com
        self::assertSame('example.com', DomainHostNormalizer::normalizeStrict('www.example.com'));
    }

    public function test_normal_domain_with_trailing_dot(): void
    {
        // 3. example.com. -> example.com
        self::assertSame('example.com', DomainHostNormalizer::normalizeStrict('example.com.'));
    }

    public function test_preserves_subdomain(): void
    {
        // 4. news.example.com -> news.example.com
        self::assertSame('news.example.com', DomainHostNormalizer::normalizeStrict('news.example.com'));
    }

    public function test_collapses_duplicate_variants(): void
    {
        // 5. duplicate variants collapse:
        // example.com, https://www.example.com/x, EXAMPLE.COM -> one example.com
        $inputs = [
            'example.com',
            'https://www.example.com/x',
            'EXAMPLE.COM',
        ];
        self::assertSame(['example.com'], DomainHostNormalizer::normalizeStrictList($inputs));
    }

    public function test_scheme_relative_url(): void
    {
        // 6. scheme-relative: //www.example.com/foo -> example.com
        self::assertSame('example.com', DomainHostNormalizer::normalizeStrict('//www.example.com/foo'));
    }

    public function test_invalid_javascript_scheme(): void
    {
        // 7. javascript:alert(1) -> invalid
        self::assertNull(DomainHostNormalizer::normalizeStrict('javascript:alert(1)'));
        self::assertNull(DomainHostNormalizer::normalizeTrustedPattern('javascript:alert(1)'));
    }

    public function test_invalid_mailto_scheme(): void
    {
        // 8. mailto:test@example.com -> invalid
        self::assertNull(DomainHostNormalizer::normalizeStrict('mailto:test@example.com'));
        self::assertNull(DomainHostNormalizer::normalizeTrustedPattern('mailto:test@example.com'));
    }

    public function test_invalid_domain_with_spaces(): void
    {
        // 9. foo bar.com -> invalid
        self::assertNull(DomainHostNormalizer::normalizeStrict('foo bar.com'));
        self::assertNull(DomainHostNormalizer::normalizeTrustedPattern('foo bar.com'));
    }

    public function test_invalid_single_label_domain(): void
    {
        // 10. abc -> invalid
        self::assertNull(DomainHostNormalizer::normalizeStrict('abc'));
        self::assertNull(DomainHostNormalizer::normalizeTrustedPattern('abc'));
    }

    public function test_invalid_other_malformed_inputs(): void
    {
        self::assertNull(DomainHostNormalizer::normalizeStrict('@'));
        self::assertNull(DomainHostNormalizer::normalizeStrict('://bad'));
        self::assertNull(DomainHostNormalizer::normalizeStrict('tel:123'));
        self::assertNull(DomainHostNormalizer::normalizeStrict('http://'));
    }

    public function test_trusted_pattern_wildcards(): void
    {
        // 11. *.gov -> valid
        self::assertSame('*.gov', DomainHostNormalizer::normalizeTrustedPattern('*.gov'));

        // 12. *.edu -> valid
        self::assertSame('*.edu', DomainHostNormalizer::normalizeTrustedPattern('*.edu'));

        // 13. *.example.com -> valid
        self::assertSame('*.example.com', DomainHostNormalizer::normalizeTrustedPattern('*.example.com'));

        // 14. invalid wildcard forms -> rejected
        self::assertNull(DomainHostNormalizer::normalizeTrustedPattern('*.*'));
        self::assertNull(DomainHostNormalizer::normalizeTrustedPattern('*.'));
        self::assertNull(DomainHostNormalizer::normalizeTrustedPattern('*gov'));
        self::assertNull(DomainHostNormalizer::normalizeTrustedPattern('foo.*'));
        self::assertNull(DomainHostNormalizer::normalizeTrustedPattern('foo.*.com'));
        self::assertNull(DomainHostNormalizer::normalizeTrustedPattern('*'));
    }

    public function test_social_domains_strictly_reject_wildcards(): void
    {
        // 15. full Facebook URL -> facebook.com
        self::assertSame('facebook.com', DomainHostNormalizer::normalizeStrict('HTTPS://WWW.Facebook.COM/something'));

        // 16. wildcard social domain -> rejected
        self::assertNull(DomainHostNormalizer::normalizeStrict('*.facebook.com'));
        self::assertNull(DomainHostNormalizer::normalizeStrict('*.com'));
    }
}
