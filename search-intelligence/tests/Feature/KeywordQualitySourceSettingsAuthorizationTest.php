<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Feature;

use App\Core\Addon\AddonRegistry;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\KeywordQualitySourceSettings;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordQuality\KeywordQualityService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityCode;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

final class KeywordQualitySourceSettingsAuthorizationTest extends TestCase
{
    private User $admin;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('omi_seo_ai');
        $this->bootCoreTables();
        (require dirname(__DIR__, 2).'/database/migrations/2026_10_15_120000_create_seo_keyword_quality_tables.php')->up();

        $this->admin = $this->user('admin@example.test', User::ROLE_ADMIN);
        $this->owner = $this->user('owner@example.test', User::ROLE_OWNER);
        $this->staff = $this->user('staff@example.test', User::ROLE_STAFF, (int) $this->owner->id);

        $this->site(5, (int) $this->admin->id, 'site-five.test');
        $this->site(7, (int) $this->owner->id, 'site-seven.test');

        config(['addons.skip_slugs' => []]);
        app(AddonRegistry::class)->markEnabled('seo');
        View::addNamespace('search-intelligence', dirname(__DIR__, 2).'/resources/views');
        View::addNamespace('seo-content-ai', dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_opens_site_settings_and_save_does_not_touch_another_site(): void
    {
        $service = app(KeywordQualityService::class);
        $service->setExcluded(7, KeywordQualityCode::CTA_ANCHOR, true);

        $opened = $this->pageFor(5, $this->admin);
        $opened->assertOk()->assertSet('siteId', 5);

        $page = $this->pageFor(5, $this->admin)
            ->fillForm($this->policy([KeywordQualityCode::CTA_ANCHOR => true]));
        $this->savePolicy($page, 5)->assertOk();

        self::assertTrue($service->isExcluded(5, KeywordQualityCode::CTA_ANCHOR));
        self::assertTrue($service->isExcluded(7, KeywordQualityCode::CTA_ANCHOR));
        self::assertFalse($service->isExcluded(7, KeywordQualityCode::GENERIC_ANCHOR));

        $this->pageFor(5, $this->admin)
            ->assertSet('policyData.exclude_'.KeywordQualityCode::CTA_ANCHOR, true)
            ->assertSet('siteId', 5);
    }

    public function test_livewire_save_uses_referer_site_and_rejects_tampered_component_state(): void
    {
        $service = app(KeywordQualityService::class);
        $service->setExcluded(7, KeywordQualityCode::CTA_ANCHOR, true);

        $page = $this->pageFor(5, $this->admin)
            ->fillForm($this->policy([KeywordQualityCode::GENERIC_ANCHOR => true]));
        $this->savePolicy($page, 5)->assertOk();

        self::assertTrue($service->isExcluded(5, KeywordQualityCode::GENERIC_ANCHOR));
        self::assertTrue($service->isExcluded(7, KeywordQualityCode::CTA_ANCHOR));
        self::assertFalse($service->isExcluded(7, KeywordQualityCode::GENERIC_ANCHOR));

        $tampered = $this->pageFor(5, $this->admin)->set('siteId', 7);
        $this->savePolicy($tampered, 5)->assertForbidden();

        self::assertFalse($service->isExcluded(7, KeywordQualityCode::GENERIC_ANCHOR));
    }

    public function test_unauthorized_user_and_foreign_site_stay_forbidden(): void
    {
        $this->actingAs($this->staff);
        self::assertFalse(KeywordQualitySourceSettings::canAccess());
        $this->get('/admin/settings/keyword-sources?site_id=5')
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get('/admin/settings/keyword-sources?site_id=7')
            ->assertForbidden();

        session(['seo_simulated_role' => SeoAccessControl::ROLE_CONTENT_MANAGER]);
        $this->actingAs($this->owner);
        self::assertSame(SeoAccessControl::ROLE_CONTENT_MANAGER, SeoAccessControl::effectiveRole());
        self::assertTrue(KeywordQualitySourceSettings::canAccess());

        $this->site(8, (int) $this->owner->id, 'owner-site.test');
        $this->pageFor(8, $this->owner)->assertOk()->assertSet('siteId', 8);
    }

    public function test_topic_shortcut_targets_the_resolved_site(): void
    {
        $this->actingAs($this->admin);
        $page = new KeywordTopicClusters();
        $page->keywordWorkspaceSiteId = 5;

        $url = $page->keywordSourceSettingsUrl();

        self::assertIsString($url);
        self::assertStringContainsString('keyword-sources', $url);
        self::assertStringContainsString('site_id=5', $url);
    }

    private function pageFor(int $siteId, User $user): Testable
    {
        return Livewire::actingAs($user)
            ->withQueryParams(['site_id' => $siteId])
            ->test(KeywordQualitySourceSettings::class);
    }

    private function savePolicy(Testable $page, int $siteId): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware()
            ->actingAs($this->admin)
            ->post('/livewire/update', [
                'components' => [[
                    'snapshot' => json_encode($page->snapshot),
                    'calls' => [[
                        'path' => '',
                        'method' => 'savePolicy',
                        'params' => [],
                    ]],
                    'updates' => [],
                ]],
            ], [
                'X-Livewire' => 'true',
                'Referer' => 'http://seo-ops.test/admin/settings/keyword-sources?site_id='.$siteId,
            ]);
    }

    /**
     * @param  array<string, bool>  $enabled
     * @return array<string, bool>
     */
    private function policy(array $enabled): array
    {
        $data = [];
        foreach (KeywordQualityCode::all() as $code) {
            $data['exclude_'.$code] = (bool) ($enabled[$code] ?? false);
        }

        return $data;
    }

    private function bootCoreTables(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->unsignedBigInteger('manager_id')->nullable();
                $table->string('role')->default('staff');
                $table->string('status')->default('normal');
                $table->boolean('is_system')->default(false);
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sites')) {
            Schema::create('sites', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->string('domain');
                $table->string('status')->default('active');
                $table->boolean('ssl')->default(false);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    private function user(string $email, string $role, ?int $parentId = null): User
    {
        return User::query()->create([
            'name' => $role,
            'email' => $email,
            'password' => 'secret',
            'role' => $role,
            'status' => User::STATUS_NORMAL,
            'parent_id' => $parentId,
        ]);
    }

    private function site(int $id, int $userId, string $domain): void
    {
        Site::query()->forceCreate([
            'id' => $id,
            'user_id' => $userId,
            'domain' => $domain,
            'status' => 'active',
            'ssl' => true,
        ]);
    }
}
