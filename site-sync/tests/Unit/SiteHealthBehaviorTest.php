<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use App\Core\Capability\CapabilityRegistry;
use App\Models\Site;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SiteSync\Contracts\SiteHealthNotificationCapability;
use Omnichannel\Addons\SiteSync\Models\SiteHealthIncident;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\DnsResolver;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\ManagedWordPressSiteSelector;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthCheckService;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthResult;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthStateService;
use RuntimeException;
use Tests\TestCase;

final class SiteHealthBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        $this->createHealthTables();
    }

    public function test_reserved_invalid_domain_fails_at_dns_stage(): void
    {
        $dns = new DnsResolver();
        self::assertFalse($dns->resolves('site-health-test.invalid'));

        $site = new Site(['domain' => 'https://site-health-test.invalid', 'status' => 'active']);
        $result = (new SiteHealthCheckService($dns, app(WordPressSiteSyncClient::class)))->check($site);

        self::assertFalse($result->healthy);
        self::assertSame('down', $result->status);
        self::assertSame('critical', $result->severity);
        self::assertSame('DNS_ERROR', $result->errorCode);
        self::assertSame('failed', $result->stages['dns']['status']);
    }

    public function test_public_site_ok_and_heartbeat_failure_is_bridge_degradation(): void
    {
        $site = $this->managedSite('127.0.0.1');
        Http::fake([
            'https://127.0.0.1/wp-json/omi-seo-ai/v1/heartbeat' => Http::response([], 503),
            'https://127.0.0.1' => Http::response('ok', 200),
        ]);

        $result = app(SiteHealthCheckService::class)->check($site);

        self::assertFalse($result->healthy);
        self::assertSame('degraded', $result->status);
        self::assertSame('WP_BRIDGE_UNREACHABLE', $result->errorCode);
        self::assertNotContains($result->errorCode, ['DNS_ERROR', 'SITE_UNREACHABLE']);
    }

    public function test_heartbeat_auth_failure_has_specific_classification(): void
    {
        $site = $this->managedSite('127.0.0.2');
        Http::fake([
            'https://127.0.0.2/wp-json/omi-seo-ai/v1/heartbeat' => Http::response([], 403),
            'https://127.0.0.2' => Http::response('ok', 200),
        ]);

        $result = app(SiteHealthCheckService::class)->check($site);

        self::assertSame('degraded', $result->status);
        self::assertSame('WP_BRIDGE_AUTH_ERROR', $result->errorCode);
    }

    public function test_health_lifecycle_and_notifications_preserve_incident_history(): void
    {
        $notifications = new RecordingSiteHealthNotifications();
        $registry = new CapabilityRegistry();
        $registry->register(SiteHealthNotificationCapability::ID, $notifications, 'test');
        $service = new SiteHealthStateService($registry);
        $site = new Site(['domain' => 'site-health-test.invalid', 'status' => 'active']);
        $site->id = 91;
        $failure = SiteHealthResult::failed('down', 'critical', 'DNS_ERROR', 'DNS failed', ['dns' => ['status' => 'failed']]);

        $state = $service->record($site, $failure);
        self::assertSame('suspected', $state->status);
        self::assertSame(1, $state->consecutive_failures);
        self::assertNull($state->active_incident_id);
        self::assertSame([], $notifications->activeIncidentIds);

        $state = $service->record($site, $failure);
        $firstIncidentId = (int) $state->active_incident_id;
        self::assertSame('down', $state->status);
        self::assertGreaterThan(0, $firstIncidentId);
        self::assertSame([$firstIncidentId], $notifications->activeIncidentIds);

        $state = $service->record($site, $failure);
        self::assertSame($firstIncidentId, (int) $state->active_incident_id);
        self::assertSame(1, SiteHealthIncident::query()->count());

        $state = $service->record($site, SiteHealthResult::ok(['dns' => ['status' => 'ok']]));
        self::assertSame('healthy', $state->status);
        self::assertNull($state->active_incident_id);
        self::assertSame(0, $state->consecutive_failures);
        $firstIncident = SiteHealthIncident::query()->findOrFail($firstIncidentId);
        self::assertSame('resolved', $firstIncident->status);
        self::assertNotNull($firstIncident->resolved_at);
        self::assertSame([$firstIncidentId], $notifications->resolvedIncidentIds);

        $service->record($site, $failure);
        $state = $service->record($site, $failure);
        $secondIncidentId = (int) $state->active_incident_id;
        self::assertNotSame($firstIncidentId, $secondIncidentId);
        self::assertSame($secondIncidentId, $notifications->activeIncidentIds[array_key_last($notifications->activeIncidentIds)]);
        self::assertNotNull(SiteHealthIncident::query()->findOrFail($firstIncidentId)->resolved_at);
    }

    public function test_notification_failure_cannot_roll_back_confirmed_outage(): void
    {
        $registry = new CapabilityRegistry();
        $registry->register(SiteHealthNotificationCapability::ID, new ThrowingSiteHealthNotifications(), 'test');
        $service = new SiteHealthStateService($registry);
        $site = new Site(['domain' => 'site-health-test.invalid', 'status' => 'active']);
        $site->id = 92;
        $failure = SiteHealthResult::failed('down', 'critical', 'DNS_ERROR', 'DNS failed', ['dns' => ['status' => 'failed']]);

        $service->record($site, $failure);
        $state = $service->record($site, $failure);

        self::assertSame('down', $state->status);
        self::assertNotNull($state->active_incident_id);
        self::assertSame(1, SiteHealthIncident::query()->where('site_id', 92)->count());
    }

    public function test_managed_selector_uses_token_without_wp_headless_binding(): void
    {
        $managed = $this->managedSite('managed.test');
        $empty = $this->site('empty.test', 'active');
        $empty->metas()->create(['meta_key' => 'seo_read_token', 'meta_value' => '   ']);
        $inactive = $this->site('inactive.test', 'inactive');
        $inactive->metas()->create(['meta_key' => 'seo_read_token', 'meta_value' => 'token']);

        $ids = app(ManagedWordPressSiteSelector::class)->query()->pluck('id')->all();

        self::assertContains($managed->id, $ids);
        self::assertNotContains($empty->id, $ids);
        self::assertNotContains($inactive->id, $ids);
    }

    private function managedSite(string $domain): Site
    {
        $site = $this->site($domain, 'active');
        $site->metas()->create(['meta_key' => 'seo_read_token', 'meta_value' => 'test-token']);

        return $site;
    }

    private function site(string $domain, string $status): Site
    {
        return Site::query()->create(['domain' => $domain, 'status' => $status]);
    }

    private function createCoreTables(): void
    {
        Schema::dropIfExists('site_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('site_meta');
        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->unique();
            $table->string('status')->default('active');
            $table->boolean('ssl')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('site_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->timestamps();
        });
        Schema::create('site_services', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('service_id');
            $table->string('status');
            $table->timestamps();
        });
    }

    private function createHealthTables(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->dropIfExists('seo_site_health_states');
        $schema->dropIfExists('seo_site_health_incidents');
        $schema->create('seo_site_health_incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('status');
            $table->string('severity');
            $table->string('error_code');
            $table->text('reason')->nullable();
            $table->text('technical_error')->nullable();
            $table->json('diagnostic_stages')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('last_occurred_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_site_health_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->unique();
            $table->unsignedBigInteger('active_incident_id')->nullable();
            $table->string('status')->default('healthy');
            $table->string('severity')->nullable();
            $table->string('error_code')->nullable();
            $table->text('reason')->nullable();
            $table->text('technical_error')->nullable();
            $table->json('diagnostic_stages')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('first_failure_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });
    }
}

final class RecordingSiteHealthNotifications implements SiteHealthNotificationCapability
{
    /** @var list<int> */
    public array $activeIncidentIds = [];

    /** @var list<int> */
    public array $resolvedIncidentIds = [];

    public function incidentActive(Site $site, SiteHealthIncident $incident): void
    {
        $this->activeIncidentIds[] = (int) $incident->id;
    }

    public function incidentResolved(Site $site, SiteHealthIncident $incident): void
    {
        $this->resolvedIncidentIds[] = (int) $incident->id;
    }
}

final class ThrowingSiteHealthNotifications implements SiteHealthNotificationCapability
{
    public function incidentActive(Site $site, SiteHealthIncident $incident): void
    {
        throw new RuntimeException('notification unavailable');
    }

    public function incidentResolved(Site $site, SiteHealthIncident $incident): void
    {
        throw new RuntimeException('notification unavailable');
    }
}
