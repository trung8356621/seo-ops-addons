<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\User;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPackService;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\TaskPackService;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\TaskPortableIdentity;
use Omnichannel\Addons\AiPrompt\Support\ConfigurationPackageType;
use Omnichannel\Addons\Seo\Services\SeoAnalyticsScopeSettingsService;
use Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\AiCenterSettingsSection;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SeoSettingsBundleService;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\WorkflowBindingsSection;
use Tests\TestCase;

final class FullConfigurationTransferTest extends TestCase
{
    private SeoSettingsBundleService $bundleService;

    protected function setUp(): void
    {
        parent::setUp();

        $conn = (string) config('database.default');

        // Setup wp_options
        Schema::connection($conn)->dropIfExists('wp_options');
        Schema::connection($conn)->create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        WpOption::clearRequestCache();

        // Setup prompts
        foreach (array_unique([$conn, 'omi_seo_ai']) as $c) {
            Schema::connection($c)->dropIfExists('prompts');
            Schema::connection($c)->create('prompts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('title')->nullable();
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->longText('markdown_content')->nullable();
                $table->string('hook_key')->nullable();
                $table->string('hook_version')->nullable();
                $table->json('hook_settings')->nullable();
                $table->json('variables')->nullable();
                $table->json('settings')->nullable();
                $table->string('tools')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('routing_mode')->nullable();
                $table->string('routing_profile_key')->nullable();
                $table->unsignedBigInteger('ai_connection_id')->nullable();
                $table->uuid('portable_uuid')->nullable()->unique();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Setup seo_tasks
        Schema::connection($conn)->dropIfExists('seo_tasks');
        Schema::connection($conn)->create('seo_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('flow_data')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('portable_uuid', 36)->nullable();
            $table->timestamps();
        });

        // Setup api_connections
        Schema::connection($conn)->dropIfExists('api_connections');
        Schema::connection($conn)->create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('provider')->index();
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->string('default_model')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // Setup ai_routing_profiles & targets
        Schema::connection($conn)->dropIfExists('ai_routing_targets');
        Schema::connection($conn)->dropIfExists('ai_routing_profiles');
        Schema::connection($conn)->create('ai_routing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('key', 64);
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('required_capabilities')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::connection($conn)->create('ai_routing_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('profile_id')->nullable()->index();
            $table->string('profile_key', 64)->index();
            $table->unsignedBigInteger('api_connection_id')->index();
            $table->unsignedBigInteger('seo_ai_model_id')->nullable()->index();
            $table->string('model_key', 128);
            $table->unsignedInteger('priority')->default(1);
            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        // Setup ai_provider_templates
        Schema::connection($conn)->dropIfExists('ai_provider_templates');
        Schema::connection($conn)->create('ai_provider_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('provider_key')->index();
            $table->string('name');
            $table->string('protocol');
            $table->string('schema_version')->default('1.0');
            $table->json('config');
            $table->boolean('is_builtin')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $this->bundleService = app(SeoSettingsBundleService::class);
        $this->actingAs($this->manager(1));
    }

    public function test_full_export_includes_active_and_inactive_prompts_and_tasks(): void
    {
        // Create 2 prompts (1 active, 1 inactive)
        $p1 = SeoPrompt::query()->create([
            'user_id' => 1,
            'name' => 'Active Prompt',
            'markdown_content' => 'Active content',
            'is_active' => true,
        ]);
        $p2 = SeoPrompt::query()->create([
            'user_id' => 1,
            'name' => 'Inactive Prompt',
            'markdown_content' => 'Inactive content',
            'is_active' => false,
        ]);

        // Create 2 tasks (1 active, 1 inactive)
        $t1 = SeoTask::query()->create([
            'user_id' => 1,
            'name' => 'Active Workflow',
            'is_active' => true,
            'flow_data' => ['nodes' => [], 'edges' => []],
        ]);
        $t2 = SeoTask::query()->create([
            'user_id' => 1,
            'name' => 'Inactive Workflow',
            'is_active' => false,
            'flow_data' => ['nodes' => [], 'edges' => []],
        ]);

        $exported = $this->bundleService->export(1, [], includePrompts: true, includeTemplates: true, includeTasks: true);

        $this->assertSame(ConfigurationPackageType::SeoConfigurationBundle->value, $exported['package_type']);
        $this->assertSame('1.1', $exported['schema_version']);
        $this->assertArrayHasKey('prompts', $exported);
        $this->assertArrayHasKey('tasks', $exported);

        $promptList = $exported['prompts']['prompts'] ?? $exported['prompts'];
        $this->assertCount(2, $promptList);
        $promptStates = array_column($promptList, 'enabled', 'name');
        $this->assertTrue($promptStates['Active Prompt']);
        $this->assertFalse($promptStates['Inactive Prompt']);

        $taskList = $exported['tasks'];
        $this->assertCount(2, $taskList);
        $taskStates = array_column($taskList, 'is_active', 'name');
        $this->assertTrue($taskStates['Active Workflow']);
        $this->assertFalse($taskStates['Inactive Workflow']);
    }

    public function test_secrets_never_appear_in_exported_bundle(): void
    {
        ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => 'openrouter',
            'name' => 'My OpenRouter',
            'api_key' => 'sk-or-v1-secret-key-1234567890abcdef',
            'status' => 'active',
        ]);

        $exported = $this->bundleService->export(1, [], includePrompts: true, includeTemplates: true, includeTasks: true);
        $json = json_encode($exported, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('sk-or-v1-secret-key', $json);
        $this->assertStringNotContainsString('"api_key":', $json);
        $this->assertStringNotContainsString('password', strtolower($json));

        // Connections section has skeleton info with credential.configured = true, exported = false
        $connections = $exported['settings']['ai']['connections'] ?? [];
        $this->assertCount(1, $connections);
        $this->assertTrue($connections[0]['credential']['configured']);
        $this->assertFalse($connections[0]['credential']['exported']);
    }

    public function test_import_restores_connection_skeleton_without_secrets_and_disabled(): void
    {
        $payload = [
            'package_type' => 'seo_configuration_bundle',
            'schema_version' => '1.0',
            'settings' => [
                'ai' => [
                    'connections' => [
                        [
                            'connection_ref' => [
                                'provider_key' => 'openrouter',
                                'connection_key' => 'openrouter-main',
                            ],
                            'name' => 'OpenRouter Main',
                            'is_global' => false,
                            'credential' => ['configured' => true, 'exported' => false],
                        ],
                    ],
                ],
            ],
        ];

        $plan = $this->bundleService->plan($payload, 1, 'merge');
        $this->bundleService->apply($plan, 1);

        $restored = ApiConnection::query()->where('user_id', 1)->first();
        $this->assertNotNull($restored);
        $this->assertSame('openrouter', $restored->provider);
        $this->assertSame('OpenRouter Main', $restored->name);
        $this->assertNull($restored->api_key);
        $this->assertSame('inactive', $restored->status);
    }

    public function test_import_never_erases_existing_destination_api_key(): void
    {
        $existingKey = 'sk-existing-preconfigured-secret-key';
        $existing = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => 'openrouter',
            'name' => 'OpenRouter Main',
            'api_key' => $existingKey,
            'status' => 'active',
        ]);

        $payload = [
            'package_type' => 'seo_configuration_bundle',
            'schema_version' => '1.0',
            'settings' => [
                'ai' => [
                    'connections' => [
                        [
                            'connection_ref' => [
                                'provider_key' => 'openrouter',
                                'connection_key' => 'openrouter-main',
                            ],
                            'name' => 'OpenRouter Main',
                            'is_global' => false,
                            'credential' => ['configured' => true, 'exported' => false],
                        ],
                    ],
                ],
            ],
        ];

        $plan = $this->bundleService->plan($payload, 1, 'merge');
        $this->bundleService->apply($plan, 1);

        $refreshed = $existing->fresh();
        $this->assertSame($existingKey, $refreshed->api_key);
        $this->assertSame('active', $refreshed->status);
    }

    public function test_task_flow_data_prompt_references_round_trip_with_different_database_ids(): void
    {
        // 1. Source workspace: Prompt ID = 100, Task ID = 200
        $sourcePrompt = SeoPrompt::query()->create([
            'user_id' => 1,
            'name' => 'Article Generator Prompt',
            'markdown_content' => '# Generate Article',
            'hook_key' => 'article.content.generate',
            'is_active' => true,
        ]);
        $promptUuid = (new \Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPortableIdentity())->ensure($sourcePrompt);

        $sourceTask = SeoTask::query()->create([
            'user_id' => 1,
            'name' => 'Publish Workflow',
            'is_active' => true,
            'flow_data' => [
                'nodes' => [
                    [
                        'id' => 'node_1',
                        'type' => 'prompt',
                        'data' => [
                            'promptId' => (int) $sourcePrompt->id,
                            'execution_role' => 'article.content.generate',
                        ],
                    ],
                ],
                'edges' => [],
            ],
        ]);

        // Export full bundle
        $exported = $this->bundleService->export(1, [], includePrompts: true, includeTasks: true);

        // Ensure exported flow_data does NOT contain source DB ID
        $taskExport = $exported['tasks'][0];
        $this->assertArrayNotHasKey('promptId', $taskExport['flow_data']['nodes'][0]['data']);
        $this->assertSame($promptUuid, $taskExport['flow_data']['nodes'][0]['data']['prompt_ref']['portable_uuid']);

        // 2. Wipe DB and pad destination IDs to ensure IDs differ
        SeoPrompt::query()->forceDelete();
        SeoTask::query()->forceDelete();

        // Create padding records on destination
        for ($i = 0; $i < 3; $i++) {
            SeoPrompt::query()->create(['user_id' => 1, 'name' => 'pad_p_'.$i, 'markdown_content' => 'p']);
            SeoTask::query()->create(['user_id' => 1, 'name' => 'pad_t_'.$i]);
        }

        // Import on destination
        $plan = $this->bundleService->plan($exported, 1, 'update');
        $this->bundleService->apply($plan, 1);

        $importedPrompt = SeoPrompt::query()->where('name', 'Article Generator Prompt')->first();
        $this->assertNotNull($importedPrompt);
        $this->assertNotSame((int) $sourcePrompt->id, (int) $importedPrompt->id);

        $importedTask = SeoTask::query()->where('name', 'Publish Workflow')->first();
        $this->assertNotNull($importedTask);
        $this->assertNotSame((int) $sourceTask->id, (int) $importedTask->id);

        // Verify restored task node points to NEW destination prompt ID!
        $nodeData = $importedTask->flow_data['nodes'][0]['data'];
        $this->assertSame((int) $importedPrompt->id, (int) $nodeData['promptId']);
        $this->assertSame((int) $importedPrompt->id, (int) $nodeData['prompt_id']);
    }

    public function test_import_dependency_order_resolves_bindings_correctly(): void
    {
        $prompt = SeoPrompt::query()->create([
            'user_id' => 1,
            'name' => 'Outline Prompt',
            'markdown_content' => 'Outline template',
            'hook_key' => 'article.outline.generate',
            'is_active' => true,
        ]);
        $task = SeoTask::query()->create([
            'user_id' => 1,
            'name' => 'Auto Publish Task',
            'is_active' => true,
            'flow_data' => ['nodes' => [], 'edges' => []],
        ]);

        app(SeoCreateArticleSettingsService::class)->saveSettings([
            SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS => [
                'article.outline.generate' => (int) $prompt->id,
            ],
            SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE => (int) $task->id,
        ]);

        $exported = $this->bundleService->export(1, ['workflows'], includePrompts: true, includeTasks: true);

        // Wipe destination DB
        SeoPrompt::query()->forceDelete();
        SeoTask::query()->forceDelete();
        WpOption::query()->forceDelete();

        // Apply on clean destination
        $plan = $this->bundleService->plan($exported, 1, 'merge');
        $this->bundleService->apply($plan, 1);

        $destPrompt = SeoPrompt::query()->where('name', 'Outline Prompt')->first();
        $destTask = SeoTask::query()->where('name', 'Auto Publish Task')->first();
        $this->assertNotNull($destPrompt);
        $this->assertNotNull($destTask);

        $settings = app(SeoCreateArticleSettingsService::class);
        $bindings = $settings->getPromptHookBindings();
        $this->assertSame((int) $destPrompt->id, (int) ($bindings['article.outline.generate'] ?? 0));
        $this->assertSame((int) $destTask->id, (int) $settings->getPublishArticleTaskId());
    }

    public function test_general_settings_complete_coverage_and_normalization(): void
    {
        $overview = app(SeoOverviewSettingsService::class);
        $langService = app(SeoContentLanguageSettingsService::class);
        $scopeService = app(SeoAnalyticsScopeSettingsService::class);

        $overview->saveSettings([
            SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS => [
                'https://www.facebook.com/page',
                'linkedin.com',
            ],
            SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => ['.JPG', 'PDF'],
        ]);
        $langService->save([
            SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE => 'vi',
        ]);
        $scopeService->save([
            SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS => false,
        ]);

        $exported = $this->bundleService->export(1, ['general']);
        $general = $exported['settings']['general'];

        $this->assertSame(['facebook.com', 'linkedin.com'], $general[SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS]);
        $this->assertSame(['jpg', 'pdf'], $general[SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS]);
        $this->assertSame('vi', $general[SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE]);
        $this->assertFalse($general[SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS]);

        // Wipe options and restore
        WpOption::query()->forceDelete();
        $plan = $this->bundleService->plan($exported, 1, 'merge');
        $this->bundleService->apply($plan, 1);

        $this->assertSame(['facebook.com', 'linkedin.com'], $overview->getSocialSupportedDomains());
        $this->assertSame(['jpg', 'pdf'], $overview->getTeamChatAllowedExtensions());
        $this->assertSame('vi', $langService->getDefaultContentLanguage());
        $this->assertFalse($scopeService->excludePagesFromStatistics());
    }

    public function test_bundle_import_is_idempotent(): void
    {
        $prompt = SeoPrompt::query()->create([
            'user_id' => 1,
            'name' => 'Idempotent Prompt',
            'markdown_content' => 'content',
            'is_active' => true,
        ]);
        $task = SeoTask::query()->create([
            'user_id' => 1,
            'name' => 'Idempotent Task',
            'is_active' => true,
            'flow_data' => ['nodes' => [], 'edges' => []],
        ]);

        $exported = $this->bundleService->export(1, [], includePrompts: true, includeTasks: true);

        // First apply
        $plan1 = $this->bundleService->plan($exported, 1, 'update');
        $this->bundleService->apply($plan1, 1);

        $promptCount1 = SeoPrompt::query()->count();
        $taskCount1 = SeoTask::query()->count();

        // Second apply
        $plan2 = $this->bundleService->plan($exported, 1, 'update');
        $this->bundleService->apply($plan2, 1);

        $promptCount2 = SeoPrompt::query()->count();
        $taskCount2 = SeoTask::query()->count();

        $this->assertSame($promptCount1, $promptCount2);
        $this->assertSame($taskCount1, $taskCount2);
    }

    public function test_openrouter_preference_is_exported_and_honored(): void
    {
        $exported = $this->bundleService->export(1, ['ai']);
        $this->assertSame('openrouter', $exported['settings']['ai']['preferred_provider']);

        $exported['settings']['ai']['preferred_provider'] = 'gemini';
        $plan = $this->bundleService->plan($exported, 1, 'merge');
        $this->bundleService->apply($plan, 1);

        $aiSection = $this->bundleService->registry()->get('ai');
        $this->assertInstanceOf(AiCenterSettingsSection::class, $aiSection);
        $this->assertSame('gemini', $aiSection->getPreferredProvider());
    }

    public function test_ai_connection_export_whitelists_metadata_and_drops_transient_runtime_state(): void
    {
        ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => 'openrouter',
            'name' => 'Custom OpenRouter',
            'api_key' => 'sk-secret-key-to-scrub',
            'status' => 'active',
            'metadata' => [
                // Whitelisted configuration keys:
                'display_name' => 'Custom OpenRouter',
                'routing_priority' => 10,
                'base_url' => 'https://openrouter.ai/api/v1',
                'notes' => 'Primary production connection',
                // Runtime / transient keys that MUST be dropped:
                'model_catalog' => ['status' => 'synced', 'models' => ['m1', 'm2']],
                'last_error' => 'Connection timed out',
                'catalog_hash' => 'abc123xyz',
                'last_sync_at' => '2026-09-28T12:00:00Z',
                'last_success_at' => '2026-09-28T12:00:00Z',
                'sync_started_at' => '2026-09-28T11:59:00Z',
                'openrouter_free_pool_health' => ['status' => 'healthy'],
                'model_strikes' => ['deepseek/deepseek-chat' => 2],
                'cooldown_until' => '2026-09-28T13:00:00Z',
                'quarantine_until' => '2026-09-28T13:00:00Z',
                'recent_failures' => ['fail1', 'fail2'],
                'seo_ai_model_id' => 999,
                'openrouter_free_review_banner' => true,
                'openrouter_free_catalog_snapshot' => ['snapshot' => true],
            ],
        ]);

        $exported = $this->bundleService->export(1, ['ai']);
        $connections = $exported['settings']['ai']['connections'] ?? [];
        $this->assertCount(1, $connections);

        $meta = $connections[0]['metadata'];
        // Verify whitelisted keys are present
        $this->assertSame('Custom OpenRouter', $meta['display_name']);
        $this->assertSame(10, $meta['routing_priority']);
        $this->assertSame('https://openrouter.ai/api/v1', $meta['base_url']);
        $this->assertSame('Primary production connection', $meta['notes']);

        // Verify runtime / transient keys are completely absent
        $this->assertArrayNotHasKey('model_catalog', $meta);
        $this->assertArrayNotHasKey('last_error', $meta);
        $this->assertArrayNotHasKey('catalog_hash', $meta);
        $this->assertArrayNotHasKey('last_sync_at', $meta);
        $this->assertArrayNotHasKey('last_success_at', $meta);
        $this->assertArrayNotHasKey('sync_started_at', $meta);
        $this->assertArrayNotHasKey('openrouter_free_pool_health', $meta);
        $this->assertArrayNotHasKey('model_strikes', $meta);
        $this->assertArrayNotHasKey('cooldown_until', $meta);
        $this->assertArrayNotHasKey('quarantine_until', $meta);
        $this->assertArrayNotHasKey('recent_failures', $meta);
        $this->assertArrayNotHasKey('seo_ai_model_id', $meta);
        $this->assertArrayNotHasKey('openrouter_free_review_banner', $meta);
        $this->assertArrayNotHasKey('openrouter_free_catalog_snapshot', $meta);
        $this->assertArrayNotHasKey('api_key', $meta);

        // Verify import into clean destination also does not populate runtime keys
        ApiConnection::query()->forceDelete();

        $plan = $this->bundleService->plan($exported, 1, 'merge');
        $this->bundleService->apply($plan, 1);

        $restored = ApiConnection::query()->where('user_id', 1)->first();
        $this->assertNotNull($restored);
        $restoredMeta = $restored->metadata ?? [];
        $this->assertSame('Custom OpenRouter', $restoredMeta['display_name']);
        $this->assertSame(10, $restoredMeta['routing_priority']);
        $this->assertArrayNotHasKey('model_catalog', $restoredMeta);
        $this->assertArrayNotHasKey('openrouter_free_pool_health', $restoredMeta);
    }

    public function test_ai_resilience_settings_round_trip(): void
    {
        $resilienceService = app(\Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService::class);
        $resilienceService->save(1, [
            'max_ai_attempts' => 12,
            'max_free_attempts' => 5,
        ]);

        $exported = $this->bundleService->export(1, ['ai']);
        $this->assertArrayHasKey('resilience', $exported['settings']['ai']);
        $this->assertSame(12, $exported['settings']['ai']['resilience']['max_ai_attempts']);
        $this->assertSame(5, $exported['settings']['ai']['resilience']['max_free_attempts']);

        // Wipe options and restore
        WpOption::query()->forceDelete();

        $plan = $this->bundleService->plan($exported, 1, 'merge');
        $this->bundleService->apply($plan, 1);

        $restored = $resilienceService->get(1);
        $this->assertSame(12, $restored['max_ai_attempts']);
        $this->assertSame(5, $restored['max_free_attempts']);
    }

    public function test_backward_compatibility_import_1_0_bundle_succeeds(): void
    {
        $payload = [
            'package_type' => 'seo_configuration_bundle',
            'schema_version' => '1.0',
            'settings' => [
                'general' => [
                    SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE => 'vi',
                ],
            ],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $plan = $this->bundleService->parseAndPlan($json, 1, 'merge');
        $this->bundleService->apply($plan, 1);

        $this->assertSame('vi', app(SeoContentLanguageSettingsService::class)->getDefaultContentLanguage());
    }

    public function test_unsupported_schema_version_is_rejected(): void
    {
        $payload = [
            'package_type' => 'seo_configuration_bundle',
            'schema_version' => '2.0',
            'settings' => [],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->expectException(\Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException::class);
        $this->bundleService->parseAndPlan($json, 1, 'merge');
    }

    private function manager(int $id): User
    {
        $user = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
        $user->id = $id;

        return $user;
    }
}
