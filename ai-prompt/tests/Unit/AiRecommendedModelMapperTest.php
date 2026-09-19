<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiRecommendedModelCatalog;
use Omnichannel\Addons\AiPrompt\Services\AiRecommendedModelMapper;
use Omnichannel\Addons\AiPrompt\Support\AiCapabilitySource;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use ReflectionClass;
use Tests\TestCase;

/**
 * Auto-map models — isolated sqlite :memory: fixtures only.
 * Does NOT Schema::drop*, migrate:fresh, or RefreshDatabase against local AI DB.
 */
final class AiRecommendedModelMapperTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private AiRecommendedModelMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureDisposableSchema();
        $this->clearDisposableTables();
        $this->priorities = new AiModelPriorityService();
        $this->mapper = new AiRecommendedModelMapper(
            new AiRecommendedModelCatalog(),
            $this->priorities,
        );
    }

    public function test_a_known_recommended_auto_enables_unknown_stays_available(): void
    {
        $connection = $this->connection(101, ApiConnectionProviders::OPENROUTER, 'or-a');
        $mini = $this->model($connection, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini');
        $foo = $this->model($connection, 'vendor/foo-unknown-xyz', 'Foo');
        $this->grantText($connection, $mini);
        $this->grantText($connection, $foo);

        $result = $this->mapper->mapForUser(101);

        $this->assertSame(1, $result->enabled);
        $this->assertGreaterThanOrEqual(1, $result->recognized);
        $this->assertGreaterThanOrEqual(1, $result->unknown);
        $mini->refresh();
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($mini, AiModelArea::TextFast));
        $this->assertSame(AiModelArea::SOURCE_AUTO, $this->priorities->areaSource($mini, AiModelArea::TextFast));
        $foo->refresh();
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($foo, AiModelArea::TextFast));
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($foo, AiModelArea::TextLongform));
    }

    public function test_b_correct_areas_for_fast_longform_reasoning(): void
    {
        $connection = $this->connection(102, ApiConnectionProviders::DEEPSEEK, 'ds');
        $fast = $this->model($connection, 'deepseek-flash', 'DeepSeek Flash');
        $long = $this->model($connection, 'deepseek-v4-pro', 'DeepSeek V4 Pro');
        $reason = $this->model($connection, 'deepseek-reasoner', 'DeepSeek Reasoner');
        foreach ([$fast, $long, $reason] as $model) {
            $this->grantText($connection, $model);
        }

        $result = $this->mapper->mapForUser(102);

        $this->assertSame(3, $result->enabled);
        $fast->refresh();
        $long->refresh();
        $reason->refresh();
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($fast, AiModelArea::TextFast));
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($long, AiModelArea::TextLongform));
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($reason, AiModelArea::TextReasoning));
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($fast, AiModelArea::TextLongform));
        $this->assertSame(1, $result->byArea[AiModelArea::TextFast->value] ?? 0);
        $this->assertSame(1, $result->byArea[AiModelArea::TextLongform->value] ?? 0);
        $this->assertSame(1, $result->byArea[AiModelArea::TextReasoning->value] ?? 0);
    }

    public function test_c_manual_disabled_survives(): void
    {
        $connection = $this->connection(103, ApiConnectionProviders::OPENROUTER, 'or');
        $mini = $this->model($connection, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini');
        $this->grantText($connection, $mini);
        $this->priorities->writeAreaMembership(
            $mini,
            AiModelArea::TextFast,
            false,
            1,
            AiModelArea::SOURCE_MANUAL,
        );

        $result = $this->mapper->mapForUser(103);

        $mini->refresh();
        $this->assertSame(1, $result->manualDisabledPreserved);
        $this->assertSame(0, $result->enabled);
        $this->assertTrue($this->priorities->isExplicitlyAreaDisabled($mini, AiModelArea::TextFast));
    }

    public function test_d_manual_order_survives(): void
    {
        $connection = $this->connection(104, ApiConnectionProviders::OPENROUTER, 'or');
        $claude = $this->model($connection, 'anthropic/claude-sonnet-4.6', 'Claude Sonnet');
        $mini = $this->model($connection, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini');
        $this->grantText($connection, $claude);
        $this->grantText($connection, $mini);
        $this->priorities->writeAreaMembership($claude, AiModelArea::TextFast, true, 1, AiModelArea::SOURCE_MANUAL);
        $this->priorities->writeAreaMembership($mini, AiModelArea::TextFast, true, 2, AiModelArea::SOURCE_MANUAL);

        $result = $this->mapper->mapForUser(104, AiModelArea::TextFast);

        $enabled = $this->priorities->areaEnabledModels(104, AiModelArea::TextFast);
        $this->assertSame(
            [(int) $claude->id, (int) $mini->id],
            array_map(static fn (SeoAiModel $m): int => (int) $m->id, $enabled),
        );
        $this->assertSame(0, $result->enabled);
        $this->assertGreaterThanOrEqual(1, $result->manualPreserved);
        $claude->refresh();
        $mini->refresh();
        $this->assertSame(1, (int) ($claude->capabilities['omi_areas']['fast_text']['priority'] ?? 0));
        $this->assertSame(2, (int) ($mini->capabilities['omi_areas']['fast_text']['priority'] ?? 0));
    }

    public function test_e_same_provider_stack_enables_once(): void
    {
        $a = $this->connection(105, ApiConnectionProviders::OPENROUTER, 'seo-ops-2');
        $b = $this->connection(105, ApiConnectionProviders::OPENROUTER, 'seo-ops-3');
        $miniA = $this->model($a, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini');
        $miniB = $this->model($b, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini');
        $this->grantText($a, $miniA);
        $this->grantText($b, $miniB);

        $result = $this->mapper->mapForUser(105);

        $this->assertSame(1, $result->enabled);
        $explicit = 0;
        foreach ([$miniA->fresh(), $miniB->fresh()] as $row) {
            if ($this->priorities->isExplicitlyAreaEnabled($row, AiModelArea::TextFast)) {
                $explicit++;
            }
        }
        $this->assertSame(1, $explicit);
        $membership = $this->priorities->effectiveAreaMembership(105, AiModelArea::TextFast);
        $this->assertArrayHasKey((int) $miniA->id, $membership);
        $this->assertArrayHasKey((int) $miniB->id, $membership);
    }

    public function test_e2_cross_provider_each_gets_explicit_membership(): void
    {
        $orA = $this->connection(115, ApiConnectionProviders::OPENROUTER, 'OR-A');
        $orB = $this->connection(115, ApiConnectionProviders::OPENROUTER, 'OR-B');
        $oa = $this->connection(115, 'openai', 'OpenAI Direct');
        $miniOrA = $this->model($orA, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini');
        $miniOrB = $this->model($orB, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini');
        $miniOa = $this->model($oa, 'gpt-5.4-mini', 'GPT-5.4 Mini');
        $this->grantText($orA, $miniOrA);
        $this->grantText($orB, $miniOrB);
        $this->grantText($oa, $miniOa);

        $result = $this->mapper->mapForUser(115);

        $this->assertSame(2, $result->enabled);
        $orExplicit = 0;
        foreach ([$miniOrA->fresh(), $miniOrB->fresh()] as $row) {
            if ($this->priorities->isExplicitlyAreaEnabled($row, AiModelArea::TextFast)) {
                $orExplicit++;
            }
        }
        $this->assertSame(1, $orExplicit);
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($miniOa->fresh(), AiModelArea::TextFast));

        $membership = $this->priorities->effectiveAreaMembership(115, AiModelArea::TextFast);
        $this->assertArrayHasKey((int) $miniOrA->id, $membership);
        $this->assertArrayHasKey((int) $miniOrB->id, $membership);
        $this->assertArrayHasKey((int) $miniOa->id, $membership);

        $presenter = new \Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter(
            priorities: $this->priorities,
        );
        $rows = $presenter->areaRows(115, AiModelArea::TextFast);
        $logical = null;
        foreach ($rows as $row) {
            $ids = array_map('intval', $row['ids'] ?? []);
            if (in_array((int) $miniOrA->id, $ids, true) || in_array((int) $miniOa->id, $ids, true)) {
                $logical = $row;
                break;
            }
        }
        $this->assertNotNull($logical);
        $this->assertSame(3, (int) ($logical['connection_count'] ?? 0));
        $routes = is_array($logical['routes'] ?? null) ? $logical['routes'] : [];
        $providerKeys = [];
        foreach ($routes as $route) {
            $key = (string) ($route['provider_key'] ?? '');
            if ($key !== '') {
                $providerKeys[$key] = true;
            }
        }
        $this->assertArrayHasKey(ApiConnectionProviders::OPENROUTER, $providerKeys);
        $this->assertArrayHasKey('openai', $providerKeys);
    }

    public function test_f_unsupported_incompatible_lane_skipped(): void
    {
        $connection = $this->connection(106, ApiConnectionProviders::OPENROUTER, 'or');
        // Free economics → not allowed in paid Fast lane.
        $mini = $this->model($connection, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
            ],
        ]);
        $this->grantText($connection, $mini);

        $result = $this->mapper->mapForUser(106);

        $this->assertSame(1, $result->unsupported);
        $this->assertSame(0, $result->enabled);
        $mini->refresh();
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($mini, AiModelArea::TextFast));
    }

    public function test_g_unknown_remains_available(): void
    {
        $connection = $this->connection(107, ApiConnectionProviders::OPENROUTER, 'or');
        $foo = $this->model($connection, 'acme/totally-unknown-99', 'Unknown 99');
        $this->grantText($connection, $foo);

        $result = $this->mapper->mapForUser(107);

        $this->assertSame(0, $result->enabled);
        $this->assertSame(0, $result->recognized);
        $this->assertSame(1, $result->unknown);
        $foo->refresh();
        foreach (AiModelArea::paidTextCases() as $area) {
            $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($foo, $area));
        }
    }

    public function test_2_unknown_only_inventory_stays_available_after_bootstrap_map(): void
    {
        $connection = $this->connection(120, ApiConnectionProviders::OPENROUTER, 'or-unknowns');
        $a = $this->model($connection, 'unknown/model-a', 'Unknown A');
        $b = $this->model($connection, 'unknown/model-b', 'Unknown B');
        $this->grantText($connection, $a);
        $this->grantText($connection, $b);

        // Post-sync bootstrap = classifier metadata (skipped here) + recommended mapper only.
        $result = $this->mapper->mapForUser(120);
        $this->assertSame(0, $result->enabled);
        $this->assertSame(0, $result->recognized);
        $this->assertSame(2, $result->unknown);

        foreach (AiModelArea::paidTextCases() as $area) {
            $this->assertCount(0, $this->priorities->areaEnabledModels(120, $area), $area->value);
            $this->assertSame([], $this->priorities->effectiveAreaMembership(120, $area), $area->value);
        }
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($a->fresh(), AiModelArea::TextFast));
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($b->fresh(), AiModelArea::TextFast));
    }

    public function test_3_known_recommended_plus_unknown_foo_post_sync_map(): void
    {
        $connection = $this->connection(121, ApiConnectionProviders::OPENROUTER, 'or');
        $mini = $this->model($connection, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini');
        $foo = $this->model($connection, 'vendor/foo-bar', 'Foo');
        $this->grantText($connection, $mini);
        $this->grantText($connection, $foo);

        $result = $this->mapper->mapForUser(121);
        $this->assertSame(1, $result->enabled);
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($mini->fresh(), AiModelArea::TextFast));
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($foo->fresh(), AiModelArea::TextFast));
        foreach (AiModelArea::paidTextCases() as $area) {
            if ($area === AiModelArea::TextFast) {
                continue;
            }
            $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($foo->fresh(), $area));
        }
    }

    public function test_4_same_provider_second_key_projects_without_duplicate_or_fallback(): void
    {
        $orA = $this->connection(122, ApiConnectionProviders::OPENROUTER, 'OR-A');
        $miniA = $this->model($orA, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini');
        $this->grantText($orA, $miniA);
        $this->priorities->writeAreaMembership(
            $miniA,
            AiModelArea::TextFast,
            true,
            5,
            AiModelArea::SOURCE_MANUAL,
        );

        $orB = $this->connection(122, ApiConnectionProviders::OPENROUTER, 'OR-B');
        $miniB = $this->model($orB, 'openai/gpt-5.4-mini', 'GPT-5.4 Mini');
        $fallback = $this->model($orB, 'acme/random-fallback', 'Random Fallback');
        $this->grantText($orB, $miniB);
        $this->grantText($orB, $fallback);

        $result = $this->mapper->mapForUser(122);
        $this->assertSame(0, $result->enabled);
        $this->assertGreaterThanOrEqual(1, $result->alreadyEnabled + $result->manualPreserved);
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($miniB->fresh(), AiModelArea::TextFast));
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($fallback->fresh(), AiModelArea::TextFast));
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($miniA->fresh(), AiModelArea::TextFast));

        $membership = $this->priorities->effectiveAreaMembership(122, AiModelArea::TextFast);
        $this->assertArrayHasKey((int) $miniA->id, $membership);
        $this->assertArrayHasKey((int) $miniB->id, $membership);
        $this->assertArrayNotHasKey((int) $fallback->id, $membership);

        $explicitIds = [];
        foreach ([$miniA->fresh(), $miniB->fresh(), $fallback->fresh()] as $row) {
            if ($this->priorities->isExplicitlyAreaEnabled($row, AiModelArea::TextFast)) {
                $explicitIds[] = (int) $row->id;
            }
        }
        $this->assertSame([(int) $miniA->id], $explicitIds);
        $miniA->refresh();
        $this->assertSame(5, (int) ($miniA->capabilities['omi_areas']['fast_text']['priority'] ?? 0));
        $this->assertSame(AiModelArea::SOURCE_MANUAL, $this->priorities->areaSource($miniA, AiModelArea::TextFast));
    }

    public function test_sync_paths_do_not_auto_seed_coverage(): void
    {
        $syncAll = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\SyncAllAiConnectionModelsService::class))->getFileName(),
        );
        $this->assertDoesNotMatchRegularExpression('/app\(\s*AiConnectionCoverageService::class/', $syncAll);
        $this->assertStringNotContainsString('reconcileAllAreas', $syncAll);
        $this->assertStringNotContainsString('reconcileRoutingCoverage', $syncAll);
        $this->assertStringContainsString('AiRecommendedModelMapper', $syncAll);

        $aiCenter = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter::class))->getFileName(),
        );
        $syncConnection = $this->extractMethodBody($aiCenter, 'syncConnection');
        $this->assertStringNotContainsString('reconcileRoutingCoverage', $syncConnection);
        $this->assertStringNotContainsString('reconcileAllAreas', $syncConnection);
        $this->assertStringContainsString('AiRecommendedModelMapper', $syncConnection);

        $create = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages\CreateAiConnection::class))->getFileName(),
        );
        $this->assertStringNotContainsString('AiConnectionCoverageService', $create);
        $this->assertStringContainsString('AiRecommendedModelMapper', $create);

        $edit = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages\EditAiConnection::class))->getFileName(),
        );
        $this->assertStringNotContainsString('AiConnectionCoverageService', $edit);
        $this->assertStringContainsString('AiRecommendedModelMapper', $edit);
    }

    public function test_h_idempotent_second_run(): void
    {
        $connection = $this->connection(108, ApiConnectionProviders::DEEPSEEK, 'ds');
        $flash = $this->model($connection, 'deepseek-flash', 'DeepSeek Flash');
        $this->grantText($connection, $flash);

        $first = $this->mapper->mapForUser(108);
        $flash->refresh();
        $priority = (int) ($flash->capabilities['omi_areas']['fast_text']['priority'] ?? 0);
        $capsBefore = $flash->capabilities;

        $second = $this->mapper->mapForUser(108);
        $flash->refresh();

        $this->assertSame(1, $first->enabled);
        $this->assertSame(0, $second->enabled);
        $this->assertSame(1, $second->alreadyEnabled);
        $this->assertSame($priority, (int) ($flash->capabilities['omi_areas']['fast_text']['priority'] ?? 0));
        $this->assertSame(
            $capsBefore['omi_areas']['fast_text'] ?? null,
            $flash->capabilities['omi_areas']['fast_text'] ?? null,
        );
    }

    public function test_i_ui_action_wires_mapper_without_sync(): void
    {
        $path = (string) (new ReflectionClass(SeoSettingsAiCenter::class))->getFileName();
        $source = (string) file_get_contents($path);
        $this->assertStringContainsString('function autoMapModels', $source);
        $this->assertStringContainsString('AiRecommendedModelMapper::class', $source);
        $this->assertStringContainsString('auto_map_models_done_title', $source);

        $blade = dirname(__DIR__, 2).DIRECTORY_SEPARATOR
            .'seo-content-ai-compat'.DIRECTORY_SEPARATOR
            .'resources'.DIRECTORY_SEPARATOR
            .'views'.DIRECTORY_SEPARATOR
            .'filament'.DIRECTORY_SEPARATOR
            .'pages'.DIRECTORY_SEPARATOR
            .'seo-settings-ai-center.blade.php';
        // ai-prompt/tests → peer seo-content-ai-compat at repo root
        if (! is_file($blade)) {
            $blade = dirname(__DIR__, 3).DIRECTORY_SEPARATOR
                .'seo-content-ai-compat'.DIRECTORY_SEPARATOR
                .'resources'.DIRECTORY_SEPARATOR
                .'views'.DIRECTORY_SEPARATOR
                .'filament'.DIRECTORY_SEPARATOR
                .'pages'.DIRECTORY_SEPARATOR
                .'seo-settings-ai-center.blade.php';
        }
        $bladeSource = (string) file_get_contents($blade);
        $this->assertStringContainsString('wire:click="autoMapModels"', $bladeSource);

        $autoMapBody = $this->extractMethodBody($source, 'autoMapModels');
        $this->assertStringContainsString('AiRecommendedModelMapper', $autoMapBody);
        $this->assertStringContainsString('bustInventoryCache', $autoMapBody);
        $this->assertStringNotContainsString('SyncAllAiConnectionModelsService', $autoMapBody);
        $this->assertStringNotContainsString('requestRefresh', $autoMapBody);
        $this->assertStringNotContainsString('Http::', $autoMapBody);
    }

    public function test_j_no_ai_routing_dependency(): void
    {
        $this->assertSame(0, (int) DB::table('ai_routing_profiles')->count());
        $this->assertSame(0, (int) DB::table('ai_routing_targets')->count());

        $connection = $this->connection(110, ApiConnectionProviders::GEMINI, 'gemini');
        $flash = $this->model($connection, 'gemini-3.5-flash', 'Gemini Flash');
        $this->grantText($connection, $flash);

        $result = $this->mapper->mapForUser(110);

        $this->assertSame(1, $result->enabled);
        $flash->refresh();
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($flash, AiModelArea::TextFast));
        $this->assertSame(0, (int) DB::table('ai_routing_profiles')->count());
        $this->assertSame(0, (int) DB::table('ai_routing_targets')->count());
    }

    public function test_catalog_entries_use_existing_family_keys_only(): void
    {
        $families = new AiModelFamilyCatalog();
        $catalog = new AiRecommendedModelCatalog();
        foreach ($catalog->entries() as $entry) {
            $this->assertNotNull($families->find($entry['family_key']), $entry['family_key']);
            $this->assertTrue($entry['recommended']);
            $this->assertInstanceOf(AiModelArea::class, $entry['default_area']);
            $this->assertGreaterThan(0, $entry['default_rank']);
        }
        $this->assertContains('deepseek.flash', $catalog->recommendedFamilyKeys(AiModelArea::TextFast));
        $this->assertContains('deepseek.v4_pro', $catalog->recommendedFamilyKeys(AiModelArea::TextLongform));
        $this->assertContains('deepseek.reasoner', $catalog->recommendedFamilyKeys(AiModelArea::TextReasoning));
    }

    private function ensureDisposableSchema(): void
    {
        if (! Schema::hasTable('api_connections')) {
            Schema::create('api_connections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('provider');
                $table->string('name');
                $table->text('api_key')->nullable();
                $table->boolean('is_global')->default(false);
                $table->string('status')->default('active');
                $table->string('connection_type')->default('ai');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('seo_ai_models')) {
            Schema::create('seo_ai_models', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('api_connection_id');
                $table->string('category')->nullable();
                $table->string('raw_model_name');
                $table->string('display_name');
                $table->integer('priority')->default(100);
                $table->string('status')->default('active');
                $table->boolean('is_hidden')->default(false);
                $table->json('capabilities')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('ai_model_capabilities')) {
            Schema::create('ai_model_capabilities', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('seo_ai_model_id')->nullable();
                $table->unsignedBigInteger('api_connection_id')->nullable();
                $table->string('model_key');
                $table->string('capability');
                $table->string('source')->default('built_in');
                $table->boolean('enabled')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('ai_routing_profiles')) {
            Schema::create('ai_routing_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->default(0);
                $table->string('key');
                $table->string('name');
                $table->string('description')->nullable();
                $table->json('required_capabilities')->nullable();
                $table->boolean('enabled')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('ai_routing_targets')) {
            Schema::create('ai_routing_targets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('profile_id');
                $table->unsignedBigInteger('api_connection_id')->nullable();
                $table->string('model_key')->nullable();
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('enabled')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    private function clearDisposableTables(): void
    {
        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function connection(int $userId, string $provider, string $name): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'sk-test-key-long-enough-for-usable',
            'status' => 'active',
            'is_global' => false,
            'connection_type' => 'ai',
            'metadata' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function model(
        ApiConnection $connection,
        string $raw,
        string $display,
        array $capabilities = [],
    ): SeoAiModel {
        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => 'unknown',
            'raw_model_name' => $raw,
            'display_name' => $display,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => false,
            'capabilities' => $capabilities === [] ? null : $capabilities,
        ]);
    }

    private function grantText(ApiConnection $connection, SeoAiModel $model): void
    {
        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $capability) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $model->id,
                'model_key' => (string) $model->raw_model_name,
                'capability' => $capability,
                'source' => AiCapabilitySource::Manual->value,
                'enabled' => true,
            ]);
        }
    }

    private function extractMethodBody(string $source, string $method): string
    {
        if (preg_match(
            '/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{/',
            $source,
            $m,
            PREG_OFFSET_CAPTURE,
        ) !== 1) {
            return '';
        }
        $start = (int) $m[0][1];
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            return '';
        }
        $depth = 0;
        $len = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }
}
