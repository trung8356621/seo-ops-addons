<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Services\SeedingLinkPreviewService;
use Omnichannel\Addons\Seeding\Support\SeedingOutboundUrlPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeedingLinkPreviewUnfurlContractTest extends TestCase
{
    private function service(): SeedingLinkPreviewService
    {
        return new SeedingLinkPreviewService(new SeedingOutboundUrlPolicy);
    }

    public function test_open_graph_title_description_image(): void
    {
        $html = <<<'HTML'
<!doctype html><html><head>
<meta property="og:title" content="Balo travel" />
<meta property="og:description" content="Nhẹ và bền" />
<meta property="og:image" content="https://cdn.example.com/img.jpg" />
<title>Fallback</title>
</head><body></body></html>
HTML;
        $meta = $this->service()->parseHtmlMeta($html, 'https://example.com/product');
        self::assertSame('Balo travel', $meta['title']);
        self::assertSame('Nhẹ và bền', $meta['description']);
        self::assertSame('https://cdn.example.com/img.jpg', $meta['image']);
    }

    public function test_twitter_card_fallback(): void
    {
        $html = <<<'HTML'
<html><head>
<meta name="twitter:title" content="Twitter Title" />
<meta name="twitter:description" content="Twitter Desc" />
<meta name="twitter:image" content="https://cdn.example.com/tw.jpg" />
</head><body></body></html>
HTML;
        $meta = $this->service()->parseHtmlMeta($html, 'https://example.com/x');
        self::assertSame('Twitter Title', $meta['title']);
        self::assertSame('Twitter Desc', $meta['description']);
        self::assertSame('https://cdn.example.com/tw.jpg', $meta['image']);
    }

    public function test_json_ld_image_object_and_array_shapes(): void
    {
        $html = <<<'HTML'
<html><head>
<script type="application/ld+json">
{"@type":"Product","name":"Túi xách","image":{"url":"https://cdn.example.com/ld.jpg"}}
</script>
</head><body></body></html>
HTML;
        $meta = $this->service()->parseHtmlMeta($html, 'https://shop.example.com/p');
        self::assertSame('Túi xách', $meta['title']);
        self::assertSame('https://cdn.example.com/ld.jpg', $meta['image']);
    }

    public function test_link_rel_image_src(): void
    {
        $html = <<<'HTML'
<html><head>
<link rel="image_src" href="/media/hero.jpg" />
<title>Rel image</title>
</head><body></body></html>
HTML;
        $meta = $this->service()->parseHtmlMeta($html, 'https://example.com/page');
        self::assertSame('https://example.com/media/hero.jpg', $meta['image']);
    }

    public function test_relative_image_and_base_href_resolution(): void
    {
        $html = <<<'HTML'
<html><head>
<base href="https://cdn.example.com/assets/" />
<meta property="og:image" content="photos/a.jpg" />
<title>Base</title>
</head><body></body></html>
HTML;
        $meta = $this->service()->parseHtmlMeta($html, 'https://example.com/page');
        self::assertSame('https://cdn.example.com/assets/photos/a.jpg', $meta['image']);
    }

    public function test_malformed_html_still_yields_og_via_dom_tolerance(): void
    {
        $html = '<html><head><meta property="og:title" content="Broken"><title>T</title><body><p>no close';
        $meta = $this->service()->parseHtmlMeta($html, 'https://example.com/');
        self::assertSame('Broken', $meta['title']);
    }

    public function test_fallback_prefers_article_image_over_logo(): void
    {
        $html = <<<'HTML'
<html><body>
<img src="/logo.png" class="site-logo" width="40" height="40" />
<article>
  <img src="/content/hero-large.jpg" width="640" height="360" alt="product" />
</article>
</body></html>
HTML;
        $meta = $this->service()->parseHtmlMeta($html, 'https://example.com/story');
        self::assertSame('https://example.com/content/hero-large.jpg', $meta['image']);
    }

    public function test_rejects_tiny_and_icon_candidates(): void
    {
        $html = <<<'HTML'
<html><body>
<img src="/favicon.ico" width="16" height="16" />
<img src="/avatar.png" class="user-avatar" width="48" height="48" />
<img src="/pixel.gif" width="1" height="1" />
</body></html>
HTML;
        $meta = $this->service()->parseHtmlMeta($html, 'https://example.com/');
        self::assertNull($meta['image']);
        self::assertNull($meta['title']);
    }

    public function test_missing_image_keeps_title_and_domain_usable(): void
    {
        $html = <<<'HTML'
<html><head>
<meta property="og:title" content="Only text" />
<meta property="og:description" content="No image here" />
</head><body></body></html>
HTML;
        $meta = $this->service()->parseHtmlMeta($html, 'https://news.example.com/a');
        self::assertSame('Only text', $meta['title']);
        self::assertSame('No image here', $meta['description']);
        self::assertNull($meta['image']);
    }

    public function test_ssrf_policy_still_blocks_private_hosts(): void
    {
        $policy = new SeedingOutboundUrlPolicy;
        $this->expectException(InvalidArgumentException::class);
        $policy->assertSafeUrl('http://127.0.0.1/x');
    }

    public function test_image_proxy_cache_and_route_registered(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/SeedingServiceProvider.php'
        );
        $cache = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/SeedingLinkPreviewImageCache.php'
        );
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Http/Controllers/SeedingLinkPreviewImageController.php'
        );
        $service = (string) file_get_contents(
            (new ReflectionClass(SeedingLinkPreviewService::class))->getFileName()
        );

        self::assertStringContainsString('link-preview/image/{hash}', $provider);
        self::assertStringContainsString('SeedingLinkPreviewImageController', $provider);
        self::assertStringContainsString('cachePublicImage', $cache);
        self::assertStringContainsString('readCached', $cache);
        self::assertStringContainsString('SeedingOutboundUrlPolicy', $cache);
        self::assertStringContainsString('cachePublicImage', $service);
        self::assertFileExists(dirname(__DIR__, 2).'/src/Http/Controllers/SeedingLinkPreviewImageController.php');
        self::assertStringContainsString('assertCanAccess', $controller);
    }

    public function test_frontend_omits_empty_media_placeholder(): void
    {
        $card = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/seeding/components/LinkPreviewCard.jsx'
        );
        $css = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/css/seeding-workspace.css'
        );
        $pipeline = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/seeding/services/linkPreviewPipeline.js'
        );

        self::assertStringContainsString('is-no-media', $card);
        self::assertStringNotContainsString('link-preview-media--empty', $card);
        self::assertStringContainsString('PREVIEW_RETRY_TTL_MS', $pipeline);
        self::assertStringContainsString('isPreviewCacheFresh', $pipeline);
        self::assertStringContainsString('.seeding-ws__link-preview-media--empty', $css);
        self::assertStringContainsString('display: none', $css);
    }
}
