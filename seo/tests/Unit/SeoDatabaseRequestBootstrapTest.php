<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Omnichannel\Addons\Seo\Support\SeoDatabaseRequestBootstrap;
use App\Models\SeoDatabaseConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SeoDatabaseRequestBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SeoConnectionContext::reset();

        parent::tearDown();
    }

    public function test_should_bootstrap_livewire_when_referer_is_seo_panel(): void
    {
        $bootstrap = app(SeoDatabaseRequestBootstrap::class);

        $request = Request::create('/livewire/update', 'POST');
        $request->headers->set('referer', 'https://seo.example.test/seo/'.str_repeat('a', 32).'/media-library');

        $this->assertTrue($bootstrap->shouldBootstrap($request));
    }

    public function test_should_not_bootstrap_livewire_from_admin_panel(): void
    {
        $bootstrap = app(SeoDatabaseRequestBootstrap::class);

        $request = Request::create('/livewire/update', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        session(['seo_current_connection_hash' => str_repeat('b', 32)]);
        $request->headers->set('referer', 'https://seo.example.test/admin/site-services/create');

        $this->assertFalse($bootstrap->shouldBootstrap($request));
    }

    public function test_should_bootstrap_seo_panel_routes(): void
    {
        $bootstrap = app(SeoDatabaseRequestBootstrap::class);

        $request = Request::create('/seo/'.str_repeat('a', 32).'/media-library', 'GET');

        $this->assertTrue($bootstrap->shouldBootstrap($request));
    }

    public function test_should_not_bootstrap_admin_even_with_session_hash(): void
    {
        $bootstrap = app(SeoDatabaseRequestBootstrap::class);

        $request = Request::create('/admin/site-services/create', 'GET');
        $request->setLaravelSession($this->app['session.store']);
        session(['seo_current_connection_hash' => str_repeat('b', 32)]);

        $this->assertFalse($bootstrap->shouldBootstrap($request));
    }

    public function test_should_not_bootstrap_unrelated_admin_requests(): void
    {
        $bootstrap = app(SeoDatabaseRequestBootstrap::class);

        $request = Request::create('/admin/users', 'GET');

        $this->assertFalse($bootstrap->shouldBootstrap($request));
    }

    public function test_bootstrap_uses_manual_connection_from_session_hash_on_seo_livewire(): void
    {
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'username' => 'root',
            'password' => '',
        ]);

        $hash = str_repeat('c', 32);

        $connection = SeoDatabaseConnection::query()->create([
            'name' => 'Hosting manual',
            'hash_id' => $hash,
            'type' => 'manual',
            'host' => 'db-host.test',
            'port' => '3307',
            'database' => 'seo_manual_db',
            'username' => 'seo_manual_user',
            'password' => 'secret',
            'is_active' => true,
        ]);

        $request = Request::create('/livewire/update', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        session(['seo_current_connection_hash' => $hash]);
        $request->headers->set('referer', 'https://seo.example.test/seo/'.$hash.'/media-library');

        app(SeoDatabaseRequestBootstrap::class)->bootstrap($request);

        $resolved = Config::get('database.connections.omi_seo_ai');

        $this->assertSame('db-host.test', $resolved['host']);
        $this->assertSame('seo_manual_db', $resolved['database']);
        $this->assertSame('seo_manual_user', $resolved['username']);
        $this->assertSame('secret', $resolved['password']);
        $this->assertSame($connection->hash_id, SeoConnectionContext::hash());
    }
}
