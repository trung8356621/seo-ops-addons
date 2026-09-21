<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemAiModeClassifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemAiModeReadModel;
use Tests\TestCase;

/**
 * List AI MODE must follow editor FREE badge SSOT (snapshot stamps via article links).
 */
final class ContentProjectItemAiModeReadModelTest extends TestCase
{
    private string $connection = 'omi_seo_ai';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_prompt_result_links');
        Schema::connection($this->connection)->dropIfExists('prompt_results');
        parent::tearDown();
    }

    public function test_free_split_snapshot_displays_free_split(): void
    {
        $resultId = $this->insertResult([
            'hook_key' => 'article.content.generate',
            'generation_shape' => 'sectioned',
            'generation_shape_source' => 'route_cost_auto',
            'primary_is_free' => true,
            'sectioned_free_orchestrator' => true,
        ]);
        $this->link(13637, $resultId);

        $mode = $this->modeFor(13637);

        self::assertSame('FREE', $mode['mode']);
        self::assertSame('SPLIT', $mode['shape']);
        self::assertSame('FREE · SPLIT', $mode['label']);
    }

    public function test_paid_single_snapshot_displays_paid_single(): void
    {
        $resultId = $this->insertResult([
            'hook_key' => 'article.content.generate',
            'generation_shape' => 'single_pass',
            'shape_decision_cost_class' => 'paid',
        ]);
        $this->link(200, $resultId);

        $mode = $this->modeFor(200);

        self::assertSame('PAID', $mode['mode']);
        self::assertSame('SINGLE', $mode['shape']);
        self::assertSame('PAID · SINGLE', $mode['label']);
    }

    public function test_shape_alone_does_not_infer_free(): void
    {
        $resultId = $this->insertResult([
            'hook_key' => 'article.content.generate',
            'generation_shape' => 'sectioned',
        ]);
        $this->link(201, $resultId);

        $mode = $this->modeFor(201);

        self::assertNull($mode['mode']);
        self::assertSame('—', $mode['label']);
    }

    public function test_unrelated_outline_call_does_not_affect_mode(): void
    {
        $outline = $this->insertResult([
            'hook_key' => 'article.outline.generate',
            'generation_shape' => 'sectioned',
            'shape_decision_cost_class' => 'paid',
        ]);
        $content = $this->insertResult([
            'hook_key' => 'article.content.generate',
            'generation_shape' => 'sectioned',
            'primary_is_free' => true,
            'sectioned_free_orchestrator' => true,
        ]);
        $this->link(202, $outline);
        $this->link(202, $content);

        $mode = $this->modeFor(202);

        self::assertSame('FREE · SPLIT', $mode['label']);
    }

    public function test_no_execution_history_displays_dash(): void
    {
        $rows = (new ContentProjectItemAiModeReadModel())->apply([
            ['task_id' => 1, 'article_id' => 999],
            ['task_id' => 2, 'article_id' => null],
        ]);

        self::assertSame('—', $rows[0]['ai_mode_label']);
        self::assertSame('—', $rows[1]['ai_mode_label']);
    }

    public function test_latest_content_generation_wins_over_older(): void
    {
        $older = $this->insertResult([
            'hook_key' => 'article.content.generate',
            'generation_shape' => 'sectioned',
            'primary_is_free' => true,
        ]);
        $newer = $this->insertResult([
            'hook_key' => 'article.content.generate',
            'generation_shape' => 'single_pass',
            'shape_decision_cost_class' => 'paid',
        ]);
        $this->link(203, $older);
        $this->link(203, $newer);

        self::assertGreaterThan($older, $newer);
        self::assertSame('PAID · SINGLE', $this->modeFor(203)['label']);
    }

    public function test_orchestrator_preferred_over_section_child_link(): void
    {
        $section = $this->insertResult([
            'hook_key' => 'article.content.section.generate',
            'generation_shape' => 'sectioned',
            'is_free' => false,
        ]);
        $parent = $this->insertResult([
            'hook_key' => 'article.content.generate',
            'generation_shape' => 'sectioned',
            'primary_is_free' => true,
            'sectioned_free_orchestrator' => true,
        ]);
        // Newer section link first in id order — priority must still pick orchestrator.
        $this->link(204, $parent);
        $this->link(204, $section);

        self::assertSame('FREE · SPLIT', $this->modeFor(204)['label']);
    }

    public function test_list_query_count_does_not_grow_with_row_count(): void
    {
        foreach ([301, 302, 303, 304] as $articleId) {
            $resultId = $this->insertResult([
                'hook_key' => 'article.content.generate',
                'generation_shape' => 'sectioned',
                'primary_is_free' => true,
                'sectioned_free_orchestrator' => true,
            ]);
            $this->link($articleId, $resultId);
        }

        $reader = new ContentProjectItemAiModeReadModel();
        $reader->apply([['article_id' => 301]]);

        $db = DB::connection($this->connection);
        $db->flushQueryLog();
        $db->enableQueryLog();
        $reader->apply([['article_id' => 301]]);
        $one = $db->getQueryLog();

        $db->flushQueryLog();
        $reader->apply([
            ['article_id' => 301],
            ['article_id' => 302],
            ['article_id' => 303],
            ['article_id' => 304],
        ]);
        $many = $db->getQueryLog();

        self::assertNotEmpty($one);
        self::assertCount(count($one), $many);
        self::assertSame(1, $this->countSql($many, 'seo_prompt_result_links'));
        self::assertSame(1, $this->countSql($many, 'prompt_results'));
        self::assertLessThanOrEqual(4, count($many));
    }

    public function test_classifier_maps_badge_cost_and_shape(): void
    {
        self::assertSame(
            'FREE · SPLIT',
            ContentProjectItemAiModeClassifier::classify(['free'], 'sectioned')['label'],
        );
        self::assertSame(
            'PAID · SINGLE',
            ContentProjectItemAiModeClassifier::classify(['paid'], 'single_pass')['label'],
        );
        self::assertSame(
            '—',
            ContentProjectItemAiModeClassifier::classify([], 'sectioned')['label'],
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function insertResult(array $snapshot): int
    {
        return (int) DB::connection($this->connection)->table('prompt_results')->insertGetId([
            'prompt_id' => 1,
            'user_id' => 1,
            'site_id' => 1,
            'status' => 'completed',
            'input_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function link(int $articleId, int $promptResultId): void
    {
        DB::connection($this->connection)->table('seo_prompt_result_links')->insert([
            'article_id' => $articleId,
            'prompt_result_id' => $promptResultId,
            'source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{mode: string|null, shape: string|null, label: string}
     */
    private function modeFor(int $articleId): array
    {
        $rows = (new ContentProjectItemAiModeReadModel())->apply([
            ['task_id' => 1, 'article_id' => $articleId],
        ]);

        return [
            'mode' => $rows[0]['ai_mode'] ?? null,
            'shape' => $rows[0]['ai_mode_shape'] ?? null,
            'label' => (string) ($rows[0]['ai_mode_label'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $log
     */
    private function countSql(array $log, string $needle): int
    {
        $count = 0;
        foreach ($log as $entry) {
            $haystack = (string) ($entry['query'] ?? '').' '.json_encode($entry['bindings'] ?? []);
            if (str_contains($haystack, $needle)) {
                $count++;
            }
        }

        return $count;
    }

    private function createSchema(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_prompt_result_links');
        $schema->dropIfExists('prompt_results');

        $schema->create('prompt_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id')->default(0);
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('status', 32)->default('pending');
            $table->json('input_snapshot')->nullable();
            $table->timestamps();
        });

        $schema->create('seo_prompt_result_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->unsignedBigInteger('prompt_result_id')->index();
            $table->string('source', 64)->nullable();
            $table->timestamps();
        });
    }
}
