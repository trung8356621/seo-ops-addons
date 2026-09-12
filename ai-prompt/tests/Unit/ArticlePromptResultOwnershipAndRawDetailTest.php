<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptResultOwnershipResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptReconstructor;
use Omnichannel\Addons\AiPrompt\Services\PromptVersionService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiCallRawDetailService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryArtifactRef;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Tests\TestCase;

/**
 * History list + "Xem prompt" detail must share the same PromptResult ownership SSOT.
 */
final class ArticlePromptResultOwnershipAndRawDetailTest extends TestCase
{
    private string $connection = 'omi_seo_ai';

    private ArticlePromptResultOwnershipResolver $ownership;

    private ArticleAiCallRawDetailService $detail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->ownership = app(ArticlePromptResultOwnershipResolver::class);
        $this->detail = app(ArticleAiCallRawDetailService::class);
    }

    protected function tearDown(): void
    {
        foreach ([
            'seo_prompt_result_links',
            'seo_project_run_items',
            'seo_project_runs',
            'seo_project_tasks',
            'seo_projects',
            'prompt_results',
            'prompt_versions',
            'prompts',
            'articles',
        ] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_b1_snapshot_article_id_without_link_detail_succeeds(): void
    {
        $article = $this->makeArticle(13627);
        $result = $this->makeResult([
            'input_snapshot' => [
                'article_id' => 13627,
                'variables' => ['title' => 'Outline title'],
                'hook_key' => 'article.outline.structure.generate',
            ],
            'canonical_prompt_key' => 'article.outline.structure.generate',
            'output_text' => '{"outline":"ok"}',
        ]);

        self::assertTrue($this->ownership->isOwned(13627, (int) $result->id, []));
        self::assertFalse(
            SeoPromptResultLink::query()->where('prompt_result_id', $result->id)->exists()
        );

        $out = $this->detail->resolve(
            $article,
            ArticleAiHistoryArtifactRef::encodePromptResult((int) $result->id),
            [],
        );

        self::assertTrue($out['success']);
        self::assertNotSame('', trim((string) ($out['prompt'] ?? '')));
        self::assertStringContainsString('ok', (string) ($out['output'] ?? ''));
    }

    public function test_b2_variables_article_id_ownership(): void
    {
        $article = $this->makeArticle(50);
        $result = $this->makeResult([
            'input_snapshot' => [
                'variables' => ['article_id' => 50, 'title' => 'Vocab'],
                'hook_key' => 'article.vocabulary.generate',
            ],
            'canonical_prompt_key' => 'article.vocabulary.generate',
            'output_text' => 'vocab-raw',
        ]);

        self::assertTrue($this->ownership->isOwned(50, (int) $result->id, [1]));
        $out = $this->detail->resolve(
            $article,
            ArticleAiHistoryArtifactRef::encodePromptResult((int) $result->id),
            [1],
        );
        self::assertTrue($out['success']);
        self::assertSame('vocab-raw', $out['output'] ?? null);
    }

    public function test_b3_content_project_run_correlation_without_legacy_link(): void
    {
        $article = $this->makeArticle(70);
        $project = SeoProject::query()->create([
            'name' => 'P',
            'site_id' => 6,
            'status' => 'active',
        ]);
        $task = SeoProjectTask::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => 6,
            'article_id' => 70,
            'type' => 'create',
            'status' => 'ready',
            'keyword' => 'k',
            'title' => 't',
        ]);
        $run = SeoProjectRun::query()->create([
            'project_id' => (int) $project->id,
            'mode' => 'generate',
            'status' => 'completed',
        ]);
        SeoProjectRunItem::query()->create([
            'run_id' => (int) $run->id,
            'task_id' => (int) $task->id,
            'article_id' => 70,
            'action' => 'generate',
            'status' => 'success',
            'attempt' => 1,
            'output_snapshot' => ['steps' => []],
        ]);

        $result = $this->makeResult([
            'input_snapshot' => ['variables' => ['title' => 'via-run']],
            'run_id' => (int) $run->id,
            'content_project_id' => (int) $project->id,
            'output_text' => 'run-out',
        ]);

        self::assertTrue($this->ownership->isOwned(70, (int) $result->id, [(int) $project->id]));
        $out = $this->detail->resolve(
            $article,
            ArticleAiHistoryArtifactRef::encodePromptResult((int) $result->id),
            [(int) $project->id],
        );
        self::assertTrue($out['success']);
    }

    public function test_b4_other_article_rejected(): void
    {
        $article = $this->makeArticle(10);
        $result = $this->makeResult([
            'input_snapshot' => ['article_id' => 11],
        ]);

        self::assertFalse($this->ownership->isOwned(10, (int) $result->id, []));
        $out = $this->detail->resolve(
            $article,
            ArticleAiHistoryArtifactRef::encodePromptResult((int) $result->id),
            [],
        );
        self::assertFalse($out['success']);
        self::assertStringContainsString('Không tìm thấy AI call', (string) ($out['message'] ?? ''));
    }

    public function test_b5_inaccessible_project_run_rejected(): void
    {
        $article = $this->makeArticle(80);
        $project = SeoProject::query()->create([
            'name' => 'Hidden',
            'site_id' => 6,
            'status' => 'active',
        ]);
        $run = SeoProjectRun::query()->create([
            'project_id' => (int) $project->id,
            'mode' => 'generate',
            'status' => 'completed',
        ]);
        SeoProjectRunItem::query()->create([
            'run_id' => (int) $run->id,
            'article_id' => 80,
            'action' => 'generate',
            'status' => 'success',
            'attempt' => 1,
        ]);
        $result = $this->makeResult([
            'input_snapshot' => ['variables' => ['title' => 'x']],
            'run_id' => (int) $run->id,
            'content_project_id' => (int) $project->id,
        ]);

        // No snapshot article_id; only project correlation — inaccessible project ids.
        self::assertFalse($this->ownership->isOwned(80, (int) $result->id, [99999]));
        $out = $this->detail->resolve(
            $article,
            ArticleAiHistoryArtifactRef::encodePromptResult((int) $result->id),
            [99999],
        );
        self::assertFalse($out['success']);
    }

    public function test_b6_reconstructs_without_compiled_prompt_blob(): void
    {
        $article = $this->makeArticle(90);
        $prompt = $this->makePrompt(['markdown_content' => "Write {{title}}"]);
        app(PromptVersionService::class)->syncFromSavedPrompt($prompt);
        $result = $this->makeResult([
            'prompt_id' => (int) $prompt->id,
            'prompt_version_id' => (int) $prompt->fresh()->current_prompt_version_id,
            'input_snapshot' => [
                'article_id' => 90,
                'variables' => ['title' => 'Beta'],
            ],
            'output_text' => 'done',
        ]);

        $reconstructed = app(PromptReconstructor::class)->reconstruct($result->fresh());
        self::assertStringContainsString('Beta', $reconstructed['prompt']);

        $out = $this->detail->resolve(
            $article,
            ArticleAiHistoryArtifactRef::encodePromptResult((int) $result->id),
            [],
        );
        self::assertTrue($out['success']);
        self::assertStringContainsString('Beta', (string) ($out['prompt'] ?? ''));
        self::assertFalse((bool) ($out['hash_mismatch'] ?? false));
    }

    public function test_b7_hash_mismatch_still_opens_with_warning(): void
    {
        $article = $this->makeArticle(91);
        $prompt = $this->makePrompt(['markdown_content' => "Write {{title}}"]);
        app(PromptVersionService::class)->syncFromSavedPrompt($prompt);
        $result = $this->makeResult([
            'prompt_id' => (int) $prompt->id,
            'prompt_version_id' => (int) $prompt->fresh()->current_prompt_version_id,
            'input_snapshot' => [
                'article_id' => 91,
                'variables' => ['title' => 'Gamma'],
            ],
            'compiled_prompt_hash' => str_repeat('a', 64),
            'output_text' => 'out',
        ]);

        $out = $this->detail->resolve(
            $article,
            ArticleAiHistoryArtifactRef::encodePromptResult((int) $result->id),
            [],
        );

        self::assertTrue($out['success']);
        self::assertTrue((bool) ($out['hash_mismatch'] ?? false));
        self::assertStringContainsString(
            ArticleAiCallRawDetailService::LEGACY_RECONSTRUCTED_WARNING,
            (string) ($out['meta'] ?? ''),
        );
        self::assertStringContainsString('Gamma', (string) ($out['prompt'] ?? ''));
    }

    public function test_exact_compiled_prompt_wins_in_detail_resolve(): void
    {
        $article = $this->makeArticle(92);
        $prompt = $this->makePrompt(['markdown_content' => 'Write {{title}} whole article']);
        app(PromptVersionService::class)->syncFromSavedPrompt($prompt);
        $exact = 'EXACT SECTION PROMPT ONLY';
        $result = $this->makeResult([
            'prompt_id' => (int) $prompt->id,
            'prompt_version_id' => (int) $prompt->fresh()->current_prompt_version_id,
            'input_snapshot' => [
                'article_id' => 92,
                'compiled_prompt' => $exact,
                'manual_compiled' => true,
                'sectioned_free_section' => true,
                'variables' => ['title' => 'ShouldNotWin'],
            ],
            'compiled_prompt_hash' => hash('sha256', $exact),
            'output_text' => 'section out',
        ]);
        SeoPromptResultLink::query()->create([
            'prompt_result_id' => (int) $result->id,
            'article_id' => 92,
            'source' => 'test',
        ]);

        $out = $this->detail->resolve(
            $article,
            ArticleAiHistoryArtifactRef::encodePromptResult((int) $result->id),
            [],
        );

        self::assertTrue($out['success']);
        self::assertSame($exact, $out['prompt'] ?? null);
        self::assertTrue((bool) ($out['exact_execution_prompt'] ?? false));
        self::assertFalse((bool) ($out['hash_mismatch'] ?? true));
        self::assertStringNotContainsString(
            ArticleAiCallRawDetailService::HASH_MISMATCH_WARNING,
            (string) ($out['meta'] ?? ''),
        );
        self::assertStringNotContainsString('ShouldNotWin', (string) ($out['prompt'] ?? ''));
    }

    public function test_snapshot_helpers_match_history_sources(): void
    {
        self::assertSame(13627, ArticlePromptResultOwnershipResolver::articleIdFromSnapshot([
            'article_id' => 13627,
        ]));
        self::assertSame(13627, ArticlePromptResultOwnershipResolver::articleIdFromSnapshot([
            'variables' => ['article_id' => 13627],
        ]));

        $src = (string) file_get_contents(
            (string) (new \ReflectionClass(ArticleAiCallRawDetailService::class))->getFileName(),
        );
        self::assertStringContainsString('ArticlePromptResultOwnershipResolver', $src);
        self::assertStringNotContainsString('SeoPromptResultLink::query()', $src);
        self::assertStringContainsString("snapshot['compiled_prompt']", $src);
        self::assertStringContainsString('resolvePromptAuthority', $src);
    }

    private function makeArticle(int $id): SeoArticle
    {
        // Avoid SeoArticle::save() → wordpress_article_links timestamp hook in unit DB.
        $article = new SeoArticle;
        $article->forceFill([
            'id' => $id,
            'site_id' => 6,
            'title' => 'Article '.$id,
            'content' => '',
            'status' => 'draft',
        ]);
        $article->exists = true;

        return $article;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makePrompt(array $attrs = []): SeoPrompt
    {
        return SeoPrompt::query()->create(array_merge([
            'name' => 'Test Prompt',
            'markdown_content' => 'Hello {{title}}',
            'is_active' => true,
            'user_id' => 1,
            'site_id' => 0,
        ], $attrs));
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeResult(array $attrs = []): PromptResult
    {
        $promptId = (int) ($attrs['prompt_id'] ?? 0);
        if ($promptId <= 0) {
            $prompt = $this->makePrompt();
            app(PromptVersionService::class)->syncFromSavedPrompt($prompt);
            $attrs['prompt_id'] = (int) $prompt->id;
            $attrs['prompt_version_id'] = $attrs['prompt_version_id']
                ?? (int) $prompt->fresh()->current_prompt_version_id;
        }

        return PromptResult::query()->create(array_merge([
            'user_id' => 1,
            'site_id' => 6,
            'status' => 'completed',
            'input_snapshot' => [],
            'output_text' => null,
        ], $attrs));
    }

    private function createSchema(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->string('status', 32)->nullable();
            $table->string('slug')->nullable();
            $table->string('focus_keyword')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('outline')->nullable();
            $table->timestamps();
        });

        $schema->create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->longText('markdown_content')->nullable();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('site_id')->default(0);
            $table->unsignedBigInteger('current_prompt_version_id')->nullable();
            $table->string('hook_key')->nullable();
            $table->string('hook_version')->nullable();
            $table->json('hook_settings')->nullable();
            $table->json('settings')->nullable();
            $table->json('variables')->nullable();
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

        $schema->create('seo_prompt_result_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('prompt_result_id');
            $table->unsignedBigInteger('project_run_id')->nullable();
            $table->unsignedBigInteger('project_task_id')->nullable();
            $table->string('source')->nullable();
            $table->string('workflow_node_id')->nullable();
            $table->timestamps();
        });

        $schema->create('seo_project_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->string('keyword')->nullable();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('seo_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        $schema->create('seo_project_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('mode')->nullable();
            $table->string('status')->nullable();
            $table->json('items')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $schema->create('seo_project_run_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('action')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->json('input_snapshot')->nullable();
            $table->json('output_snapshot')->nullable();
            $table->timestamps();
        });
    }
}
