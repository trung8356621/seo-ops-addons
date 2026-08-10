<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpOwnDomainDetector;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;

final class SerpUrlNormalizationTest extends TestCase
{
    private SerpUrlNormalizationService $normalizer;

    private SerpOwnDomainDetector $ownDomain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new SerpUrlNormalizationService;
        $this->ownDomain = new SerpOwnDomainDetector($this->normalizer);
    }

    public function test_strips_tracking_params_keeps_business_params(): void
    {
        $result = $this->normalizer->normalize(
            'https://WWW.Example.com/path?utm_source=newsletter&gclid=abc&product=seo#section',
        );

        self::assertSame('https://example.com/path?product=seo', $result['normalized_url']);
        self::assertSame('example.com', $result['normalized_domain']);
        self::assertStringNotContainsString('utm_', $result['normalized_url']);
        self::assertStringNotContainsString('gclid', $result['normalized_url']);
        self::assertStringNotContainsString('#', $result['normalized_url']);
    }

    public function test_strips_fbclid_and_utm_prefix(): void
    {
        $result = $this->normalizer->normalize('https://shop.test/item?fbclid=xyz&utm_campaign=spring&sku=42');

        self::assertSame('https://shop.test/item?sku=42', $result['normalized_url']);
    }

    public function test_own_domain_detector_rejects_suffix_attack(): void
    {
        self::assertTrue($this->ownDomain->isOwnDomain('www.example.com', ['example.com']));
        self::assertTrue($this->ownDomain->isOwnDomain('blog.example.com', ['example.com']));
        self::assertFalse($this->ownDomain->isOwnDomain('example.com.evil.com', ['example.com']));
    }
}
