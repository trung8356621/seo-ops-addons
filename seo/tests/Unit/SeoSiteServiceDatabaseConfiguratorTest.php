<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Support\SeoSiteServiceDatabaseConfigurator;
use App\Models\Service;
use App\Models\SiteService;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SeoSiteServiceDatabaseConfiguratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
    }

    public function test_merge_form_settings_defaults_missing_db_config_type_to_auto(): void
    {
        $merged = SeoSiteServiceDatabaseConfigurator::mergeFormSettings([
            'settings' => [
                'api_key' => 'seo_test',
            ],
        ]);

        $this->assertSame('auto', $merged['settings']['db_config_type']);
    }

    public function test_merge_form_settings_keeps_manual_db_config_type_in_settings(): void
    {
        $merged = SeoSiteServiceDatabaseConfigurator::mergeFormSettings([
            'settings' => [
                'api_key' => 'seo_test',
                'db_config_type' => 'manual',
            ],
        ]);

        $this->assertSame('manual', $merged['settings']['db_config_type']);
    }

    public function test_merge_form_settings_reads_top_level_db_config_type(): void
    {
        $merged = SeoSiteServiceDatabaseConfigurator::mergeFormSettings([
            'seo_db_config_type' => 'manual',
            'settings' => [
                'api_key' => 'seo_test',
            ],
        ]);

        $this->assertSame('manual', $merged['settings']['db_config_type']);
        $this->assertArrayNotHasKey('seo_db_config_type', $merged);
    }

    public function test_hydrate_form_settings_exposes_db_config_type_field(): void
    {
        $hydrated = SeoSiteServiceDatabaseConfigurator::hydrateFormSettings([
            'settings' => [
                'db_config_type' => 'manual',
            ],
        ]);

        $this->assertSame('manual', $hydrated['seo_db_config_type']);
    }

    public function test_assert_connection_from_form_data_does_not_block_manual_without_connection(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-config@test.test',
            'password' => bcrypt('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $service = Service::query()->create([
            'name' => 'SEO Content AI',
            'slug' => 'seo-content-ai',
            'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
            'is_active' => true,
        ]);

        SeoSiteServiceDatabaseConfigurator::assertConnectionFromFormData([
            'service_id' => $service->id,
            'bound_type' => SiteServiceBindingService::BOUND_USER,
            'user_id' => $owner->id,
            'site_id' => null,
            'seo_db_config_type' => 'manual',
            'settings' => [
                'api_key' => 'seo_test',
            ],
        ], null);

        $this->assertTrue(true);
    }

    public function test_run_migrations_skips_manual_mode_when_owner_has_no_connection(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-manual@test.test',
            'password' => bcrypt('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $service = Service::query()->create([
            'name' => 'SEO Content AI',
            'slug' => 'seo-content-ai',
            'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
            'is_active' => true,
        ]);

        $siteService = SiteService::query()->create([
            'bound_type' => SiteServiceBindingService::BOUND_USER,
            'user_id' => $owner->id,
            'site_id' => null,
            'service_id' => $service->id,
            'status' => 'active',
            'settings' => ['db_config_type' => 'manual'],
        ]);

        SeoSiteServiceDatabaseConfigurator::runMigrations($siteService);

        $this->assertTrue(true);
    }
}
