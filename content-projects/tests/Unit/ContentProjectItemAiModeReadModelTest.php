<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemAiModeClassifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemAiModeReadModel;
use Tests\TestCase;

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
        Schema::connection($this->connection)->dropIfExists('prompt_result_routing_attempts');
        Schema::connection($this->connection)->dropIfExists('prompt_results');
        parent::tearDown();
    }

    public function test_all_free_execution_displays_free_split(): void
    {
        $parentId = $this->insertResult(11, 'article.content.generate', 'completed', [
            'generation_shape' => 'sectioned',
            'sectioned_free_orchestrator' => true,
            'child_prompt_result_ids' => [],
        ]);
        $sectionA = $this->insertResult(11, 'article.content.section.generate', 'completed', [
            'parent_prompt_result_id' => $parentId,
            'is_free' => true,
        ]);
        $sectionB = $this->insertResult(11, 'article.content.section.generate', 'completed', [
            'parent_prompt_result_id' => $parentId,
            'is_free' => true,
        ]);
        $this->pointChildren($parentId, [$sectionA, $sectionB]);
        $this->insertAttempt($sectionA, 'free', 'SUCCESS', true, 1);
        $this->insertAttempt($sectionA, 'paid', 'SKIPPED', false, 2);
        $this->insertAttempt($sectionB, 'free', 'FAILED', true, 1);
        $this->insertAttempt($sectionB, 'free', 'SUCCESS', true, 2);

        $mode = $this->modeFor(11);

        self::assertSame('FREE', $mode['mode']);
        self::assertSame('SPLIT', $mode['shape']);
        self::assertSame('FREE · SPLIT', $mode['label']);
    }

    public function test_all_paid_execution_displays_paid_single(): void
    {
        $parentId = $this->insertResult(22, 'article.content.generate', 'completed', [
            'generation_shape' => 'single_pass',
        ]);
        $this->insertAttempt($parentId, 'free', 'FAILED', true, 1);
        $this->insertAttempt($parentId, 'paid', 'SKIPPED', false, 2);
        $this->insertAttempt($parentId, 'paid', 'SUCCESS', true, 3);

        $mode = $this->modeFor(22);

        self::assertSame('PAID', $mode['mode']);
        self::assertSame('SINGLE', $mode['shape']);
        self::assertSame('PAID · SINGLE', $mode['label']);
    }

    public function test_sectioned_shape_does_not_infer_free_when_used_routes_are_paid(): void
    {
        $parentId = $this->insertResult(23, 'article.content.generate', 'completed', [
            'generation_shape' => 'sectioned',
            'shape_decision_cost_class' => 'free',
            'primary_is_free' => true,
        ]);
        $this->insertAttempt($parentId, 'paid', 'SUCCESS', true, 1);

        $mode = $this->modeFor(23);

        self::assertSame('PAID', $mode['mode']);
        self::assertSame('SPLIT', $mode['shape']);
        self::assertSame('PAID · SPLIT', $mode['label']);
    }

    public function test_mixed_successful_section_routes_display_mixed(): void
    {
        $parentId = $this->insertResult(33, 'article.content.generate', 'completed', [
            'generation_shape' => 'sectioned',
            'sectioned_free_orchestrator' => true,
        ]);
        $freeSection = $this->insertResult(33, 'article.content.section.generate', 'completed', [
            'parent_prompt_result_id' => $parentId,
            'is_free' => false,
        ]);
        $paidSection = $this->insertResult(33, 'article.content.section.generate', 'completed', [
            'parent_prompt_result_id' => $parentId,
            'is_free' => true,
        ]);
        $this->pointChildren($parentId, [$freeSection, $paidSection]);
        $this->insertAttempt($freeSection, 'free', 'SUCCESS', true, 1);
        $this->insertAttempt($paidSection, 'free', 'FAILED', true, 1);
        $this->insertAttempt($paidSection, 'paid', 'SUCCESS', true, 2);

        $mode = $this->modeFor(33);

        self::assertSame('MIXED', $mode['mode']);
        self::assertSame('SPLIT', $mode['shape']);
        self::assertSame('MIXED · SPLIT', $mode['label']);
    }

    public function test_successful_section_without_routing_rows_uses_persisted_route_flag(): void
    {
        $parentId = $this->insertResult(34, 'article.content.generate', 'completed', [
            'generation_shape' => 'sectioned',
        ]);
        $freeSection = $this->insertResult(34, 'article.content.section.generate', 'completed', [
            'parent_prompt_result_id' => $parentId,
            'is_free' => true,
        ]);
        $paidSection = $this->insertResult(34, 'article.content.section.generate', 'completed', [
            'parent_prompt_result_id' => $parentId,
            'is_free' => false,
        ]);
        $failedFree = $this->insertResult(34, 'article.content.section.generate', 'failed', [
            'parent_prompt_result_id' => $parentId,
            'is_free' => true,
        ]);
        $this->pointChildren($parentId, [$freeSection, $paidSection, $failedFree]);

        $mode = $this->modeFor(34);

        self::assertSame('MIXED', $mode['mode']);
        self::assertSame('MIXED · SPLIT', $mode['label']);
    }

    public function test_routing_attempt_cost_class_overrides_contradictory_section_flag(): void
    {
        $parentId = $this->insertResult(35, 'article.content.generate', 'completed', [
            'generation_shape' => 'sectioned',
        ]);
        $sectionId = $this->insertResult(35, 'article.content.section.generate', 'completed', [
            'parent_prompt_result_id' => $parentId,
            'is_free' => true,
        ]);
        $this->pointChildren($parentId, [$sectionId]);
        $this->insertAttempt($sectionId, 'paid', 'SUCCESS', true, 1);

        $mode = $this->modeFor(35);

        self::assertSame('PAID', $mode['mode']);
        self::assertSame('PAID · SPLIT', $mode['label']);
    }

    public function test_failed_section_route_does_not_create_mixed_mode(): void
    {
        $parentId = $this->insertResult(36, 'article.content.generate', 'completed', [
            'generation_shape' => 'sectioned',
        ]);
        $paidSection = $this->insertResult(36, 'article.content.section.generate', 'completed', [
            'parent_prompt_result_id' => $parentId,
            'is_free' => false,
        ]);
        $failedFree = $this->insertResult(36, 'article.content.section.generate', 'failed', [
            'parent_prompt_result_id' => $parentId,
            'is_free' => true,
        ]);
        $this->pointChildren($parentId, [$paidSection, $failedFree]);

        $mode = $this->modeFor(36);

        self::assertSame('PAID', $mode['mode']);
        self::assertSame('PAID · SPLIT', $mode['label']);
    }

    public function test_unrelated_ai_calls_do_not_affect_mode(): void
    {
        $parentId = $this->insertResult(44, 'article.content.generate', 'completed', [
            'generation_shape' => 'single_pass',
        ]);
        $this->insertAttempt($parentId, 'free', 'SUCCESS', true, 1);

        $faqId = $this->insertResult(44, 'article.faq.generate', 'completed', [
            'generation_shape' => 'single_pass',
        ]);
        $this->insertAttempt($faqId, 'paid', 'SUCCESS', true, 1);
        $outlineId = $this->insertResult(44, 'article.outline.generate', 'completed', []);
        $this->insertAttempt($outlineId, 'paid', 'SUCCESS', true, 1);
        $imageId = $this->insertResult(44, 'article.image.generate', 'completed', []);
        $this->insertAttempt($imageId, 'paid', 'SUCCESS', true, 1);

        $mode = $this->modeFor(44);

        self::assertSame('FREE', $mode['mode']);
        self::assertSame('FREE · SINGLE', $mode['label']);
    }

    public function test_no_execution_history_displays_dash(): void
    {
        $rows = (new ContentProjectItemAiModeReadModel())->apply([
            ['task_id' => 55],
        ]);

        self::assertNull($rows[0]['ai_mode']);
        self::assertNull($rows[0]['ai_mode_shape']);
        self::assertSame('—', $rows[0]['ai_mode_label']);
    }

    public function test_shape_stamp_without_successful_routes_is_unknown(): void
    {
        $this->insertResult(56, 'article.content.generate', 'completed', [
            'generation_shape' => 'sectioned',
            'primary_is_free' => true,
            'shape_decision_cost_class' => 'free',
        ]);

        $mode = $this->modeFor(56);

        self::assertNull($mode['mode']);
        self::assertSame('—', $mode['label']);
    }

    public function test_latest_generation_wins_over_older_generation(): void
    {
        $older = $this->insertResult(66, 'article.content.generate', 'completed', [
            'generation_shape' => 'sectioned',
        ]);
        $olderSection = $this->insertResult(66, 'article.content.section.generate', 'completed', [
            'parent_prompt_result_id' => $older,
            'is_free' => true,
        ]);
        $this->pointChildren($older, [$olderSection]);
        $this->insertAttempt($olderSection, 'free', 'SUCCESS', true, 1);

        $latest = $this->insertResult(66, 'article.content.generate', 'completed', [
            'generation_shape' => 'single_pass',
        ]);
        $this->insertAttempt($latest, 'paid', 'SUCCESS', true, 1);

        $mode = $this->modeFor(66);

        self::assertGreaterThan($older, $latest);
        self::assertSame('PAID', $mode['mode']);
        self::assertSame('PAID · SINGLE', $mode['label']);
    }

    public function test_list_query_count_does_not_grow_with_row_count(): void
    {
        foreach ([71, 72, 73, 74] as $taskId) {
            $parentId = $this->insertResult($taskId, 'article.content.generate', 'completed', [
                'generation_shape' => 'sectioned',
            ]);
            $sectionId = $this->insertResult($taskId, 'article.content.section.generate', 'completed', [
                'parent_prompt_result_id' => $parentId,
                'is_free' => true,
            ]);
            $this->pointChildren($parentId, [$sectionId]);
            $this->insertAttempt($sectionId, 'free', 'SUCCESS', true, 1);
        }

        $reader = new ContentProjectItemAiModeReadModel();
        $reader->forTaskIds([71]);

        $db = DB::connection($this->connection);
        $db->flushQueryLog();
        $db->enableQueryLog();
        $reader->forTaskIds([71]);
        $one = $db->getQueryLog();

        $db->flushQueryLog();
        $reader->forTaskIds([71, 72, 73, 74]);
        $many = $db->getQueryLog();

        self::assertNotEmpty($one);
        self::assertCount(count($one), $many);
        self::assertSame(1, $this->countSql($many, 'from "prompt_result_routing_attempts"'));
        self::assertSame(1, $this->countSql($many, 'article.content.section.generate'));
        self::assertLessThanOrEqual(8, count($many));
    }

    public function test_classifier_does_not_treat_shape_as_cost_class(): void
    {
        $splitOnly = ContentProjectItemAiModeClassifier::classify([], 'sectioned');
        self::assertNull($splitOnly['mode']);
        self::assertSame('—', $splitOnly['label']);

        $paidSplit = ContentProjectItemAiModeClassifier::classify(['paid', 'paid'], 'sectioned');
        self::assertSame('PAID · SPLIT', $paidSplit['label']);

        $mixed = ContentProjectItemAiModeClassifier::classify(['free', 'paid'], 'single_pass');
        self::assertSame('MIXED · SINGLE', $mixed['label']);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function insertResult(int $taskId, string $key, string $status, array $snapshot): int
    {
        return (int) DB::connection($this->connection)->table('prompt_results')->insertGetId([
            'prompt_id' => 1,
            'user_id' => 1,
            'site_id' => 1,
            'status' => $status,
            'canonical_prompt_key' => $key,
            'project_item_id' => $taskId,
            'input_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAttempt(int $resultId, string $costClass, string $state, bool $attempted, int $sequence): void
    {
        DB::connection($this->connection)->table('prompt_result_routing_attempts')->insert([
            'prompt_result_id' => $resultId,
            'sequence' => $sequence,
            'cost_class' => $costClass,
            'state' => $state,
            'attempted' => $attempted,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<int>  $childIds
     */
    private function pointChildren(int $parentId, array $childIds): void
    {
        $row = DB::connection($this->connection)->table('prompt_results')->where('id', $parentId)->first();
        $snapshot = json_decode((string) ($row->input_snapshot ?? '{}'), true);
        if (! is_array($snapshot)) {
            $snapshot = [];
        }
        $snapshot['child_prompt_result_ids'] = $childIds;
        DB::connection($this->connection)->table('prompt_results')->where('id', $parentId)->update([
            'input_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array{mode: string|null, shape: string|null, label: string}
     */
    private function modeFor(int $taskId): array
    {
        $rows = (new ContentProjectItemAiModeReadModel())->apply([
            ['task_id' => $taskId],
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
        $schema->dropIfExists('prompt_result_routing_attempts');
        $schema->dropIfExists('prompt_results');

        $schema->create('prompt_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id')->default(0);
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('status', 32)->default('pending');
            $table->string('canonical_prompt_key', 191)->nullable();
            $table->unsignedBigInteger('project_item_id')->nullable();
            $table->json('input_snapshot')->nullable();
            $table->timestamps();
        });

        $schema->create('prompt_result_routing_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_result_id')->index();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('cost_class', 32)->nullable();
            $table->string('state', 32)->nullable();
            $table->boolean('attempted')->default(false);
            $table->timestamps();
        });
    }
}
