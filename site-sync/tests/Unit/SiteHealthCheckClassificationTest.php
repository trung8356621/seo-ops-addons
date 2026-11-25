<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthCheckService;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthSanitizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SiteHealthCheckClassificationTest extends TestCase
{
    public function test_timeout_tls_and_connection_errors_have_stable_codes(): void
    {
        self::assertSame('CONNECTION_TIMEOUT', SiteHealthCheckService::classifyException(new RuntimeException('Operation timed out')));
        self::assertSame('TLS_ERROR', SiteHealthCheckService::classifyException(new RuntimeException('SSL certificate failed')));
        self::assertSame('CONNECTION_ERROR', SiteHealthCheckService::classifyException(new ConnectionException('Connection refused')));
    }

    public function test_secret_values_are_removed_from_detail_payloads(): void
    {
        $clean = SiteHealthSanitizer::clean('Bearer abc123 token=secret password:hunter2');
        self::assertStringNotContainsString('abc123', (string) $clean);
        self::assertStringNotContainsString('hunter2', (string) $clean);
        self::assertStringNotContainsString('=secret', (string) $clean);
    }

    public function test_pipeline_is_dns_and_hosting_availability_only(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(SiteHealthCheckService::class))->getFileName());
        self::assertStringContainsString("'DNS_ERROR'", $source);
        self::assertStringContainsString("'TLS_ERROR'", $source);
        self::assertStringContainsString('public_site', $source);
        self::assertStringNotContainsString("'HTTP_5XX'", $source);
        self::assertStringNotContainsString("'WP_BRIDGE_UNREACHABLE'", $source);
        self::assertStringNotContainsString("'WP_BRIDGE_AUTH_ERROR'", $source);
        self::assertStringNotContainsString("'HEARTBEAT_INVALID'", $source);
        self::assertStringNotContainsString('/omi-seo-ai/v1/heartbeat', $source);
        self::assertStringNotContainsString('/omi-seo-ai/v1/capabilities', $source);
        self::assertStringNotContainsString('healthProbe', $source);
    }
}
