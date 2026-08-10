<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleOAuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GoogleSearchConsoleOAuthCredentialsTest extends TestCase
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

    public function test_create_persists_oauth_client_id_and_encrypted_secret(): void
    {
        $service = app(GoogleSearchConsoleConnectionService::class);

        $connection = $service->createForUser(42, [
            'name' => 'GSC master',
            'oauth_client_id' => 'per-connection-client-id',
            'oauth_client_secret' => 'per-connection-client-secret',
        ]);

        $fresh = $connection->fresh();
        $this->assertInstanceOf(SeoGscMasterConnection::class, $fresh);
        $this->assertSame('per-connection-client-id', $fresh->oauth_client_id);
        $this->assertSame('per-connection-client-secret', $fresh->oauth_client_secret);
        $this->assertTrue($service->hasOAuthAppCredentials($fresh));
        $this->assertSame('not_configured', $service->resolveEffectiveStatus($fresh));
    }

    public function test_edit_keeps_client_secret_when_blank(): void
    {
        $service = app(GoogleSearchConsoleConnectionService::class);
        $connection = $service->createForUser(42, [
            'name' => 'GSC master',
            'oauth_client_id' => 'client-id',
            'oauth_client_secret' => 'original-secret',
        ]);

        $updated = $service->saveMasterConnection(42, [
            'name' => 'Renamed GSC',
            'oauth_client_id' => 'client-id-updated',
            'oauth_client_secret' => '',
        ], (int) $connection->id);

        $this->assertSame('client-id-updated', $updated->oauth_client_id);
        $this->assertSame('original-secret', $updated->fresh()?->oauth_client_secret);
    }

    public function test_oauth_authorization_and_exchange_use_connection_credentials(): void
    {
        $oauth = app(GoogleSearchConsoleOAuthService::class);
        $connection = SeoGscMasterConnection::query()->create([
            'user_id' => 501,
            'name' => 'GSC',
            'oauth_client_id' => 'connection-client-id',
            'oauth_client_secret' => 'connection-client-secret',
            'status' => 'not_configured',
            'is_global' => false,
        ]);

        $url = $oauth->buildAuthorizationUrl($connection, 'state-token-123', true);
        $this->assertStringContainsString('client_id=connection-client-id', $url);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-from-connection-creds',
                'refresh_token' => 'refresh-from-connection-creds',
                'expires_in' => 3600,
            ]),
        ]);

        $payload = $oauth->exchangeAuthorizationCode($connection, 'auth-code');
        $this->assertSame('access-from-connection-creds', $payload['access_token']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['client_id'] === 'connection-client-id'
                && $request['client_secret'] === 'connection-client-secret';
        });
    }

    public function test_connect_redirect_blocked_without_oauth_app_credentials(): void
    {
        $manager = $this->makeSeoUser();
        $this->actingAs($manager);

        $connection = SeoGscMasterConnection::query()->create([
            'user_id' => $manager->id,
            'name' => 'GSC without oauth app',
            'status' => 'not_configured',
            'is_global' => false,
        ]);

        $response = $this->get('/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/connect');

        $response->assertRedirect();
        $this->assertSame(
            __('seo-content-ai::filament.api_connections.gsc_oauth_app_not_configured'),
            session('gsc_oauth_error'),
        );
    }

    public function test_status_requires_tokens_not_email(): void
    {
        $service = app(GoogleSearchConsoleConnectionService::class);

        $oauthOnly = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'OAuth only',
            'oauth_client_id' => 'client',
            'oauth_client_secret' => 'secret',
            'status' => 'connected',
            'is_global' => false,
        ]);
        $this->assertSame('not_configured', $service->resolveEffectiveStatus($oauthOnly));

        $tokensWithoutEmail = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'Tokens without email',
            'oauth_client_id' => 'client',
            'oauth_client_secret' => 'secret',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'access-only',
                'refresh_token' => 'refresh',
            ],
            'is_global' => false,
        ]);
        $this->assertSame('connected', $service->resolveEffectiveStatus($tokensWithoutEmail));

        $accessOnly = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'Access only',
            'oauth_client_id' => 'client',
            'oauth_client_secret' => 'secret',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'access-only',
            ],
            'is_global' => false,
        ]);
        $this->assertSame('not_configured', $service->resolveEffectiveStatus($accessOnly));

        $connected = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'Connected',
            'oauth_client_id' => 'client',
            'oauth_client_secret' => 'secret',
            'account_email' => 'owner@example.com',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'is_global' => false,
        ]);
        $this->assertSame('connected', $service->resolveEffectiveStatus($connected));
    }

    private function makeSeoUser(): \App\Models\User
    {
        $user = new \App\Models\User([
            'role' => \App\Models\User::ROLE_OWNER,
            'seo_role' => \App\Models\User::SEO_ROLE_MANAGER,
            'status' => \App\Models\User::STATUS_NORMAL,
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
            $table->timestamps();
        });

        $this->assertTrue(Route::has('seo.gsc.oauth.redirect'));
    }
}
