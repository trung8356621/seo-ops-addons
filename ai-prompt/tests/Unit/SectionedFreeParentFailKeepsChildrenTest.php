<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Parent FAIL must not erase section PromptResults (audit log survives).
 */
final class SectionedFreeParentFailKeepsChildrenTest extends TestCase
{
    private string $connection = 'omi_seo_ai';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection($this->connection)->dropIfExists('prompt_results');
        Schema::connection($this->connection)->dropIfExists('prompts');

        Schema::connection($this->connection)->create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('name')->nullable();
            $table->longText('markdown_content')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('prompt_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id');
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('status', 32)->default('pending');
            $table->json('input_snapshot')->nullable();
            $table->longText('output_text')->nullable();
            $table->json('token_usage')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection($this->connection)->dropIfExists('prompt_results');
        Schema::connection($this->connection)->dropIfExists('prompts');
        parent::tearDown();
    }

    public function test_parent_fail_after_sections_leaves_child_prompt_results(): void
    {
        $promptId = (int) DB::connection($this->connection)->table('prompts')->insertGetId([
            'user_id' => 1,
            'name' => 'Viết bài theo dàn ý',
            'markdown_content' => 'template',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $parent = PromptResult::query()->create([
            'prompt_id' => $promptId,
            'user_id' => 1,
            'site_id' => 1,
            'status' => 'running',
            'input_snapshot' => [
                'sectioned_free_orchestrator' => true,
                'generation_strategy' => 'sectioned_free',
                'compiled_prompt' => 'Strategy: sectioned_free',
                'display_name' => 'Viết bài — Sectioned free (orchestrator)',
                'hook_key' => 'article.content.generate',
            ],
            'started_at' => now(),
        ]);

        $childIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $sectionId = sprintf('section_%02d', $i);
            $actualPrompt = "ACTUAL SECTION PROMPT {$sectionId}\nWrite body only from outline scope.";
            $child = PromptResult::query()->create([
                'prompt_id' => $promptId,
                'user_id' => 1,
                'site_id' => 1,
                'status' => 'completed',
                'input_snapshot' => [
                    'compiled_prompt' => $actualPrompt,
                    'manual_compiled' => true,
                    'hook_key' => 'article.content.section.generate',
                    'display_name' => "Viết bài — Section {$i}/4",
                    'generation_strategy' => 'sectioned_free',
                    'sectioned_free_section' => true,
                    'section_id' => $sectionId,
                    'section_order' => $i - 1,
                    'section_count' => 4,
                    'parent_prompt_result_id' => (int) $parent->id,
                    'raw_model_used' => 'gemini-flash-free',
                    'attempt' => 1,
                ],
                'output_text' => str_repeat('word ', 80),
                'started_at' => now(),
                'finished_at' => now(),
            ]);
            $childIds[] = (int) $child->id;
        }

        // Parent finalization fails AFTER children were committed independently.
        $parent->update([
            'status' => 'failed',
            'error_message' => 'OUTPUT_TRUNCATED',
            'finished_at' => now(),
            'input_snapshot' => array_merge(
                is_array($parent->input_snapshot) ? $parent->input_snapshot : [],
                [
                    'child_prompt_result_ids' => $childIds,
                    'compiled_prompt' => "Strategy: sectioned_free\nCompleted: 4/4\nFailed finalization",
                ],
            ),
        ]);

        $children = PromptResult::query()->whereIn('id', $childIds)->get();
        self::assertCount(4, $children);
        foreach ($children as $child) {
            self::assertSame('completed', (string) $child->status);
            $snap = is_array($child->input_snapshot) ? $child->input_snapshot : [];
            self::assertStringContainsString('ACTUAL SECTION PROMPT', (string) ($snap['compiled_prompt'] ?? ''));
            self::assertStringNotContainsString('TASK_2_', (string) ($snap['compiled_prompt'] ?? ''));
            self::assertNotSame('', trim((string) ($child->output_text ?? '')));
        }

        $parent->refresh();
        self::assertSame('failed', (string) $parent->status);

        $history = new ArticlePromptRunHistoryService;
        $expand = new ReflectionMethod($history, 'expandSplitChildSteps');
        $expand->setAccessible(true);
        /** @var list<array<string, mixed>> $rows */
        $rows = $expand->invoke($history, [
            'type' => 'prompt',
            'title' => 'Viết bài theo dàn ý',
            'status' => 'failed',
            'result_id' => (int) $parent->id,
            'hook_key' => 'article.content.generate',
            'execution_source' => 'sectioned_free_orchestrator',
            'generation_strategy' => 'sectioned_free',
            'prompt_result_ids' => array_merge([(int) $parent->id], $childIds),
            'child_prompt_result_ids' => $childIds,
        ]);
        self::assertCount(5, $rows);
        self::assertCount(4, array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['hook_key'] ?? '') === 'article.content.section.generate',
        ));
    }
}
