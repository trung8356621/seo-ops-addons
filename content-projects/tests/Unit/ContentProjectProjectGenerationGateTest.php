<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectActiveGenerationRunDetector;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectImproveManualOnlyGenerationGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectActionDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectGenerationGate;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectProjectGenerationGateTest extends TestCase
{
    private ContentProjectItemGenerationClassifier $classifier;

    private ContentProjectActiveGenerationRunDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ContentProjectItemGenerationClassifier(new ContentProjectLifecycle);
        $this->detector = new ContentProjectActiveGenerationRunDetector;
    }

    public function test_a_mixed_project_enables_generate_when_eligible_exist_and_no_bulk(): void
    {
        $eligible = $this->eligibleIdsFromMixedProject();
        self::assertGreaterThan(0, count($eligible));

        $decision = ContentProjectProjectGenerationGate::resolve(
            $eligible,
            conflictActive: false,
            conflictReason: ContentProjectProjectActionDecision::REASON_BULK_ACTIVE,
        );

        self::assertTrue($decision->enabled);
        self::assertSame(ContentProjectProjectActionDecision::REASON_NONE, $decision->reasonCode);
    }

    public function test_b_zero_eligible_disables_generate_with_reason(): void
    {
        $decision = ContentProjectProjectGenerationGate::resolve(
            [],
            conflictActive: false,
            conflictReason: ContentProjectProjectActionDecision::REASON_BULK_ACTIVE,
        );

        self::assertFalse($decision->enabled);
        self::assertSame(ContentProjectProjectActionDecision::REASON_NO_ELIGIBLE, $decision->reasonCode);
    }

    public function test_c_real_bulk_generation_disables_generate(): void
    {
        $now = Carbon::now();
        $live = $this->detector->classifyRunSnapshot([
            'status' => SeoProjectRun::STATUS_RUNNING,
            'mode' => SeoProjectRun::MODE_FULL,
            'total' => 18,
            'updated_at' => $now,
            'started_at' => $now,
        ], $now, 30);

        self::assertTrue($live['is_live']);
        self::assertTrue($live['is_bulk']);

        $decision = ContentProjectProjectGenerationGate::resolve(
            [1, 2, 3],
            conflictActive: $live['is_bulk'] && $live['is_live'],
            conflictReason: ContentProjectProjectActionDecision::REASON_BULK_ACTIVE,
        );
        self::assertFalse($decision->enabled);
        self::assertSame(ContentProjectProjectActionDecision::REASON_BULK_ACTIVE, $decision->reasonCode);
    }

    public function test_d_stale_processing_run_does_not_disable_generate(): void
    {
        $now = Carbon::now();
        $stale = $this->detector->classifyRunSnapshot([
            'status' => SeoProjectRun::STATUS_RUNNING,
            'mode' => SeoProjectRun::MODE_FULL,
            'total' => 18,
            'updated_at' => $now->copy()->subHours(3),
            'started_at' => $now->copy()->subHours(3),
        ], $now, 30);

        self::assertFalse($stale['is_live']);

        $staleItem = $this->classifier->classifySnapshot([
            'task_id' => 99,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'status' => SeoProjectTask::STATUS_PROCESSING,
            'article_id' => 10,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Generating->value,
            'stale_generation' => true,
        ]);
        self::assertTrue($staleItem->shouldRun());

        $decision = ContentProjectProjectGenerationGate::resolve(
            [99, 100],
            conflictActive: $stale['is_live'] && $stale['is_bulk'],
            conflictReason: ContentProjectProjectActionDecision::REASON_BULK_ACTIVE,
        );
        self::assertTrue($decision->enabled);
    }

    public function test_e_availability_derives_from_eligible_not_uniform_state(): void
    {
        $eligible = $this->eligibleIdsFromMixedProject();
        self::assertContains(18, $eligible);
        self::assertNotContains(1, $eligible);
        self::assertNotContains(2, $eligible);
        self::assertNotContains(11, $eligible);
        self::assertNotContains(40, $eligible);
    }

    public function test_f_unrelated_item_processing_does_not_disable_test_run(): void
    {
        $now = Carbon::now();
        $itemRun = $this->detector->classifyRunSnapshot([
            'status' => SeoProjectRun::STATUS_RUNNING,
            'mode' => SeoProjectRun::MODE_FULL,
            'total' => 1,
            'task_ids' => [7],
            'updated_at' => $now,
        ], $now, 30);

        self::assertTrue($itemRun['is_live']);
        self::assertFalse($itemRun['is_bulk']);
        self::assertFalse($itemRun['is_test']);

        $decision = ContentProjectProjectGenerationGate::resolve(
            [18],
            conflictActive: $itemRun['is_test'] && $itemRun['is_live'],
            conflictReason: ContentProjectProjectActionDecision::REASON_TEST_ACTIVE,
        );
        self::assertTrue($decision->enabled);
    }

    public function test_g_active_test_run_disables_test_until_complete(): void
    {
        $now = Carbon::now();
        $test = $this->detector->classifyRunSnapshot([
            'status' => SeoProjectRun::STATUS_RUNNING,
            'mode' => SeoProjectRun::MODE_TEST,
            'total' => 1,
            'updated_at' => $now,
        ], $now, 30);

        self::assertTrue($test['is_live']);
        self::assertTrue($test['is_test']);

        $decision = ContentProjectProjectGenerationGate::resolve(
            [18],
            conflictActive: $test['is_test'] && $test['is_live'],
            conflictReason: ContentProjectProjectActionDecision::REASON_TEST_ACTIVE,
        );
        self::assertFalse($decision->enabled);
        self::assertSame(ContentProjectProjectActionDecision::REASON_TEST_ACTIVE, $decision->reasonCode);
    }

    public function test_h_handler_revalidates_classifier_and_conflicts_server_side(): void
    {
        $handler = (string) file_get_contents(
            (string) (new ReflectionClass(GenerateProjectItemsHandler::class))->getFileName(),
        );
        self::assertStringContainsString('classifier->preview', $handler);
        self::assertStringContainsString('runnableTaskIds', $handler);
        self::assertStringContainsString('hasActiveTestRun', $handler);
        self::assertStringContainsString('hasActiveBulkGeneration', $handler);
        self::assertStringContainsString('Selected items are not eligible', $handler);
    }

    public function test_rewrite_with_source_article_is_eligible(): void
    {
        $d = $this->classifier->classifySnapshot([
            'task_id' => 530,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'status' => SeoProjectTask::STATUS_PENDING,
            'article_id' => 2757,
            'article_has_body' => true,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value,
        ]);

        self::assertTrue($d->shouldRun());
        self::assertSame('never_generated', $d->reason);
    }

    public function test_create_empty_shell_is_eligible_not_article_linked(): void
    {
        $d = $this->classifier->classifySnapshot([
            'task_id' => 8,
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_PENDING,
            'article_id' => 99,
            'article_has_body' => false,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value,
        ]);

        self::assertTrue($d->shouldRun());
        self::assertNotSame('article_linked', $d->reason);
    }

    public function test_live_generating_item_is_skipped_but_does_not_empty_the_set(): void
    {
        $processing = $this->classifier->classifySnapshot([
            'task_id' => 1,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'status' => SeoProjectTask::STATUS_PROCESSING,
            'article_id' => 1,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Generating->value,
        ]);
        $pending = $this->classifier->classifySnapshot([
            'task_id' => 2,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'status' => SeoProjectTask::STATUS_PENDING,
            'article_id' => 2,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value,
        ]);

        self::assertFalse($processing->shouldRun());
        self::assertTrue($pending->shouldRun());
    }

    public function test_improve_filtered_from_project_bulk_eligible_set(): void
    {
        $ids = [10, 11, 12];
        $types = [
            10 => SeoProjectTask::TYPE_REWRITE,
            11 => SeoProjectTask::TYPE_IMPROVE,
            12 => SeoProjectTask::TYPE_REWRITE,
        ];
        $filtered = ContentProjectImproveManualOnlyGenerationGuard::filterItemIds($ids, $types, false);
        self::assertSame([10, 12], $filtered['eligible_ids']);
    }

    public function test_filament_uses_independent_test_run_gate_and_reasons(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        self::assertStringContainsString('canTestRun', $resource);
        self::assertStringContainsString('testRunDisabledReason', $resource);
        self::assertStringContainsString('generatePendingDisabledReason', $resource);
        self::assertStringContainsString('ContentProjectProjectGenerationGate', $resource);
        self::assertStringContainsString('! static::canTestRun($project)', $resource);
    }

    public function test_classifier_preview_scoped_to_working_set(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemGenerationClassifier::class))->getFileName(),
        );
        self::assertStringContainsString('inContentProjectWorkingSet', $src);
        self::assertStringContainsString('typesRequiringExistingArticle', $src);
        self::assertStringNotContainsString("ACTION_SKIP, 'article_linked'", $src);
    }

    /**
     * @return list<int>
     */
    private function eligibleIdsFromMixedProject(): array
    {
        $snapshots = [
            ['task_id' => 1, 'type' => SeoProjectTask::TYPE_CREATE, 'status' => SeoProjectTask::STATUS_COMPLETED, 'article_id' => 1, 'article_has_body' => true, 'lifecycle_phase' => ContentProjectLifecyclePhase::Review->value, 'successful_execution' => true],
            ['task_id' => 2, 'type' => SeoProjectTask::TYPE_REWRITE, 'status' => SeoProjectTask::STATUS_PENDING, 'article_id' => 2, 'lifecycle_phase' => ContentProjectLifecyclePhase::Generating->value],
            ['task_id' => 11, 'type' => SeoProjectTask::TYPE_REWRITE, 'status' => SeoProjectTask::STATUS_REVIEWING, 'article_id' => 11, 'lifecycle_phase' => ContentProjectLifecyclePhase::Review->value],
            ['task_id' => 18, 'type' => SeoProjectTask::TYPE_REWRITE, 'status' => SeoProjectTask::STATUS_PENDING, 'article_id' => 18, 'article_has_body' => true, 'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value],
            ['task_id' => 40, 'type' => SeoProjectTask::TYPE_CREATE, 'status' => SeoProjectTask::STATUS_COMPLETED, 'article_id' => 40, 'article_has_body' => true, 'lifecycle_phase' => ContentProjectLifecyclePhase::Published->value, 'successful_execution' => true],
        ];

        $ids = [];
        foreach ($snapshots as $snap) {
            $d = $this->classifier->classifySnapshot($snap);
            if ($d->shouldRun()) {
                $ids[] = $d->taskId;
            }
        }

        return $ids;
    }
}
