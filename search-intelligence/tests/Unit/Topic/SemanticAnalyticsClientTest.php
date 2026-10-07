<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticInvalidResponseException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTimeoutException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTransportException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticUnavailableException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use ReflectionMethod;
use Tests\TestCase;

final class SemanticAnalyticsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 120,
            'semantic.connect_timeout' => 5,
        ]);
    }

    public function test_successful_json_get(): void
    {
        Http::fake([
            'semantic.test/health/ready' => Http::response([
                'status' => 'ok',
                'ready' => true,
            ], 200),
        ]);

        $client = new SemanticAnalyticsClient;
        $json = $client->getJson('/health/ready');

        self::assertSame('ok', $json['status']);
        Http::assertSentCount(1);
    }

    public function test_uses_configured_request_and_connect_timeouts(): void
    {
        config([
            'semantic.timeout' => 120,
            'semantic.connect_timeout' => 5,
        ]);
        $client = new SemanticAnalyticsClient;

        $timeout = new ReflectionMethod(SemanticAnalyticsClient::class, 'resolvedTimeout');
        $timeout->setAccessible(true);
        $connect = new ReflectionMethod(SemanticAnalyticsClient::class, 'resolvedConnectTimeout');
        $connect->setAccessible(true);

        self::assertSame(120, $timeout->invoke($client));
        self::assertSame(5, $connect->invoke($client));

        $override = new SemanticAnalyticsClient(null, 90, 3);
        self::assertSame(90, $timeout->invoke($override));
        self::assertSame(3, $connect->invoke($override));
    }

    public function test_connection_failure(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $this->expectException(SemanticUnavailableException::class);
        (new SemanticAnalyticsClient)->getJson('/health/ready');
    }

    public function test_request_timeout_maps_to_semantic_timeout(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 28: Operation timed out after 30008 milliseconds with 0 bytes received',
            );
        });

        try {
            (new SemanticAnalyticsClient)->postJson('/v1/topic/analyses', [
                'site_ref' => '4',
                'keywords' => [],
            ]);
            self::fail('Expected SemanticTimeoutException');
        } catch (SemanticTimeoutException $e) {
            self::assertSame('semantic_timeout', $e->errorCode);
        }
    }

    public function test_post_is_not_retried_on_timeout(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received',
            );
        });

        try {
            (new SemanticAnalyticsClient)->postJson('/v1/topic/analyses', ['site_ref' => '1', 'keywords' => []]);
            self::fail('Expected SemanticTimeoutException');
        } catch (SemanticTimeoutException) {
            // expected — single attempt only
        }

        self::assertSame(1, $calls);
    }

    public function test_http_5xx(): void
    {
        Http::fake([
            'semantic.test/v1/topic/analyses' => Http::response(['detail' => 'boom'], 500),
        ]);

        $this->expectException(SemanticTransportException::class);
        (new SemanticAnalyticsClient)->postJson('/v1/topic/analyses', ['site_ref' => '1', 'keywords' => []]);
    }

    public function test_invalid_json_object(): void
    {
        Http::fake([
            'semantic.test/health/live' => Http::response('"not-an-object"', 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $this->expectException(SemanticInvalidResponseException::class);
        (new SemanticAnalyticsClient)->getJson('/health/live');
    }
}
