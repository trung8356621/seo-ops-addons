<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Services\SeedingLinkPreviewService;
use Omnichannel\Addons\Seeding\Support\SeedingOutboundUrlPolicy;
use Omnichannel\Addons\Seeding\Support\SeedingTopicAuthorization;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeedingLinkPreviewAndAuthContractTest extends TestCase
{
    public function test_outbound_policy_blocks_localhost_and_private_ips(): void
    {
        $policy = new SeedingOutboundUrlPolicy;

        foreach ([
            'http://127.0.0.1/x',
            'http://localhost/x',
            'http://10.0.0.5/x',
            'http://192.168.1.1/x',
            'ftp://example.com/x',
        ] as $url) {
            try {
                $policy->assertSafeUrl($url);
                self::fail('Expected InvalidArgumentException for '.$url);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_outbound_policy_allows_public_https_host_shape(): void
    {
        $policy = new SeedingOutboundUrlPolicy;
        // May fail DNS in offline CI — only assert scheme/host parse path for IP literals blocked above.
        // Public hostname: assert does not throw on scheme alone by using a resolvable public host when possible.
        try {
            $policy->assertSafeUrl('https://example.com/path');
            self::assertTrue(true);
        } catch (InvalidArgumentException $e) {
            // Offline / DNS failure is acceptable in locked-down CI.
            self::assertStringContainsString('resolv', strtolower($e->getMessage()));
        }
    }

    public function test_parse_html_meta_reads_open_graph(): void
    {
        $service = new SeedingLinkPreviewService;
        $html = <<<'HTML'
<!doctype html><html><head>
<meta property="og:title" content="Balo travel" />
<meta property="og:description" content="Nhẹ và bền" />
<meta property="og:image" content="https://cdn.example.com/img.jpg" />
<title>Fallback</title>
</head><body></body></html>
HTML;

        $meta = $service->parseHtmlMeta($html, 'https://example.com/product');
        self::assertSame('Balo travel', $meta['title']);
        self::assertSame('Nhẹ và bền', $meta['description']);
        self::assertSame('https://cdn.example.com/img.jpg', $meta['image']);
    }

    public function test_authorization_edit_is_author_only(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeedingTopicAuthorization::class))->getFileName()
        );
        self::assertStringContainsString('canEditTopic', $source);
        self::assertStringContainsString('canDeleteTopic', $source);
        self::assertStringContainsString('canEditComment', $source);
        self::assertStringContainsString('canDeleteComment', $source);
        self::assertStringContainsString('(int) $user->id === $createdByUserId', $source);
        self::assertStringContainsString('(int) $user->id === $authorUserId', $source);
    }

    public function test_topic_controller_uses_authz_on_update_destroy(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Http/Controllers/SeedingTopicController.php'
        );
        self::assertStringContainsString('SeedingTopicAuthorization', $controller);
        self::assertStringContainsString('canEditTopic', $controller);
        self::assertStringContainsString('canDeleteTopic', $controller);
        self::assertStringContainsString('abort_unless', $controller);
    }

    public function test_link_preview_route_registered(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/SeedingServiceProvider.php'
        );
        self::assertStringContainsString('link-preview', $provider);
        self::assertStringContainsString('SeedingLinkPreviewController', $provider);
        self::assertStringContainsString('SeedingLinkPreviewService', $provider);
        self::assertStringContainsString('SeedingOutboundUrlPolicy', $provider);
    }
}
