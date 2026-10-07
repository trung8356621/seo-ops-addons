<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Semantic;

use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticServiceHealthProbe;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticServiceHealthStatus;
use Tests\TestCase;

final class SemanticServiceHealthProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 30,
            'semantic.connect_timeout' => 2,
        ]);
    }

    public function test_ready_response_is_healthy(): void
    {
        Http::fake([
            'semantic.test/health/ready' => Http::response([
                'status' => 'ok',
                'ready' => true,
            ], 200),
        ]);

        $snapshot = (new SemanticServiceHealthProbe)->probe();
        self::assertSame(SemanticServiceHealthStatus::Healthy, $snapshot->status);
        self::assertSame('semantic.test', $snapshot->baseHost);
    }

    public function test_ready_false_on_200_is_degraded(): void
    {
        Http::fake([
            'semantic.test/health/ready' => Http::response([
                'status' => 'degraded',
                'ready' => false,
            ], 200),
        ]);

        $snapshot = (new SemanticServiceHealthProbe)->probe();
        self::assertSame(SemanticServiceHealthStatus::Degraded, $snapshot->status);
        self::assertSame('semantic_not_ready', $snapshot->errorCode);
    }

    public function test_ready_false_on_503_is_degraded(): void
    {
        Http::fake([
            'semantic.test/health/ready' => Http::response([
                'status' => 'error',
                'ready' => false,
            ], 503),
        ]);

        $snapshot = (new SemanticServiceHealthProbe)->probe();
        self::assertSame(SemanticServiceHealthStatus::Degraded, $snapshot->status);
    }

    public function test_connection_unavailable_is_down(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $snapshot = (new SemanticServiceHealthProbe)->probe();
        self::assertSame(SemanticServiceHealthStatus::Down, $snapshot->status);
        self::assertSame('semantic_unavailable', $snapshot->errorCode);
    }

    public function test_timeout_is_down(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 28: Operation timed out after 5001 milliseconds',
            );
        });

        $snapshot = (new SemanticServiceHealthProbe)->probe();
        self::assertSame(SemanticServiceHealthStatus::Down, $snapshot->status);
        self::assertSame('semantic_timeout', $snapshot->errorCode);
    }

    public function test_gateway_without_ready_body_is_down(): void
    {
        Http::fake([
            'semantic.test/health/ready' => Http::response('Bad Gateway', 502),
        ]);

        $snapshot = (new SemanticServiceHealthProbe)->probe();
        self::assertSame(SemanticServiceHealthStatus::Down, $snapshot->status);
    }
}
