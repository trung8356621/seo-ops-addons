<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Semantic;

use App\Core\Capability\CapabilityRegistry;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SearchIntelligence\Contracts\SemanticServiceNotificationCapability;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTimeoutException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTransportException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticUnavailableException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticServiceHealthMonitor;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticServiceHealthProbe;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticServiceHealthReporter;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticServiceHealthSnapshot;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticServiceHealthStatus;
use Tests\TestCase;

final class SemanticServiceHealthReporterTest extends TestCase
{
    private RecordingSemanticNotifications $recording;

    private CapabilityRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.enabled' => true,
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 30,
            'semantic.connect_timeout' => 2,
        ]);
        $this->recording = new RecordingSemanticNotifications;
        $this->registry = new CapabilityRegistry;
        $this->registry->register(
            SemanticServiceNotificationCapability::ID,
            $this->recording,
            'test',
        );
    }

    public function test_healthy_with_no_prior_incident_calls_recovered_idempotently(): void
    {
        Http::fake([
            'semantic.test/health/ready' => Http::response(['status' => 'ok', 'ready' => true], 200),
        ]);
        $monitor = new SemanticServiceHealthMonitor(
            new SemanticServiceHealthProbe,
            new SemanticServiceHealthReporter($this->registry),
        );
        $monitor->check();
        self::assertSame(['recovered'], $this->recording->calls);
    }

    public function test_connection_unavailable_reports_unavailable(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });
        $monitor = new SemanticServiceHealthMonitor(
            new SemanticServiceHealthProbe,
            new SemanticServiceHealthReporter($this->registry),
        );
        $snapshot = $monitor->check();
        self::assertSame(SemanticServiceHealthStatus::Down, $snapshot->status);
        self::assertSame(['unavailable'], $this->recording->calls);
    }

    public function test_not_ready_reports_degraded(): void
    {
        Http::fake([
            'semantic.test/health/ready' => Http::response(['ready' => false], 503),
        ]);
        $monitor = new SemanticServiceHealthMonitor(
            new SemanticServiceHealthProbe,
            new SemanticServiceHealthReporter($this->registry),
        );
        $monitor->check();
        self::assertSame(['degraded'], $this->recording->calls);
    }

    public function test_repeated_failure_reports_same_channel_without_new_capability_types(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });
        $monitor = new SemanticServiceHealthMonitor(
            new SemanticServiceHealthProbe,
            new SemanticServiceHealthReporter($this->registry),
        );
        $monitor->check();
        $monitor->check();
        self::assertSame(['unavailable', 'unavailable'], $this->recording->calls);
    }

    public function test_recovery_after_down(): void
    {
        $reporter = new SemanticServiceHealthReporter($this->registry);
        $reporter->report(new SemanticServiceHealthSnapshot(
            SemanticServiceHealthStatus::Down,
            'semantic_unavailable',
            'down',
        ));
        $reporter->report(new SemanticServiceHealthSnapshot(
            SemanticServiceHealthStatus::Healthy,
            '',
            'ok',
        ));
        self::assertSame(['unavailable', 'recovered'], $this->recording->calls);
    }

    public function test_disabled_skips_alerts(): void
    {
        config(['semantic.enabled' => false]);
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });
        $monitor = new SemanticServiceHealthMonitor(
            new SemanticServiceHealthProbe,
            new SemanticServiceHealthReporter($this->registry),
        );
        $monitor->check();
        self::assertSame([], $this->recording->calls);
    }

    public function test_client_transport_failure_reports_down(): void
    {
        $reporter = new SemanticServiceHealthReporter($this->registry);
        $client = new SemanticAnalyticsClient(healthReporter: $reporter);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });
        try {
            $client->getJson('/health/ready');
            self::fail('expected unavailable');
        } catch (SemanticUnavailableException) {
            // expected
        }
        self::assertSame(['unavailable'], $this->recording->calls);
    }

    public function test_client_422_does_not_report_service_down(): void
    {
        $reporter = new SemanticServiceHealthReporter($this->registry);
        $client = new SemanticAnalyticsClient(healthReporter: $reporter);
        Http::fake([
            'semantic.test/*' => Http::response(['detail' => 'bad'], 422),
        ]);
        try {
            $client->postJson('/v1/topic/analyses', ['site_ref' => '1', 'keywords' => []]);
            self::fail('expected transport');
        } catch (SemanticTransportException) {
            // expected — service answered with application error
        }
        self::assertSame([], $this->recording->calls);
    }

    public function test_client_success_reports_reachable(): void
    {
        $reporter = new SemanticServiceHealthReporter($this->registry);
        $client = new SemanticAnalyticsClient(healthReporter: $reporter);
        Http::fake([
            'semantic.test/health/ready' => Http::response(['ready' => true], 200),
        ]);
        $client->getJson('/health/ready');
        self::assertSame(['recovered'], $this->recording->calls);
    }

    public function test_timeout_reports_unavailable_channel(): void
    {
        $reporter = new SemanticServiceHealthReporter($this->registry);
        $client = new SemanticAnalyticsClient(healthReporter: $reporter);
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 28: Operation timed out after 120001 milliseconds',
            );
        });
        try {
            $client->postJson('/v1/x', []);
            self::fail('expected timeout');
        } catch (SemanticTimeoutException) {
        }
        self::assertSame(['unavailable'], $this->recording->calls);
    }
}

final class RecordingSemanticNotifications implements SemanticServiceNotificationCapability
{
    /** @var list<string> */
    public array $calls = [];

    public function serviceUnavailable(array $context = []): void
    {
        $this->calls[] = 'unavailable';
    }

    public function serviceDegraded(array $context = []): void
    {
        $this->calls[] = 'degraded';
    }

    public function serviceRecovered(array $context = []): void
    {
        $this->calls[] = 'recovered';
    }
}
