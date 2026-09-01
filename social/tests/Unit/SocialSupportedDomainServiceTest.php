<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Tests\Unit;

use Omnichannel\Addons\Social\Services\SocialSupportedDomainService;
use PHPUnit\Framework\TestCase;

final class SocialSupportedDomainServiceTest extends TestCase
{
    private SocialSupportedDomainService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = SocialSupportedDomainService::withSupportedDomains([
            'facebook.com',
            'linkedin.com',
            'x.com',
            'example.social',
        ]);
    }

    public function test_normalizes_social_domains_from_textarea_like_input(): void
    {
        $domains = $this->service->normalizeDomains([
            ' Facebook.com ',
            'HTTPS://WWW.LINKEDIN.COM/company/acme',
            'x.com.',
            'example.social',
            'example.social',
        ]);

        self::assertSame([
            'facebook.com',
            'linkedin.com',
            'x.com',
            'example.social',
        ], $domains);
    }

    public function test_arbitrary_new_domain_can_be_added_to_allowlist(): void
    {
        $service = SocialSupportedDomainService::withSupportedDomains(['mastodon.social']);

        self::assertTrue($service->isAllowedUrl('https://mastodon.social/@user/123456789'));
        self::assertSame('mastodon.social', $service->resolveHost('https://mastodon.social/@user/123456789'));
    }

    public function test_subdomain_matches_allowed_root_domain(): void
    {
        self::assertTrue($this->service->hostMatchesAllowedDomain('www.facebook.com', 'facebook.com'));
        self::assertTrue($this->service->hostMatchesAllowedDomain('m.facebook.com', 'facebook.com'));
        self::assertTrue($this->service->isAllowedUrl('https://www.facebook.com/groups/test/posts/1'));
        self::assertTrue($this->service->isAllowedUrl('https://m.facebook.com/story.php?id=1'));
    }

    public function test_evilfacebook_com_does_not_match_facebook_com(): void
    {
        self::assertFalse($this->service->hostMatchesAllowedDomain('evilfacebook.com', 'facebook.com'));
        self::assertFalse($this->service->isAllowedUrl('https://evilfacebook.com/post/1'));
    }

    public function test_only_http_and_https_urls_are_accepted(): void
    {
        self::assertNull($this->service->resolveHost('ftp://facebook.com/post/1'));
        self::assertNull($this->service->resolveHost('javascript:alert(1)'));
        self::assertNull($this->service->resolveHost('not-a-url'));
        self::assertFalse($this->service->isAllowedUrl('ftp://facebook.com/post/1'));

        self::assertSame('facebook.com', $this->service->resolveHost('http://facebook.com/post/1'));
        self::assertSame('facebook.com', $this->service->resolveHost('https://facebook.com/post/1'));
    }

    public function test_domains_from_textarea_splits_by_newline(): void
    {
        self::assertSame([
            'facebook.com',
            'linkedin.com',
        ], SocialSupportedDomainService::withSupportedDomains([])->domainsFromTextarea("facebook.com\nlinkedin.com"));
    }

    public function test_normalize_domain_returns_null_for_invalid_label(): void
    {
        self::assertNull($this->service->normalizeDomain(''));
        self::assertNull($this->service->normalizeDomain('not a domain'));
    }
}
