<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpPageEvidenceFetcher;
use PHPUnit\Framework\TestCase;

final class SerpPageEvidenceSecurityTest extends TestCase
{
    private SerpPageEvidenceFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetcher = new SerpPageEvidenceFetcher;
    }

    /**
     * @return list<string>
     */
    public static function blockedUrlProvider(): array
    {
        return [
            'localhost' => ['http://localhost/admin'],
            'loopback' => ['http://127.0.0.1/secret'],
            'metadata_ip' => ['http://169.254.169.254/latest/meta-data/'],
            'private_10' => ['http://10.0.0.5/internal'],
            'file_scheme' => ['file:///etc/passwd'],
            'javascript_scheme' => ['javascript:alert(1)'],
        ];
    }

    /**
     * @dataProvider blockedUrlProvider
     */
    public function test_validate_url_for_fetch_blocks_unsafe_targets(string $url): void
    {
        $result = $this->fetcher->validateUrlForFetch($url);

        self::assertFalse($result['allowed']);
        self::assertNotNull($result['error_code']);
    }

    public function test_public_https_url_allowed_in_metadata_mode(): void
    {
        $result = $this->fetcher->validateUrlForFetch('https://example.com/article', ['mode' => SerpPageEvidenceFetcher::MODE_METADATA_ONLY]);

        self::assertTrue($result['allowed']);
        self::assertNull($result['error_code']);
    }
}
