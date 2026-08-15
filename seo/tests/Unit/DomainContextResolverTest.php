<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\DomainContext;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DomainContextResolverTest extends TestCase
{
    private User $owner;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $default = (string) config('database.default', 'sqlite');
        Config::set('database.core_connection', $default);

        $this->createCoreTables();

        $this->owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'domain-context-owner@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $other = User::query()->create([
            'name' => 'Other',
            'email' => 'domain-context-other@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $this->siteA = Site::query()->create([
            'user_id' => $this->owner->id,
            'domain' => 'baloquatang.net',
            'status' => 'active',
        ]);
        $this->siteB = Site::query()->create([
            'user_id' => $this->owner->id,
            'domain' => 'congtybalo.com',
            'status' => 'active',
        ]);
        Site::query()->create([
            'user_id' => $other->id,
            'domain' => 'foreign-site.test',
            'status' => 'active',
        ]);

        $this->actingAs($this->owner);
        app(DomainContextResolver::class)->reset();
    }

    protected function tearDown(): void
    {
        app(DomainContextResolver::class)->reset();

        parent::tearDown();
    }

    public function test_default_without_url_or_header_is_all_domains(): void
    {
        $this->bindRequest();

        $context = app(DomainContextResolver::class)->current();

        $this->assertTrue($context->isAllDomains);
        $this->assertNull($context->siteId);
        $this->assertSame(DomainContext::ALL_KEY, $context->domainKey);
        $this->assertNull(SeoAccessControl::globalSiteId());
        $this->assertFalse(SeoAccessControl::shouldApplyGlobalSiteScope());
    }

    public function test_query_domain_wins_over_header(): void
    {
        $this->bindRequest(
            ['domain' => 'congtybalo.com'],
            [DomainContext::HEADER_KEY => 'baloquatang.net'],
        );

        $context = app(DomainContextResolver::class)->current();

        $this->assertFalse($context->isAllDomains);
        $this->assertSame((int) $this->siteB->id, $context->siteId);
        $this->assertSame('congtybalo.com', $context->domainKey);
    }

    public function test_header_is_used_when_query_absent(): void
    {
        $this->bindRequest([], [DomainContext::HEADER_KEY => 'baloquatang.net']);

        $context = app(DomainContextResolver::class)->current();

        $this->assertSame((int) $this->siteA->id, $context->siteId);
        $this->assertSame('baloquatang.net', $context->domainKey);
    }

    public function test_explicit_all_key_is_first_class(): void
    {
        $this->bindRequest(['domain' => 'all']);

        $context = app(DomainContextResolver::class)->current();

        $this->assertTrue($context->isAllDomains);
        $this->assertSame('all', $context->domainKey);
        $this->assertNull($context->siteId);
    }

    public function test_legacy_zero_and_empty_normalize_to_all(): void
    {
        foreach (['', '0', '-1', 'null'] as $raw) {
            app(DomainContextResolver::class)->reset();
            $context = app(DomainContextResolver::class)->resolveKey($raw);
            $this->assertTrue($context->isAllDomains, 'raw='.$raw);
        }
    }

    public function test_unauthorized_domain_normalizes_to_all(): void
    {
        $this->bindRequest(['domain' => 'foreign-site.test']);

        $context = app(DomainContextResolver::class)->current();

        $this->assertTrue($context->isAllDomains);
        $this->assertNull(SeoAccessControl::globalSiteId());
    }

    public function test_stale_unknown_domain_normalizes_to_all(): void
    {
        $this->bindRequest(['domain' => 'deleted-site.test']);

        $context = app(DomainContextResolver::class)->current();

        $this->assertTrue($context->isAllDomains);
    }

    public function test_specific_domain_exposes_site_id_for_query_scope(): void
    {
        $this->bindRequest(['domain' => 'baloquatang.net']);

        $this->assertTrue(SeoAccessControl::shouldApplyGlobalSiteScope());
        $this->assertSame((int) $this->siteA->id, SeoAccessControl::globalSiteId());
    }

    public function test_legacy_session_and_cookie_are_ignored(): void
    {
        $request = Request::create('/seo/'.str_repeat('a', 32).'/content-projects', 'GET');
        $request->cookies->set('seo_global_site_id', (string) $this->siteA->id);
        $this->app->instance('request', $request);
        $request->setLaravelSession($this->app['session']->driver());
        session(['seo_global_site_id' => (int) $this->siteA->id]);
        app(DomainContextResolver::class)->reset();

        $context = app(DomainContextResolver::class)->current();

        $this->assertTrue($context->isAllDomains);
        $this->assertNull(SeoAccessControl::globalSiteId());
    }

    public function test_set_global_site_id_does_not_write_session(): void
    {
        $this->bindRequest();
        session()->forget('seo_global_site_id');

        SeoAccessControl::setGlobalSiteId((int) $this->siteA->id);

        $this->assertSame((int) $this->siteA->id, SeoAccessControl::globalSiteId());
        $this->assertFalse(session()->has('seo_global_site_id'));
    }

    public function test_set_global_site_id_rejects_inaccessible_site(): void
    {
        $this->bindRequest();
        $foreignId = (int) Site::query()->where('domain', 'foreign-site.test')->value('id');

        SeoAccessControl::setGlobalSiteId($foreignId);

        $this->assertTrue(SeoAccessControl::domainContext()->isAllDomains);
        $this->assertNull(SeoAccessControl::globalSiteId());
    }

    private function createCoreTables(): void
    {
        $connection = (string) config('database.core_connection', 'sqlite');

        Schema::connection($connection)->dropIfExists('sites');
        Schema::connection($connection)->dropIfExists('users');

        Schema::connection($connection)->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('role')->nullable();
            $table->string('seo_role')->nullable();
            $table->string('status')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection($connection)->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('domain');
            $table->string('status')->nullable();
            $table->boolean('ssl')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    private function bindRequest(array $query = [], array $headers = []): void
    {
        $request = Request::create(
            '/seo/'.str_repeat('a', 32).'/content-projects',
            'GET',
            $query,
        );
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $this->app->instance('request', $request);
        app(DomainContextResolver::class)->reset();
    }
}
