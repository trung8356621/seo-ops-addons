<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGeneratePendingPreview;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationDecision;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use PHPUnit\Framework\TestCase;

final class ContentProjectGeneratePendingSafetyTest extends TestCase
{
    private ContentProjectItemGenerationClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ContentProjectItemGenerationClassifier(new ContentProjectLifecycle);
    }

    public function test_legacy_31_ok_reviewing_items_not_selected_as_pending(): void
    {
        $decisions = [];
        for ($i = 1; $i <= 28; $i++) {
            $decisions[] = $this->classifier->classifySnapshot([
                'task_id' => $i,
                'type' => SeoProjectTask::TYPE_CREATE,
                'status' => SeoProjectTask::STATUS_COMPLETED,
                'article_id' => 1000 + $i,
                'article_has_body' => true,
                'lifecycle_phase' => ContentProjectLifecyclePhase::Review->value,
                'successful_execution' => true,
            ]);
        }
        for ($i = 29; $i <= 31; $i++) {
            $decisions[] = $this->classifier->classifySnapshot([
                'task_id' => $i,
                'type' => SeoProjectTask::TYPE_CREATE,
                'status' => SeoProjectTask::STATUS_REVIEWING,
                'article_id' => 1000 + $i,
                'article_has_body' => true,
                'lifecycle_phase' => ContentProjectLifecyclePhase::Review->value,
                'successful_execution' => true,
                'last_run_item_status' => 'success',
            ]);
        }

        $preview = new ContentProjectGeneratePendingPreview(
            projectId: 24,
            totalItems: 31,
            decisions: $decisions,
            hasHistoricalExecution: true,
            failClosed: false,
        );

        self::assertSame(0, $preview->runCount());
        self::assertSame(31, count($preview->skipDecisions()));
        self::assertFalse($preview->failClosed);
    }

    public function test_generated_but_not_approved_is_not_pending(): void
    {
        $d = $this->classifier->classifySnapshot([
            'task_id' => 1,
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_COMPLETED,
            'article_id' => 9,
            'article_has_body' => true,
            'article_is_approved' => false,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Review->value,
            'successful_execution' => true,
        ]);

        self::assertFalse($d->shouldRun());
        self::assertSame(ContentProjectItemGenerationDecision::ACTION_SKIP, $d->action);
    }

    public function test_manually_edited_is_not_regenerated_as_pending(): void
    {
        $d = $this->classifier->classifySnapshot([
            'task_id' => 2,
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_PENDING,
            'article_id' => 9,
            'article_manually_edited' => true,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value,
        ]);

        self::assertFalse($d->shouldRun());
        self::assertSame('manually_edited', $d->reason);
    }

    public function test_rewrite_and_improve_pending_run_when_no_output_exists(): void
    {
        foreach ([SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE] as $type) {
            $d = $this->classifier->classifySnapshot([
                'task_id' => 3,
                'type' => $type,
                'status' => SeoProjectTask::STATUS_PENDING,
                'article_id' => 88,
                'article_has_body' => true,
                'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value,
            ]);

            self::assertTrue($d->shouldRun(), $type.' should be generated through the rewrite workflow.');
            self::assertSame('never_generated', $d->reason);
        }
    }

    public function test_only_truly_pending_runs(): void
    {
        $pending = $this->classifier->classifySnapshot([
            'task_id' => 4,
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_PENDING,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value,
        ]);
        $done = $this->classifier->classifySnapshot([
            'task_id' => 5,
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_PENDING,
            'successful_execution' => true,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value,
        ]);

        self::assertTrue($pending->shouldRun());
        self::assertSame('never_generated', $pending->reason);
        self::assertFalse($done->shouldRun());
    }

    public function test_acknowledged_error_without_output_remains_generate_eligible(): void
    {
        $decision = $this->classifier->classifySnapshot([
            'task_id' => 6,
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_PENDING,
            'last_run_item_status' => 'acknowledged_error',
            'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value,
        ]);

        self::assertTrue($decision->shouldRun());
        self::assertSame('failed_without_output', $decision->reason);
        self::assertContains('last_run_item:acknowledged_error', $decision->evidence);
    }

    public function test_preview_counts_match_dispatch_ids(): void
    {
        $decisions = [
            $this->classifier->classifySnapshot([
                'task_id' => 10,
                'type' => SeoProjectTask::TYPE_CREATE,
                'status' => SeoProjectTask::STATUS_PENDING,
                'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value,
            ]),
            $this->classifier->classifySnapshot([
                'task_id' => 11,
                'type' => SeoProjectTask::TYPE_CREATE,
                'status' => SeoProjectTask::STATUS_COMPLETED,
                'lifecycle_phase' => ContentProjectLifecyclePhase::Approved->value,
                'successful_execution' => true,
            ]),
        ];

        $preview = new ContentProjectGeneratePendingPreview(1, 2, $decisions, false, false);

        self::assertSame(1, $preview->runCount());
        self::assertSame([10], $preview->runnableTaskIds());
        self::assertSame($preview->runCount(), count($preview->runnableTaskIds()));
    }

    public function test_fail_closed_when_all_never_generated_with_history(): void
    {
        $decisions = [];
        for ($i = 1; $i <= 3; $i++) {
            $decisions[] = $this->classifier->classifySnapshot([
                'task_id' => $i,
                'type' => SeoProjectTask::TYPE_CREATE,
                'status' => SeoProjectTask::STATUS_PENDING,
                'lifecycle_phase' => ContentProjectLifecyclePhase::Draft->value,
            ]);
        }

        $runCount = count(array_filter(
            $decisions,
            static fn (ContentProjectItemGenerationDecision $d): bool => $d->shouldRun(),
        ));

        self::assertTrue($this->classifier->shouldFailClosed(true, 3, $runCount, $decisions));

        $preview = new ContentProjectGeneratePendingPreview(
            projectId: 1,
            totalItems: 3,
            decisions: $decisions,
            hasHistoricalExecution: true,
            failClosed: true,
            failClosedReason: 'fail_closed_would_rerun_entire_project_with_history',
        );

        self::assertTrue($preview->failClosed);
        self::assertTrue($preview->requiresTechnicalConfirm());
        self::assertSame(3, $preview->runCount());
    }

    public function test_single_item_failed_recovery_not_fail_closed(): void
    {
        $decisions = [
            $this->classifier->classifySnapshot([
                'task_id' => 331,
                'type' => SeoProjectTask::TYPE_CREATE,
                'status' => SeoProjectTask::STATUS_FAILED,
                'lifecycle_phase' => ContentProjectLifecyclePhase::Failed->value,
            ]),
        ];

        self::assertSame('failed_without_output', $decisions[0]->reason);
        self::assertFalse($this->classifier->shouldFailClosed(true, 1, 1, $decisions));
    }

    public function test_all_failed_without_output_multi_not_fail_closed(): void
    {
        $decisions = [];
        for ($i = 1; $i <= 3; $i++) {
            $decisions[] = $this->classifier->classifySnapshot([
                'task_id' => $i,
                'type' => SeoProjectTask::TYPE_CREATE,
                'status' => SeoProjectTask::STATUS_FAILED,
                'lifecycle_phase' => ContentProjectLifecyclePhase::Failed->value,
            ]);
        }

        self::assertFalse($this->classifier->shouldFailClosed(true, 3, 3, $decisions));
    }

    public function test_ui_has_project_operations_not_run_history_hub(): void
    {
        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource.php',
        );
        $opsView = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );
        $opsBlade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        $viewRun = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php',
        );
        $itemsList = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );

        self::assertStringContainsString('view-seo-project-operations', $opsView);
        self::assertStringContainsString('ContentProjectItemOperationsReadModel', $opsView);
        self::assertStringContainsString('applySummaryFilter', $opsBlade);
        self::assertStringContainsString('content-project-items-list', $opsBlade);
        // generation_badge rendering lives in the shared items-list component now.
        self::assertStringContainsString('generation_badge', $itemsList);
        self::assertSame([], \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getRelations());
        self::assertStringContainsString('redirect', strtolower($viewRun));
        self::assertStringContainsString('allowsDevTestGenerateUi', $resource);
        self::assertStringContainsString("environment('production')", $resource);
    }

    public function test_hydrate_service_preserves_reviewing_and_is_idempotent_shaped(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectLegacyExecutionHydrateService.php',
        );

        self::assertStringContainsString('manual_or_lifecycle_status_preserved', $src);
        self::assertStringContainsString('STATUS_REVIEWING', $src);
        self::assertStringContainsString('dry_run', $src);
        self::assertStringContainsString('transaction', strtolower($src));
        self::assertStringNotContainsString('ContentProjectRunEngine', $src);
    }

    public function test_prepare_run_queue_uses_classifier_not_status_pending_only(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php',
        );

        self::assertStringContainsString('ContentProjectItemGenerationClassifier', $src);
        self::assertStringContainsString('technical_confirm_full_rerun', $src);
        self::assertStringContainsString('Rerun requires explicit item selection', $src);
    }
}
