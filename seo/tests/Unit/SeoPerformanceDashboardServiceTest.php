<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankCheckRun;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankSnapshot;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroupItem;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection;
use Omnichannel\Addons\SearchIntelligence\Services\SeoPerformanceDashboardService;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProjectRoot;
use Tests\TestCase;

final class SeoPerformanceDashboardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('mysql');
        DB::purge('omi_seo_ai');
        $this->ensureTables();

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-dashboard@test.local',
            'password' => 'secret',
            'role' => 'admin',
        ]);
        $this->actingAs($user);
    }

    public function test_build_gsc_state_does_not_include_rank_visibility_metrics(): void
    {
        $state = app(SeoPerformanceDashboardService::class)->buildGscState(null);

        $this->assertArrayHasKey('total_clicks', $state['kpis']);
        $this->assertArrayNotHasKey('visibility', $state['kpis']);
        $this->assertArrayNotHasKey('search_volume', $state['kpis']);
    }

    public function test_build_rank_state_does_not_include_gsc_queries(): void
    {
        $state = app(SeoPerformanceDashboardService::class)->buildRankState(null, (int) auth()->id(), 'serper');

        $this->assertArrayHasKey('visibility', $state['kpis']);
        $this->assertArrayHasKey('advanced_analysis', $state);
        $this->assertArrayNotHasKey('visibility_chart', $state);
        $this->assertArrayNotHasKey('queries', $state);
        $this->assertArrayNotHasKey('quick_wins', $state);
    }

    public function test_organic_visibility_not_eligible_with_zero_or_one_successful_run(): void
    {
        $group = $this->createRankGroupWithTargetDomain();
        $service = app(SeoPerformanceDashboardService::class);

        $empty = $service->buildRankState((int) $group->id, (int) auth()->id(), 'serper');
        $this->assertFalse($empty['advanced_analysis']['organic_visibility']['eligible']);
        $this->assertSame(0, $empty['advanced_analysis']['organic_visibility']['successful_run_count']);
        $this->assertFalse($empty['advanced_analysis']['has_any']);

        $this->seedCompletedRankRun($group, 'serper', 1);
        $oneRun = $service->buildRankState((int) $group->id, (int) auth()->id(), 'serper');
        $this->assertFalse($oneRun['advanced_analysis']['organic_visibility']['eligible']);
        $this->assertSame(1, $oneRun['advanced_analysis']['organic_visibility']['successful_run_count']);
        $this->assertFalse($oneRun['advanced_analysis']['has_any']);
    }

    public function test_organic_visibility_eligible_with_two_successful_runs(): void
    {
        $group = $this->createRankGroupWithTargetDomain();
        $this->seedCompletedRankRun($group, 'serper', 1);
        $this->seedCompletedRankRun($group, 'serper', 2);

        $state = app(SeoPerformanceDashboardService::class)->buildRankState((int) $group->id, (int) auth()->id(), 'serper');

        $this->assertTrue($state['advanced_analysis']['organic_visibility']['eligible']);
        $this->assertSame(2, $state['advanced_analysis']['organic_visibility']['successful_run_count']);
        $this->assertTrue($state['advanced_analysis']['has_any']);
        $this->assertTrue($state['advanced_analysis']['organic_visibility']['data']['has_data']);
    }

    public function test_organic_visibility_counts_runs_not_keyword_rows(): void
    {
        $group = $this->createRankGroupWithTargetDomain();
        $keywordB = Keyword::query()->create(['phrase' => 'keyword beta']);
        SeoRankKeywordGroupItem::query()->create([
            'group_id' => $group->id,
            'keyword_id' => $keywordB->id,
        ]);

        $run = KeywordRankCheckRun::query()->create([
            'rank_group_id' => $group->id,
            'provider' => 'serper',
            'status' => 'completed',
            'run_type' => 'batch',
            'completed_at' => now(),
        ]);

        foreach ($group->fresh(['items'])->items as $item) {
            KeywordRankSnapshot::query()->create([
                'rank_group_id' => $group->id,
                'rank_group_item_id' => $item->id,
                'keyword_id' => $item->keyword_id,
                'provider' => 'serper',
                'position' => 5.0,
                'checked_at' => now(),
                'run_id' => $run->id,
            ]);
        }

        $state = app(SeoPerformanceDashboardService::class)->buildRankState((int) $group->id, (int) auth()->id(), 'serper');

        $this->assertSame(1, $state['advanced_analysis']['organic_visibility']['successful_run_count']);
        $this->assertFalse($state['advanced_analysis']['organic_visibility']['eligible']);
    }

    public function test_provider_comparison_not_eligible_without_two_providers_or_data(): void
    {
        $group = $this->createRankGroupWithTargetDomain();
        $service = app(SeoPerformanceDashboardService::class);

        $noProviders = $service->buildRankState((int) $group->id, (int) auth()->id(), 'serper', comparisonBatchId: 'batch-1');
        $this->assertFalse($noProviders['advanced_analysis']['provider_comparison']['eligible']);
        $this->assertSame(0, $noProviders['advanced_analysis']['provider_comparison']['provider_count']);
        $this->assertSame([], $noProviders['advanced_analysis']['provider_comparison']['data']);

        $this->seedConfiguredProvider('serper');
        $oneProvider = $service->buildRankState((int) $group->id, (int) auth()->id(), 'serper', comparisonBatchId: 'batch-1');
        $this->assertFalse($oneProvider['advanced_analysis']['provider_comparison']['eligible']);
        $this->assertSame(1, $oneProvider['advanced_analysis']['provider_comparison']['provider_count']);

        $this->seedConfiguredProvider('serpapi');
        $twoProvidersNoData = $service->buildRankState((int) $group->id, (int) auth()->id(), 'serper', comparisonBatchId: '');
        $this->assertFalse($twoProvidersNoData['advanced_analysis']['provider_comparison']['eligible']);
        $this->assertSame(2, $twoProvidersNoData['advanced_analysis']['provider_comparison']['provider_count']);
    }

    public function test_advanced_analysis_toggle_not_needed_when_nothing_eligible(): void
    {
        $state = app(SeoPerformanceDashboardService::class)->buildRankState(null, (int) auth()->id(), 'serper');

        $this->assertFalse($state['advanced_analysis']['has_any']);
    }

    public function test_performance_hub_blade_places_rankings_before_advanced_analysis(): void
    {
        $blade = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/seo/performance-hub.blade.php');

        $rankingsPos = strpos($blade, 'rankings-table');
        $advancedPos = strpos($blade, 'advanced-analysis');

        $this->assertNotFalse($rankingsPos);
        $this->assertNotFalse($advancedPos);
        $this->assertLessThan($advancedPos, $rankingsPos);
    }

    public function test_advanced_analysis_partial_defaults_collapsed(): void
    {
        $partial = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/seo/performance-hub/partials/advanced-analysis.blade.php');

        $this->assertStringContainsString('expanded: false', $partial);
        $this->assertStringContainsString('advanced_show', $partial);
        $this->assertStringContainsString('advanced_collapse', $partial);
    }

    public function test_visibility_chart_partial_has_no_empty_placeholder(): void
    {
        $partial = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/seo/performance-hub/partials/visibility-chart.blade.php');

        $this->assertStringNotContainsString('performance-hub-empty-state', $partial);
        $this->assertStringNotContainsString('empty_not_synced', $partial);
    }

    public function test_resolve_default_data_source_returns_gsc_when_no_providers(): void
    {
        $source = app(SeoPerformanceDashboardService::class)->resolveDefaultDataSource(null);

        $this->assertSame('gsc', $source);
    }

    public function test_resolve_source_or_fallback_returns_gsc_for_unknown_provider(): void
    {
        $source = app(SeoPerformanceDashboardService::class)->resolveSourceOrFallback('rank-tracking', (int) auth()->id(), null);

        $this->assertSame('gsc', $source);
    }

    public function test_available_source_tabs_always_includes_gsc(): void
    {
        $tabs = app(SeoPerformanceDashboardService::class)->availableSourceTabs((int) auth()->id());

        $this->assertSame('gsc', $tabs[0]['key']);
    }

    private function ensureTables(): void
    {
        Schema::connection('mysql')->dropIfExists('users');
        Schema::connection('mysql')->dropIfExists('seo_serp_provider_connections');
        Schema::connection('mysql')->dropIfExists('seo_dataforseo_connections');
        Schema::connection('mysql')->dropIfExists('seo_gsc_property_mappings');
        Schema::connection('mysql')->dropIfExists('seo_gsc_master_connections');

        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('admin');
            $table->timestamps();
        });

        Schema::connection('mysql')->create('seo_gsc_master_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->string('status')->default('not_configured');
            $table->text('credentials')->nullable();
            $table->string('account_email')->nullable();
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
        });

        Schema::connection('mysql')->create('seo_serp_provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider', 32);
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->string('status')->default('not_configured');
            $table->string('default_country', 8)->nullable();
            $table->string('default_language', 16)->nullable();
            $table->string('default_location')->nullable();
            $table->string('default_device', 16)->default('desktop');
            $table->unsignedSmallInteger('result_depth')->default(100);
            $table->boolean('is_global')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_rank_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('seo_dataforseo_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('login');
            $table->text('password');
            $table->string('default_location')->nullable();
            $table->string('default_language')->nullable();
            $table->decimal('balance', 12, 4)->nullable();
            $table->string('status')->default('not_configured');
            $table->boolean('is_global')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('seo_extended_provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider', 32);
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->string('status')->default('not_configured');
            $table->boolean('is_global')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase')->unique();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_rank_keyword_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('country_code', 8)->default('vn');
            $table->string('language_code', 16)->default('vi');
            $table->string('location')->nullable();
            $table->string('device', 16)->default('desktop');
            $table->string('target_domain')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('omi_seo_ai')->create('seo_rank_keyword_group_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keyword_rank_check_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('rank_group_id')->nullable();
            $table->string('connection_hash', 64)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('run_type', 32)->default('batch');
            $table->string('comparison_batch_id', 64)->nullable();
            $table->unsignedInteger('total_keywords')->default(0);
            $table->unsignedInteger('processed_keywords')->default(0);
            $table->unsignedInteger('failed_keywords')->default(0);
            $table->string('provider')->default('serper');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keyword_group_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rank_group_id')->index();
            $table->unsignedBigInteger('rank_group_item_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('metric_type', 32);
            $table->string('provider', 64)->nullable();
            $table->string('source', 64)->nullable();
            $table->unsignedBigInteger('value_int')->nullable();
            $table->string('status', 32);
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keyword_rank_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('rank_group_id')->nullable();
            $table->unsignedBigInteger('rank_group_item_id')->nullable();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('provider')->default('serper');
            $table->decimal('position', 8, 2)->nullable();
            $table->string('ranking_url', 2048)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->timestamps();
        });
    }

    private function createRankGroupWithTargetDomain(): SeoRankKeywordGroup
    {
        $keyword = Keyword::query()->create(['phrase' => 'keyword alpha']);
        $group = SeoRankKeywordGroup::query()->create([
            'created_by' => (int) auth()->id(),
            'name' => 'Test Group',
            'country_code' => 'vn',
            'language_code' => 'vi',
            'device' => 'desktop',
            'target_domain' => 'example.com',
        ]);

        SeoRankKeywordGroupItem::query()->create([
            'group_id' => $group->id,
            'keyword_id' => $keyword->id,
        ]);

        return $group->fresh(['items']);
    }

    private function seedCompletedRankRun(SeoRankKeywordGroup $group, string $provider, int $runIndex): KeywordRankCheckRun
    {
        $run = KeywordRankCheckRun::query()->create([
            'rank_group_id' => $group->id,
            'provider' => $provider,
            'status' => 'completed',
            'run_type' => 'batch',
            'completed_at' => now()->subDays($runIndex),
        ]);

        $item = $group->items()->first();
        KeywordRankSnapshot::query()->create([
            'rank_group_id' => $group->id,
            'rank_group_item_id' => $item?->id,
            'keyword_id' => $item?->keyword_id,
            'provider' => $provider,
            'position' => 4.0 + $runIndex,
            'checked_at' => now()->subDays($runIndex),
            'run_id' => $run->id,
        ]);

        return $run;
    }

    private function seedConfiguredProvider(string $provider): void
    {
        SeoSerpProviderConnection::query()->create([
            'user_id' => (int) auth()->id(),
            'provider' => $provider,
            'name' => strtoupper($provider),
            'api_key' => 'test-key-'.$provider,
            'status' => 'active',
            'is_global' => true,
        ]);
    }
}
