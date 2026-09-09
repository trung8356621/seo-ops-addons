<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\AiHistoryResetService;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionPersistence;
use Omnichannel\Addons\AiPrompt\Services\PromptReconstructor;
use Omnichannel\Addons\AiPrompt\Services\PromptVersionService;
use Omnichannel\Addons\AiPrompt\Support\AiHistoryRouteDisplay;
use Omnichannel\Addons\Content\Support\ArticleAiHistoryPromptCentricPresenter;
use Tests\TestCase;

final class PromptVersionAndHistoryStorageTest extends TestCase
{
    private string $connection = 'omi_seo_ai';

    private PromptVersionService $versions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->versions = app(PromptVersionService::class);
    }

    protected function tearDown(): void
    {
        Schema::connection($this->connection)->dropIfExists('prompt_result_routing_attempts');
        Schema::connection($this->connection)->dropIfExists('prompt_results');
        Schema::connection($this->connection)->dropIfExists('prompt_versions');
        Schema::connection($this->connection)->dropIfExists('prompts');
        parent::tearDown();
    }

    public function test_existing_prompt_gets_one_initial_version_in_d_m_yy(): void
    {
        $prompt = $this->makePrompt(['updated_at' => Carbon::parse('2026-09-08 10:00:00')]);
        $version = $this->versions->syncFromSavedPrompt($prompt);

        self::assertInstanceOf(PromptVersion::class, $version);
        self::assertSame('8.9.26', $version->version_label);
        self::assertSame(1, (int) PromptVersion::query()->where('prompt_id', $prompt->id)->count());
        self::assertSame((int) $version->id, (int) $prompt->fresh()->current_prompt_version_id);
    }

    public function test_second_same_day_change_uses_r2_then_r3(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 08:00:00'));
        $prompt = $this->makePrompt(['markdown_content' => 'v1', 'updated_at' => now()]);
        $first = $this->versions->syncFromSavedPrompt($prompt);
        self::assertSame('9.9.26', $first?->version_label);

        $prompt->markdown_content = 'v2';
        $prompt->save();
        $second = $this->versions->currentVersion($prompt->fresh());
        self::assertSame('9.9.26-r2', $second?->version_label);

        $prompt->markdown_content = 'v3';
        $prompt->save();
        $third = $this->versions->currentVersion($prompt->fresh());
        self::assertSame('9.9.26-r3', $third?->version_label);
        Carbon::setTestNow();
    }

    public function test_no_effective_change_does_not_create_version(): void
    {
        $prompt = $this->makePrompt(['markdown_content' => 'same']);
        $this->versions->syncFromSavedPrompt($prompt);
        $before = PromptVersion::query()->where('prompt_id', $prompt->id)->count();

        $prompt->name = 'Renamed only';
        $prompt->description = 'ui only';
        $prompt->save();

        self::assertSame($before, PromptVersion::query()->where('prompt_id', $prompt->id)->count());
    }

    public function test_effective_change_creates_immutable_version(): void
    {
        $prompt = $this->makePrompt(['markdown_content' => 'old']);
        $first = $this->versions->syncFromSavedPrompt($prompt);
        self::assertNotNull($first);
        $oldId = (int) $first->id;
        $oldBody = (string) $first->markdown_content;

        $prompt->markdown_content = 'new body';
        $prompt->save();
        $second = $this->versions->currentVersion($prompt->fresh());

        self::assertNotSame($oldId, (int) $second?->id);
        self::assertSame('old', PromptVersion::query()->find($oldId)?->markdown_content);
        self::assertSame($oldBody, 'old');

        $this->expectException(\RuntimeException::class);
        $frozen = PromptVersion::query()->findOrFail($oldId);
        $frozen->markdown_content = 'mutated';
        $frozen->save();
    }

    public function test_fingerprint_is_stable_across_hook_settings_key_order(): void
    {
        $left = new SeoPrompt;
        $left->markdown_content = 'body';
        $left->hook_key = 'article.content.generate';
        $left->hook_version = '0.1.0';
        $left->hook_settings = ['b' => 1, 'a' => ['z' => 2, 'y' => 3]];
        $left->tools = 'default';
        $left->settings = [];

        $right = new SeoPrompt;
        $right->markdown_content = 'body';
        $right->hook_key = 'article.content.generate';
        $right->hook_version = '0.1.0';
        $right->hook_settings = ['a' => ['y' => 3, 'z' => 2], 'b' => 1];
        $right->tools = 'default';
        $right->settings = [];

        self::assertSame($this->versions->fingerprint($left), $this->versions->fingerprint($right));
    }

    public function test_execution_stores_version_id_and_strips_compiled_prompt(): void
    {
        $prompt = $this->makePrompt(['markdown_content' => 'Write {{title}}']);
        $this->versions->syncFromSavedPrompt($prompt);

        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'user_id' => 1,
            'site_id' => 0,
            'status' => 'completed',
            'input_snapshot' => [
                'variables' => ['title' => 'Hello', 'hook_key' => 'article.content.generate'],
                'compiled_prompt' => 'Write Hello — this must not persist',
                'hook_key' => 'article.content.generate',
                'article_id' => 9,
            ],
            'output_text' => str_repeat('word ', 50),
            'token_usage' => [
                'routing' => [
                    'routing_attempts' => [
                        [
                            'attempt' => 1,
                            'result' => 'success',
                            'attempted' => true,
                            'provider' => 'deepseek',
                            'model' => 'deepseek-chat',
                            'logical_model' => 'deepseek-chat',
                            'physical_route' => 'deepseek-direct',
                        ],
                    ],
                    'correlation_id' => 'corr-1',
                ],
                'normalized_failure' => [
                    'category' => 'VALIDATION',
                    'code' => 'AI_OUTPUT_TOO_SHORT',
                    'user_message' => '434 / 501',
                ],
            ],
            'started_at' => now(),
        ]);

        $fresh = $result->fresh();
        self::assertNotNull($fresh);
        $snap = is_array($fresh->input_snapshot) ? $fresh->input_snapshot : [];
        self::assertArrayNotHasKey('compiled_prompt', $snap);
        self::assertNotEmpty($fresh->compiled_prompt_hash);
        self::assertSame((int) $prompt->current_prompt_version_id, (int) $fresh->prompt_version_id);
        self::assertSame('article.content.generate', $fresh->canonical_prompt_key);
        self::assertSame('VALIDATION', $fresh->failure_category);
        self::assertSame(1, $fresh->routingAttempts()->count());
        self::assertTrue((bool) $fresh->routingAttempts()->first()?->attempted);
    }

    public function test_routing_attempt_sequence_is_event_order_not_api_attempt_number(): void
    {
        $prompt = $this->makePrompt(['markdown_content' => 'x']);
        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'user_id' => 1,
            'site_id' => 0,
            'status' => 'completed',
            'input_snapshot' => ['hook_key' => 'article.outline.generate'],
            'token_usage' => [
                'routing' => [
                    'routing_attempts' => [
                        [
                            'attempt' => 1,
                            'result' => 'skipped',
                            'attempted' => false,
                            'skip_reason' => 'model_cooldown',
                            'provider' => 'openrouter',
                            'model' => 'nvidia/nemotron-3-ultra-550b-a55b:free',
                        ],
                        [
                            'attempt' => 2,
                            'result' => 'skipped',
                            'attempted' => false,
                            'skip_reason' => 'model_cooldown',
                            'provider' => 'openrouter',
                            'model' => 'google/gemma-4-26b-a4b-it:free',
                        ],
                        [
                            'attempt' => 1,
                            'result' => 'success',
                            'attempted' => true,
                            'provider' => 'openrouter',
                            'model' => 'nvidia/nemotron-3-super-120b-a12b:free',
                        ],
                    ],
                ],
            ],
        ]);

        $rows = $result->fresh()?->routingAttempts()->orderBy('sequence')->get() ?? collect();
        self::assertCount(3, $rows);
        self::assertSame([1, 2, 3], $rows->pluck('sequence')->map(static fn (mixed $v): int => (int) $v)->all());
        self::assertFalse((bool) $rows[0]->attempted);
        self::assertFalse((bool) $rows[1]->attempted);
        self::assertTrue((bool) $rows[2]->attempted);
        self::assertSame('nvidia/nemotron-3-super-120b-a12b:free', $rows[2]->provider_model);
    }

    public function test_failed_routes_exhausted_token_usage_syncs_monotonic_sequences(): void
    {
        $prompt = $this->makePrompt(['markdown_content' => 'x']);
        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'user_id' => 1,
            'site_id' => 0,
            'status' => 'failed',
            'error_message' => 'AI_ROUTES_EXHAUSTED: 3 AI attempt(s) failed',
            'input_snapshot' => ['hook_key' => 'article.outline.structure.generate'],
            'token_usage' => [
                'routing' => [
                    'routing_mode' => 'free_first_with_paid_fallback',
                    'routing_terminal_reason' => 'routes_exhausted',
                    'routing_attempts' => [
                        [
                            'attempt' => 1,
                            'result' => 'failed',
                            'attempted' => true,
                            'provider' => 'openrouter',
                            'model' => 'nvidia/nemotron-3-ultra-550b-a55b:free',
                        ],
                        [
                            'attempt' => 2,
                            'result' => 'failed',
                            'attempted' => true,
                            'provider' => 'openrouter',
                            'model' => 'google/gemma-4-26b-a4b-it:free',
                        ],
                        [
                            'attempt' => 3,
                            'result' => 'failed',
                            'attempted' => true,
                            'provider' => 'openrouter',
                            'model' => 'google/gemma-4-31b-it:free',
                        ],
                        [
                            'attempt' => 1,
                            'result' => 'skipped',
                            'attempted' => false,
                            'skip_reason' => 'connection_paid_locked',
                            'provider' => 'openrouter',
                            'model' => 'openai/gpt-5.4',
                        ],
                    ],
                ],
                'normalized_failure' => [
                    'category' => 'PROVIDER',
                    'code' => 'AI_PROVIDER_EMPTY_OUTPUT',
                ],
            ],
        ]);

        $rows = $result->fresh()?->routingAttempts()->orderBy('sequence')->get() ?? collect();
        self::assertCount(4, $rows);
        self::assertSame([1, 2, 3, 4], $rows->pluck('sequence')->map(static fn (mixed $v): int => (int) $v)->all());
        self::assertTrue((bool) $rows[0]->attempted);
        self::assertFalse((bool) $rows[3]->attempted);
        self::assertSame('connection_paid_locked', $rows[3]->skip_reason);
        self::assertSame('article.outline.structure.generate', $result->fresh()?->canonical_prompt_key);
    }

    public function test_reconstructor_compiles_from_prompt_version(): void
    {
        $prompt = $this->makePrompt(['markdown_content' => "# Task\nWrite {{title}}"]);
        $this->versions->syncFromSavedPrompt($prompt);
        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'user_id' => 1,
            'site_id' => 0,
            'status' => 'completed',
            'input_snapshot' => [
                'variables' => ['title' => 'Alpha'],
                'compiled_prompt' => 'ignored blob',
            ],
        ]);

        $out = app(PromptReconstructor::class)->reconstruct($result->fresh());
        self::assertStringContainsString('Alpha', $out['prompt']);
        self::assertNotEmpty($out['version_label']);
    }

    public function test_history_hot_columns_exclude_output_text(): void
    {
        self::assertNotContains('output_text', PromptResult::HOT_COLUMNS);
        self::assertContains('prompt_version_id', PromptResult::HOT_COLUMNS);
        self::assertContains('compiled_prompt_hash', PromptResult::HOT_COLUMNS);
        $src = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService::class))->getFileName()
        );
        self::assertStringContainsString('promptResultHotQuery', $src);
        self::assertStringContainsString('HOT_COLUMNS', $src);
        self::assertStringContainsString("'prompt' => ''", $src);
        self::assertStringContainsString("'result' => ''", $src);
    }

    public function test_history_groups_by_canonical_key_and_shows_version(): void
    {
        $groups = ArticleAiHistoryPromptCentricPresenter::regroupByPromptKey([
            [
                'prompts' => [
                    [
                        'canonical_prompt_key' => 'article.content.generate',
                        'type' => 'ARTICLE CONTENT',
                        'prompt_version_label' => '9.9.26',
                        'ran_at' => '2026-09-09 09:13:00',
                        'status' => 'failed',
                        'failure_category' => 'VALIDATION',
                    ],
                ],
            ],
        ], latestOnly: true);

        self::assertSame('article.content.generate', $groups[0]['prompt_key']);
        self::assertSame('9.9.26', $groups[0]['prompts'][0]['prompt_version_label']);
        self::assertCount(1, $groups[0]['prompts']);
    }

    public function test_provider_success_plus_validation_failure_and_no_attempt(): void
    {
        $success = AiHistoryRouteDisplay::resolveModelDisplay([], [
            ['result' => 'success', 'attempted' => true, 'provider' => 'deepseek', 'model' => 'deepseek-chat'],
        ]);
        self::assertStringContainsString('deepseek-chat', $success);

        $none = AiHistoryRouteDisplay::resolveModelDisplay([], [
            ['result' => 'skipped', 'attempted' => false, 'skip_reason' => 'All eligible candidates blocked by policy/health'],
        ]);
        self::assertStringStartsWith(AiHistoryRouteDisplay::MODEL_NO_ATTEMPT, $none);
    }

    public function test_prompt_list_source_has_version_first_no_updated(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource::class))->getFileName()
        );
        $versionPos = strpos($src, "TextColumn::make('currentVersion.version_label')");
        $namePos = strpos($src, "TextColumn::make('name')");
        self::assertNotFalse($versionPos);
        self::assertLessThan($namePos, $versionPos);
        self::assertStringNotContainsString("TextColumn::make('updated_at')", $src);
    }

    public function test_reset_deletes_history_keeps_prompts_and_versions(): void
    {
        $prompt = $this->makePrompt(['markdown_content' => 'keep me']);
        $this->versions->syncFromSavedPrompt($prompt);
        PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'user_id' => 1,
            'site_id' => 0,
            'status' => 'completed',
            'input_snapshot' => ['compiled_prompt' => 'blob', 'hook_key' => 'article.outline.generate'],
        ]);

        self::assertSame(1, PromptResult::query()->count());
        $counts = app(AiHistoryResetService::class)->reset();

        self::assertSame(0, PromptResult::query()->count());
        self::assertSame(1, SeoPrompt::query()->count());
        self::assertGreaterThan(0, PromptVersion::query()->count());
        self::assertSame(1, $counts['prompts_preserved']);
        self::assertGreaterThan(0, $counts['prompt_versions_preserved']);
    }

    public function test_persistence_slim_snapshot_drops_compiled_prompt(): void
    {
        $slim = app(PromptExecutionPersistence::class)->slimSnapshot([
            'compiled_prompt' => 'FULL PROMPT TEXT',
            'article_id' => 3,
            'variables' => ['title' => 'x', 'post_content' => str_repeat('huge ', 50)],
        ]);
        self::assertArrayNotHasKey('compiled_prompt', $slim);
        self::assertArrayNotHasKey('post_content', $slim['variables']);
        self::assertSame('x', $slim['variables']['title']);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makePrompt(array $attrs = []): SeoPrompt
    {
        $now = $attrs['updated_at'] ?? now();

        return SeoPrompt::query()->create(array_merge([
            'user_id' => 1,
            'title' => 'Test prompt',
            'name' => 'Test prompt',
            'markdown_content' => "# Task\nHello",
            'hook_key' => 'article.content.generate',
            'hook_version' => '0.1.0',
            'tools' => 'default',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attrs));
    }

    private function createSchema(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('prompt_result_routing_attempts');
        $schema->dropIfExists('prompt_results');
        $schema->dropIfExists('prompt_versions');
        $schema->dropIfExists('prompts');

        $schema->create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('current_prompt_version_id')->nullable();
            $table->unsignedBigInteger('user_id')->default(0);
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
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id')->index();
            $table->string('version_label', 32);
            $table->unsignedInteger('sequence')->default(1);
            $table->longText('markdown_content')->nullable();
            $table->string('hook_key')->nullable();
            $table->string('hook_version')->nullable();
            $table->json('hook_settings')->nullable();
            $table->json('settings')->nullable();
            $table->string('tools', 64)->nullable();
            $table->json('variables')->nullable();
            $table->char('content_fingerprint', 64);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('prompt_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id');
            $table->unsignedBigInteger('prompt_version_id')->nullable();
            $table->string('canonical_prompt_key', 191)->nullable();
            $table->string('stage', 191)->nullable();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('status', 32)->default('pending');
            $table->json('input_snapshot')->nullable();
            $table->longText('output_text')->nullable();
            $table->json('token_usage')->nullable();
            $table->text('error_message')->nullable();
            $table->char('compiled_prompt_hash', 64)->nullable();
            $table->unsignedBigInteger('content_project_id')->nullable();
            $table->unsignedBigInteger('project_item_id')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->string('node_id', 120)->nullable();
            $table->unsignedInteger('retry_attempt')->nullable();
            $table->string('correlation_id', 191)->nullable();
            $table->string('failure_category', 64)->nullable();
            $table->string('failure_code', 128)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $schema->create('prompt_result_routing_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_result_id')->index();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('logical_model', 191)->nullable();
            $table->string('physical_route', 191)->nullable();
            $table->string('provider', 64)->nullable();
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->string('connection_name', 191)->nullable();
            $table->string('provider_model', 191)->nullable();
            $table->string('cost_class', 32)->nullable();
            $table->string('state', 32)->nullable();
            $table->boolean('attempted')->default(false);
            $table->string('skip_reason', 191)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('failure_category', 64)->nullable();
            $table->string('failure_code', 128)->nullable();
            $table->string('failure_scope', 64)->nullable();
            $table->string('health_mutation', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('token_usage')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }
}
