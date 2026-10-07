<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticInvalidResponseException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTransportException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticUnavailableException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Tests\TestCase;

final class SemanticAnalyticsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 5,
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

    public function test_connection_failure(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $this->expectException(SemanticUnavailableException::class);
        (new SemanticAnalyticsClient)->getJson('/health/ready');
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
