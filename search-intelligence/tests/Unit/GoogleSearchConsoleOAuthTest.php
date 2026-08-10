<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleOAuthService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleSyncService;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GoogleSearchConsoleOAuthTest extends TestCase
{
    private const CONNECTION_HASH = 'YTzrG3WKuG3ygjB3cA8wU7CVfV9aRh7G';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_search_console.redirect' => 'https://seo.teamviahe.com/seo/oauth/google-search-console/callback',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('mysql');

        $this->ensureGscTables();
    }

    public function test_google_login_routes_remain_unchanged(): void
    {
        $this->assertTrue(Route::has('google.login'));
        $this->assertSame(
            'auth/google',
            (string) Route::getRoutes()->getByName('google.login')?->uri(),
        );

        $callback = collect(Route::getRoutes())->first(
            static fn ($route): bool => in_array('GET', $route->methods(), true)
                && $route->uri() === 'auth/google/callback',
        );

        $this->assertNotNull($callback);
        $this->assertStringContainsString(
            'GoogleController',
            (string) $callback->getAction('controller'),
        );
        $this->assertStringContainsString('handleGoogleCallback', (string) $callback->getAction('controller'));
    }

    private function createGscConnection(User $user): SeoGscMasterConnection
    {
        return SeoGscMasterConnection::query()->create([
            'user_id' => $user->id,
            'name' => 'Google search full web',
            'oauth_client_id' => 'gsc-test-client-id',
            'oauth_client_secret' => 'gsc-test-client-secret',
            'status' => 'not_configured',
            'is_global' => false,
        ]);
    }

    public function test_gsc_oauth_redirect_requires_manager_permission(): void
    {
        $planner = $this->makeSeoUser(User::SEO_ROLE_PLANNER);
        $this->actingAs($planner);
        $connection = $this->createGscConnection($planner);

        $response = $this->get('/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/connect');

        $response->assertForbidden();
    }

    public function test_gsc_oauth_redirect_uses_expected_scope_and_state(): void
    {
        $manager = $this->makeSeoUser(User::SEO_ROLE_MANAGER);
        $this->actingAs($manager);
        $connection = $this->createGscConnection($manager);

        $response = $this->get('/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/connect');

        $response->assertRedirect();
        $target = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $target);
        $this->assertStringContainsString('webmasters.readonly', $target);
        $this->assertStringContainsString('userinfo.email', $target);
        $this->assertStringContainsString('access_type=offline', $target);
        $this->assertStringContainsString('include_granted_scopes=true', $target);
        $this->assertStringContainsString('client_id=gsc-test-client-id', $target);
        $this->assertStringContainsString('prompt=consent', $target);
        $this->assertStringContainsString('state=', $target);

        $sessionPending = session('gsc_oauth_pending');
        $this->assertIsArray($sessionPending);
        $this->assertSame($manager->id, $sessionPending['user_id']);
        $this->assertSame($manager->id, $sessionPending['account_owner_id']);
        $this->assertSame(self::CONNECTION_HASH, $sessionPending['connection_hash']);
        $this->assertSame($connection->id, $sessionPending['connection_id']);
    }

    public function test_gsc_oauth_callback_rejects_invalid_state(): void
    {
        $manager = $this->makeSeoUser(User::SEO_ROLE_MANAGER);
        $this->actingAs($manager);
        $connection = $this->createGscConnection($manager);

        session([
            'gsc_oauth_pending' => [
                'state' => 'valid-state-token',
                'user_id' => $manager->id,
                'account_owner_id' => $manager->id,
                'connection_hash' => self::CONNECTION_HASH,
                'return_url' => '/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/edit',
                'connection_id' => $connection->id,
            ],
        ]);

        $response = $this->get('/seo/oauth/google-search-console/callback?code=fake-code&state=wrong-state');

        $response->assertRedirect('/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/edit');
        $this->assertSame(
            __('seo-content-ai::filament.api_connections.gsc_oauth_invalid_state'),
            session('gsc_oauth_error'),
        );
    }

    public function test_gsc_oauth_callback_persists_encrypted_tokens_and_syncs_properties(): void
    {
        $manager = $this->makeSeoUser(User::SEO_ROLE_MANAGER);
        $this->actingAs($manager);
        $connection = $this->createGscConnection($manager);

        $state = 'valid-state-token-abc';
        session([
            'gsc_oauth_pending' => [
                'state' => $state,
                'user_id' => $manager->id,
                'account_owner_id' => $manager->id,
                'connection_hash' => self::CONNECTION_HASH,
                'return_url' => '/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/edit',
                'connection_id' => $connection->id,
            ],
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access-token-value',
                'refresh_token' => 'new-refresh-token-value',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'gsc-owner@example.com',
            ]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'sc-domain:example.com'],
                    ['siteUrl' => 'https://example.com/'],
                ],
            ]),
        ]);

        Log::shouldReceive('warning')->never();

        $response = $this->get('/seo/oauth/google-search-console/callback?code=auth-code-123&state='.$state);

        $response->assertRedirect('/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/edit');
        $this->assertSame(
            __('seo-content-ai::filament.api_connections.gsc_oauth_success'),
            session('gsc_oauth_success'),
        );

        $connection = SeoGscMasterConnection::query()->where('user_id', $manager->id)->first();
        $this->assertInstanceOf(SeoGscMasterConnection::class, $connection);
        $this->assertSame('connected', $connection->status);
        $this->assertSame('gsc-owner@example.com', $connection->account_email);

        $raw = (string) $connection->getAttributes()['credentials'];
        $this->assertStringNotContainsString('new-access-token-value', $raw);
        $this->assertStringNotContainsString('new-refresh-token-value', $raw);

        $credentials = $connection->credentials;
        $this->assertIsArray($credentials);
        $this->assertSame('new-access-token-value', $credentials['access_token']);
        $this->assertSame('new-refresh-token-value', $credentials['refresh_token']);
        $this->assertArrayHasKey('expires_at', $credentials);

        $properties = $connection->metadata['properties'] ?? [];
        $this->assertSame(['sc-domain:example.com', 'https://example.com/'], $properties);
    }

    public function test_gsc_oauth_callback_preserves_existing_refresh_token_when_google_omits_it(): void
    {
        $manager = $this->makeSeoUser(User::SEO_ROLE_MANAGER);
        $this->actingAs($manager);

        $connection = SeoGscMasterConnection::query()->create([
            'user_id' => $manager->id,
            'name' => 'Google Search Console',
            'oauth_client_id' => 'gsc-test-client-id',
            'oauth_client_secret' => 'gsc-test-client-secret',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'old-access',
                'refresh_token' => 'keep-refresh-token',
                'expires_at' => now()->subHour()->toIso8601String(),
            ],
            'is_global' => false,
        ]);

        $state = 'reconnect-state-token';
        session([
            'gsc_oauth_pending' => [
                'state' => $state,
                'user_id' => $manager->id,
                'account_owner_id' => $manager->id,
                'connection_hash' => self::CONNECTION_HASH,
                'return_url' => '/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/edit',
                'connection_id' => $connection->id,
            ],
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'rotated-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'gsc-owner@example.com',
            ]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://example.com/'],
                ],
            ]),
        ]);

        $response = $this->get('/seo/oauth/google-search-console/callback?code=auth-code-456&state='.$state);

        $response->assertRedirect();

        $fresh = $connection->fresh();
        $this->assertInstanceOf(SeoGscMasterConnection::class, $fresh);
        $credentials = $fresh->credentials;
        $this->assertIsArray($credentials);
        $this->assertSame('rotated-access-token', $credentials['access_token']);
        $this->assertSame('keep-refresh-token', $credentials['refresh_token']);
    }

    public function test_gsc_oauth_callback_restores_connection_hash_context_in_session(): void
    {
        $manager = $this->makeSeoUser(User::SEO_ROLE_MANAGER);
        $this->actingAs($manager);
        $connection = $this->createGscConnection($manager);

        $state = 'context-state-token';
        session([
            'gsc_oauth_pending' => [
                'state' => $state,
                'user_id' => $manager->id,
                'account_owner_id' => $manager->id,
                'connection_hash' => self::CONNECTION_HASH,
                'return_url' => '/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/edit',
                'connection_id' => $connection->id,
            ],
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'owner@example.com']),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []]),
        ]);

        $this->get('/seo/oauth/google-search-console/callback?code=abc&state='.$state)
            ->assertRedirect('/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/edit');

        $this->assertNull(session('gsc_oauth_pending'));
    }

    public function test_oauth_service_does_not_leak_secrets_in_sanitized_messages(): void
    {
        $service = app(GoogleSearchConsoleOAuthService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('sanitizeMessage');
        $method->setAccessible(true);

        $sanitized = (string) $method->invoke(
            $service,
            'access_token=super-secret-token refresh_token=abc authorization_code=xyz client_secret=hidden',
        );

        $this->assertStringNotContainsString('super-secret-token', $sanitized);
        $this->assertStringNotContainsString('client_secret=hidden', $sanitized);
        $this->assertStringContainsString('[redacted]', $sanitized);
    }

    public function test_gsc_route_names_are_registered(): void
    {
        $this->assertTrue(Route::has('seo.gsc.oauth.redirect'));
        $this->assertTrue(Route::has('seo.gsc.oauth.callback'));
        $this->assertSame(
            'seo/{connection_hash}/settings/api/google-search-console/{record}/connect',
            (string) Route::getRoutes()->getByName('seo.gsc.oauth.redirect')?->uri(),
        );
        $this->assertSame(
            'seo/oauth/google-search-console/callback',
            (string) Route::getRoutes()->getByName('seo.gsc.oauth.callback')?->uri(),
        );
    }

    private function makeSeoUser(string $seoRole): User
    {
        $user = new User([
            'role' => User::ROLE_OWNER,
            'seo_role' => $seoRole,
            'status' => User::STATUS_NORMAL,
        ]);
        $user->id = 501;
        $user->exists = true;

        return $user;
    }

    private function ensureGscTables(): void
    {
        Schema::connection('mysql')->dropIfExists('seo_gsc_property_mappings');
        Schema::connection('mysql')->dropIfExists('seo_gsc_master_connections');

        Schema::connection('mysql')->create('seo_gsc_master_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->string('status')->default('not_configured');
            $table->text('credentials')->nullable();
            $table->string('account_email')->nullable();
            $table->string('oauth_client_id')->nullable();
            $table->text('oauth_client_secret')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_global')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('seo_gsc_property_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('gsc_connection_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('property_url');
            $table->string('property_type')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['gsc_connection_id', 'site_id']);
        });
    }
}
