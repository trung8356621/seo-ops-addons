<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use App\Models\User;
use App\Services\PromptTask\PromptTaskCoreMigrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\Prompt;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Tests\TestCase;

final class PromptTaskCanonicalDatabaseCutoverContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure sqlite in-memory tables exist for testing
        Schema::dropIfExists('seo_tasks');
        Schema::dropIfExists('prompt_result_routing_attempts');
        Schema::dropIfExists('prompt_results');
        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('prompts');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Test User');
            $table->string('email')->default('test@example.com');
            $table->timestamps();
        });

        Schema::create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('current_prompt_version_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->default(1)->index();
            $table->unsignedBigInteger('ai_connection_id')->nullable();
            $table->string('routing_mode', 16)->default('auto');
            $table->string('name', 255)->nullable();
            $table->string('title', 255)->default('Test Prompt');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->longText('markdown_content')->nullable();
            $table->json('settings')->nullable();
            $table->json('variables')->nullable();
            $table->json('hook_settings')->nullable();
            $table->string('hook_key', 128)->nullable();
            $table->string('hook_version', 32)->nullable();
            $table->string('tools', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->char('portable_uuid', 36)->nullable();
        });

        Schema::create('prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id')->index();
            $table->string('version_label', 32)->default('1.0.0');
            $table->unsignedInteger('sequence')->default(1);
            $table->longText('markdown_content')->nullable();
            $table->string('hook_key', 255)->nullable();
            $table->string('hook_version', 255)->nullable();
            $table->json('hook_settings')->nullable();
            $table->json('settings')->nullable();
            $table->string('tools', 64)->nullable();
            $table->json('variables')->nullable();
            $table->char('content_fingerprint', 64)->default('test_hash');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('prompt_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id')->index();
            $table->unsignedBigInteger('prompt_version_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->default(1)->index();
            $table->unsignedBigInteger('site_id')->default(1)->index();
            $table->string('status', 32)->default('completed');
            $table->json('input_snapshot')->nullable();
            $table->longText('output_text')->nullable();
            $table->json('token_usage')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('prompt_result_routing_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_result_id')->nullable()->index();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('logical_model', 191)->nullable();
            $table->string('provider', 64)->nullable();
            $table->boolean('attempted')->default(true);
            $table->timestamps();
        });

        Schema::create('seo_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1)->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('flow_data')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

    }

    public function test_canonical_models_use_core_database_trait(): void
    {
        $models = [
            Prompt::class,
            PromptVersion::class,
            PromptResult::class,
            PromptResultRoutingAttempt::class,
            SeoTask::class,
        ];

        foreach ($models as $modelClass) {
            $traits = class_uses_recursive($modelClass);
            self::assertContains(
                UsesCoreDatabaseConnection::class,
                $traits,
                "{$modelClass} must use UsesCoreDatabaseConnection trait"
            );
        }
    }

    public function test_compatibility_aliases_inherit_core_database_trait(): void
    {
        $aliases = [
            SeoPrompt::class,
            SeoPromptResult::class,
        ];

        foreach ($aliases as $aliasClass) {
            $traits = class_uses_recursive($aliasClass);
            self::assertContains(
                UsesCoreDatabaseConnection::class,
                $traits,
                "Alias {$aliasClass} must inherit UsesCoreDatabaseConnection"
            );
        }
    }

    public function test_table_ownership_registry_owns_prompts_and_tasks_on_core(): void
    {
        $configPath = base_path('config/database_table_ownership.php');
        self::assertFileExists($configPath);

        /** @var array<string, mixed> $config */
        $config = require $configPath;
        $coreTables = $config['owners']['core']['tables'] ?? [];

        $expected = [
            'prompts',
            'prompt_versions',
            'prompt_results',
            'prompt_result_routing_attempts',
            'seo_tasks',
            'task_test_results',
        ];

        foreach ($expected as $table) {
            self::assertContains($table, $coreTables, "{$table} must be registered as core table");
        }
    }

    public function test_migration_service_defines_correct_parent_child_order(): void
    {
        $tables = PromptTaskCoreMigrationService::TABLES;

        $taskPos = array_search('seo_tasks', $tables, true);
        $taskTestPos = array_search('task_test_results', $tables, true);
        $promptPos = array_search('prompts', $tables, true);
        $promptVerPos = array_search('prompt_versions', $tables, true);
        $promptResPos = array_search('prompt_results', $tables, true);
        $routingPos = array_search('prompt_result_routing_attempts', $tables, true);

        self::assertLessThan($taskTestPos, $taskPos, 'seo_tasks must be copied before task_test_results');
        self::assertLessThan($promptVerPos, $promptPos, 'prompts must be copied before prompt_versions');
        self::assertLessThan($promptResPos, $promptPos, 'prompts must be copied before prompt_results');
        self::assertLessThan($routingPos, $promptResPos, 'prompt_results must be copied before routing attempts');
    }

    public function test_prompt_crud_and_version_relationship_on_core(): void
    {
        $prompt = new Prompt();
        $prompt->name = 'test_canonical_prompt';
        $prompt->title = 'Test Canonical Prompt';
        $prompt->user_id = 1;
        $prompt->markdown_content = 'Sample content for version';
        $prompt->save();

        self::assertGreaterThan(0, $prompt->id);

        // Version was created by PromptVersionService hook
        $version = $prompt->currentVersion;
        if ($version !== null) {
            self::assertSame($prompt->id, $version->prompt_id);
            self::assertSame($version->id, $prompt->current_prompt_version_id);
        }

        // Test querying
        $loaded = Prompt::query()->find($prompt->id);
        self::assertNotNull($loaded);
        self::assertSame('test_canonical_prompt', $loaded->name);
    }

    public function test_seo_task_crud_on_core(): void
    {
        $task = new SeoTask();
        $task->name = 'Workflow Task Core Test';
        $task->user_id = 1;
        $task->flow_data = ['nodes' => [['id' => '1', 'name' => 'Start']]];
        $task->is_active = true;
        $task->save();

        self::assertGreaterThan(0, $task->id);

        $loaded = SeoTask::query()->find($task->id);
        self::assertNotNull($loaded);
        self::assertSame('Workflow Task Core Test', $loaded->name);
        self::assertCount(1, $loaded->flow_data['nodes']);
    }

    public function test_prompt_result_and_routing_attempt_relationships_on_core(): void
    {
        $prompt = Prompt::query()->create([
            'name' => 'result_test_prompt',
            'title' => 'Result Test Prompt',
            'user_id' => 1,
        ]);

        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'user_id' => 1,
            'site_id' => 1,
            'status' => 'completed',
            'output_text' => 'Generated text',
        ]);

        $attempt = PromptResultRoutingAttempt::query()->create([
            'prompt_result_id' => $result->id,
            'sequence' => 1,
            'logical_model' => 'gemini-1.5-flash',
            'provider' => 'google',
            'attempted' => true,
        ]);

        self::assertSame($prompt->id, $result->prompt->id);
        self::assertSame($result->id, $attempt->promptResult->id);
        self::assertCount(1, $result->routingAttempts);
    }
}
