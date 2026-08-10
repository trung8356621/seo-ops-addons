<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleDomainMatcherService;
use PHPUnit\Framework\TestCase;

final class GoogleSearchConsoleDomainMatcherServiceTest extends TestCase
{
    private GoogleSearchConsoleDomainMatcherService $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new GoogleSearchConsoleDomainMatcherService();
    }

    public function test_normalize_host_strips_protocol_www_and_sc_domain(): void
    {
        $this->assertSame('example.com', $this->matcher->normalizeHost('HTTPS://WWW.Example.com/'));
        $this->assertSame('example.com', $this->matcher->normalizeHost('sc-domain:example.com'));
        $this->assertSame('shop.example.com', $this->matcher->normalizeHost('https://shop.example.com:443/'));
    }

    public function test_prefers_sc_domain_over_url_prefix(): void
    {
        $match = $this->matcher->findBestPropertyForSite('example.com', [
            'https://www.example.com/',
            'sc-domain:example.com',
            'https://example.com/',
        ]);

        $this->assertNotNull($match);
        $this->assertSame('matched', $match['status']);
        $this->assertSame('sc-domain:example.com', $match['property_url']);
    }

    public function test_does_not_fuzzy_match_subdomains(): void
    {
        $match = $this->matcher->findBestPropertyForSite('example.com', [
            'https://blog.example.com/',
        ]);

        $this->assertNull($match);
    }

    public function test_does_not_contains_match_partial_host(): void
    {
        $match = $this->matcher->findBestPropertyForSite('myexample.com', [
            'https://example.com/',
        ]);

        $this->assertNull($match);
    }

    public function test_returns_ambiguous_when_multiple_same_priority_candidates(): void
    {
        $match = $this->matcher->findBestPropertyForSite('example.com', [
            'https://example.com/',
            'https://example.com',
        ]);

        $this->assertNotNull($match);
        $this->assertSame('ambiguous', $match['status']);
        $this->assertCount(2, $match['candidates']);
    }

    public function test_property_priority_order(): void
    {
        $this->assertLessThan(
            $this->matcher->propertyPriority('https://example.com/'),
            $this->matcher->propertyPriority('sc-domain:example.com'),
        );
        $this->assertLessThan(
            $this->matcher->propertyPriority('https://www.example.com/'),
            $this->matcher->propertyPriority('https://example.com/'),
        );
    }
}
