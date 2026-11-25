<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use App\Core\Capability\CapabilityRegistry;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Omnichannel\Addons\SiteSync\Contracts\SiteHealthNotificationCapability;
use Omnichannel\Addons\SiteSync\Models\SiteHealthIncident;
use Omnichannel\Addons\SiteSync\Livewire\SiteHealthNotice;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\DnsResolver;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\ManagedWordPressSiteSelector;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthCheckService;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthResult;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthStateService;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthMonitor;
use RuntimeException;
use Tests\TestCase;

final class SiteHealthBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        view()->addNamespace('site-sync', dirname(__DIR__, 2).'/resources/views');
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

    public function test_http_200_is_healthy(): void
    {
        $site = $this->managedSite('127.0.0.1');
        Http::fake(['https://127.0.0.1' => Http::response('ok', 200)]);

        $result = app(SiteHealthCheckService::class)->check($site);

        self::assertTrue($result->healthy);
        self::assertSame('ok', $result->stages['public_site']['status']);
        self::assertSame('HTTP 200', $result->stages['public_site']['detail']);
    }

    public function test_http_404_is_alive_not_down(): void
    {
        $site = $this->managedSite('127.0.0.1');
        Http::fake(['https://127.0.0.1' => Http::response('missing', 404)]);

        $result = app(SiteHealthCheckService::class)->check($site);

        self::assertTrue($result->healthy);
        self::assertSame('HTTP 404', $result->stages['public_site']['detail']);
    }

    public function test_http_500_is_alive_not_down(): void
    {
        $site = $this->managedSite('127.0.0.1');
        Http::fake(['https://127.0.0.1' => Http::response('error', 500)]);

        $result = app(SiteHealthCheckService::class)->check($site);

        self::assertTrue($result->healthy);
        self::assertSame('HTTP 500', $result->stages['public_site']['detail']);
    }

    public function test_homepage_ok_ignores_wordpress_bridge_endpoints(): void
    {
        $site = $this->managedSite('127.0.0.1');
        Http::fake([
            'https://127.0.0.1/wp-json/omi-seo-ai/v1/heartbeat' => Http::response([], 503),
            'https://127.0.0.1/wp-json/omi-seo-ai/v1/capabilities' => Http::response([], 403),
            'https://127.0.0.1' => Http::response('ok', 200),
        ]);

        $result = app(SiteHealthCheckService::class)->check($site);

        self::assertTrue($result->healthy);
        self::assertArrayNotHasKey('heartbeat', $result->stages);
        self::assertArrayNotHasKey('auth_plugin', $result->stages);
    }

    public function test_tls_error_is_recorded_without_hosting_down(): void
    {
        $site = $this->managedSite('127.0.0.3');
        Http::fake(fn () => throw new ConnectionException('SSL certificate problem: self signed certificate'));

        $result = app(SiteHealthCheckService::class)->check($site);

        self::assertTrue($result->healthy);
        self::assertSame('warning', $result->stages['connectivity']['status']);
        self::assertSame('TLS_ERROR', $result->stages['connectivity']['detail']);
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
        $ok = SiteHealthResult::ok(['dns' => ['status' => 'ok'], 'public_site' => ['status' => 'ok', 'detail' => 'HTTP 200']]);

        $state = $service->record($site, $failure);
        self::assertSame('suspected', $state->status);
        self::assertSame(1, $state->consecutive_failures);
        self::assertNull($state->active_incident_id);
        self::assertSame([], $notifications->activeIncidentIds);

        $state = $service->record($site, $failure);
        self::assertSame('suspected', $state->status);
        self::assertSame(2, $state->consecutive_failures);
        self::assertNull($state->active_incident_id);
        self::assertSame([], $notifications->activeIncidentIds);

        $state = $service->record($site, $failure);
        $firstIncidentId = (int) $state->active_incident_id;
        self::assertSame('down', $state->status);
        self::assertGreaterThan(0, $firstIncidentId);
        self::assertSame([$firstIncidentId], $notifications->activeIncidentIds);

        // Same open incident continues failing — no duplicate active notification.
        $state = $service->record($site, $failure);
        self::assertSame($firstIncidentId, (int) $state->active_incident_id);
        self::assertSame(1, SiteHealthIncident::query()->count());
        self::assertSame([$firstIncidentId], $notifications->activeIncidentIds);

        // One success → recovering, not resolved yet.
        $state = $service->record($site, $ok);
        self::assertSame('recovering', $state->status);
        self::assertSame($firstIncidentId, (int) $state->active_incident_id);
        self::assertSame([], $notifications->resolvedIncidentIds);

        // Intermittent failure during recovery keeps the same incident open, no new email.
        $state = $service->record($site, $failure);
        self::assertSame('suspected', $state->status);
        self::assertSame($firstIncidentId, (int) $state->active_incident_id);
        self::assertSame([$firstIncidentId], $notifications->activeIncidentIds);

        // Re-confirm still same incident, still one notification.
        $service->record($site, $failure);
        $state = $service->record($site, $failure);
        self::assertSame($firstIncidentId, (int) $state->active_incident_id);
        self::assertSame([$firstIncidentId], $notifications->activeIncidentIds);

        // Two consecutive successes → resolve once.
        $service->record($site, $ok);
        $state = $service->record($site, $ok);
        self::assertSame('healthy', $state->status);
        self::assertNull($state->active_incident_id);
        self::assertSame(0, $state->consecutive_failures);
        $firstIncident = SiteHealthIncident::query()->findOrFail($firstIncidentId);
        self::assertSame('resolved', $firstIncident->status);
        self::assertNotNull($firstIncident->resolved_at);
        self::assertSame([$firstIncidentId], $notifications->resolvedIncidentIds);

        // New outage after recovery creates a new incident.
        $service->record($site, $failure);
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

    public function test_authorized_user_can_retry_site_health_and_refresh_the_active_incident(): void
    {
        $user = $this->owner('health-owner@example.test');
        $site = Site::query()->create([
            'user_id' => $user->id,
            'domain' => 'https://site-health-test.invalid',
            'status' => 'active',
        ]);
        $monitor = app(SiteHealthMonitor::class);
        $monitor->check($site);
        $monitor->check($site);
        $state = $monitor->check($site);
        $incidentId = (int) $state->active_incident_id;
        $lastCheckedAt = $state->last_checked_at;

        $this->travel(1)->second();

        Livewire::actingAs($user)
            ->test(SiteHealthNotice::class)
            ->call('retrySiteHealth', $site->id)
            ->assertSet('alert.incidents.0.id', $incidentId)
            ->assertSet('alert.incidents.0.consecutive_failures', 4)
            ->assertDispatched('site-health-retried');

        $state->refresh();
        self::assertSame($incidentId, (int) $state->active_incident_id);
        self::assertTrue($state->last_checked_at->greaterThan($lastCheckedAt));
        self::assertSame(1, SiteHealthIncident::query()->where('site_id', $site->id)->count());

        $site->update(['domain' => 'https://127.0.0.1']);
        $site->metas()->create(['meta_key' => 'seo_read_token', 'meta_value' => 'test-token']);
        Http::fake([
            'https://127.0.0.1' => Http::response('ok', 200),
        ]);

        // First success → still recovering / alert present.
        Livewire::actingAs($user)
            ->test(SiteHealthNotice::class)
            ->call('retrySiteHealth', $site->id)
            ->assertSet('alert.incidents.0.id', $incidentId)
            ->assertDispatched('site-health-retried');

        // Second consecutive success → resolved.
        Livewire::actingAs($user)
            ->test(SiteHealthNotice::class)
            ->call('retrySiteHealth', $site->id)
            ->assertSet('alert', null)
            ->assertDispatched('site-health-retried');

        self::assertNotNull(SiteHealthIncident::query()->findOrFail($incidentId)->resolved_at);
    }

    public function test_retry_site_health_for_an_unauthorized_site_returns_403(): void
    {
        $owner = $this->owner('site-owner@example.test');
        $otherUser = $this->owner('other-owner@example.test');
        $site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'https://site-health-test.invalid',
            'status' => 'active',
        ]);

        Livewire::actingAs($otherUser)
            ->test(SiteHealthNotice::class)
            ->call('retrySiteHealth', $site->id)
            ->assertForbidden();
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

    private function owner(string $email): User
    {
        return User::query()->create([
            'name' => 'Site Health Owner',
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    private function createCoreTables(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('site_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('site_meta');
        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('domain')->unique();
            $table->string('status')->default('active');
            $table->boolean('ssl')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('role')->nullable();
            $table->string('status')->default(User::STATUS_NORMAL);
            $table->boolean('is_system')->default(false);
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
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
