<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleContentSslUrlNormalizer;
use App\Models\Site;
use Tests\TestCase;

final class ArticleContentSslUrlNormalizerTest extends TestCase
{
    public function test_upgrades_http_urls_for_ssl_site_domain(): void
    {
        $site = new Site([
            'domain' => 'congtybalo.com',
            'ssl' => true,
        ]);

        $html = '<img src="http://congtybalo.com/wp-content/uploads/photo.jpg" />';
        $normalized = app(ArticleContentSslUrlNormalizer::class)->normalizeForSite($html, $site);

        $this->assertStringContainsString('https://congtybalo.com/wp-content/uploads/photo.jpg', $normalized);
        $this->assertStringNotContainsString('http://congtybalo.com/', $normalized);
    }

    public function test_leaves_http_urls_when_ssl_disabled(): void
    {
        $site = new Site([
            'domain' => 'congtybalo.com',
            'ssl' => false,
        ]);

        $html = '<img src="http://congtybalo.com/wp-content/uploads/photo.jpg" />';
        $normalized = app(ArticleContentSslUrlNormalizer::class)->normalizeForSite($html, $site);

        $this->assertSame($html, $normalized);
    }

    public function test_does_not_touch_external_http_urls(): void
    {
        $site = new Site([
            'domain' => 'congtybalo.com',
            'ssl' => true,
        ]);

        $html = '<a href="http://example.com/page">link</a>';
        $normalized = app(ArticleContentSslUrlNormalizer::class)->normalizeForSite($html, $site);

        $this->assertSame($html, $normalized);
    }
}
