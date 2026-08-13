<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectRerunEligibilityGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionStalenessPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationLaunchPlanner;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailedOpsDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPendingOpsDefinition;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Smart Â«Táº¡o bÃ i / Cháº¡y láº¡iÂ» â€” heal stale runtime then Generate/Rerun (no second pipeline).
 */
final class ContentProjectSmartGenerationActionTest extends TestCase
{
    private function policy(): ContentProjectExecutionStalenessPolicy
    {
        /** @var ContentProjectExecutionStalenessPolicy $policy */
        $policy = (new ReflectionClass(ContentProjectExecutionStalenessPolicy::class))
            ->newInstanceWithoutConstructor();

        return $policy;
    }

    public function test_ready_pending_snapshot_not_stale(): void
    {
        $stale = $this->policy()->isStaleSnapshot([
            'task_status' => SeoProjectTask::STATUS_PENDING,
            'has_fresh_active_execution' => false,
            'has_valid_owned_lock' => false,
            'stale_active_run_item_count' => 0,
        ], timeoutMinutes: 30);

        self::assertFalse($stale);
    }

    public function test_stale_pending_dead_runtime_is_stale(): void
    {
        $stale = $this->policy()->isStaleSnapshot([
            'task_status' => SeoProjectTask::STATUS_PENDING,
            'has_fresh_active_execution' => false,
            'has_valid_owned_lock' => false,
            'stale_active_run_item_count' => 1,
            'last_progress_at' => Carbon::now()->subWeek(),
        ], timeoutMinutes: 30);

        self::assertTrue($stale);
    }

    public function test_stale_running_dead_runtime_is_stale(): void
    {
        $stale = $this->policy()->isStaleSnapshot([
            'task_status' => SeoProjectTask::STATUS_WRITING,
            'article_id' => null,
            'article_has_body' => false,
            'has_fresh_active_execution' => false,
            'has_valid_owned_lock' => false,
            'stale_active_run_item_count' => 1,
            'last_progress_at' => Carbon::now()->subWeek(),
            'task_updated_at' => Carbon::now()->subWeek(),
        ], timeoutMinutes: 30);

        self::assertTrue($stale);
    }

    public function test_fresh_active_execution_blocks_stale_even_with_old_progress(): void
    {
        $stale = $this->policy()->isStaleSnapshot([
            'task_status' => SeoProjectTask::STATUS_PENDING,
            'has_fresh_active_execution' => true,
            'has_valid_owned_lock' => false,
            'stale_active_run_item_count' => 2,
            'last_progress_at' => Carbon::now()->subWeek(),
        ], timeoutMinutes: 30);

        self::assertFalse($stale);
    }

    public function test_failed_ops_matches_stale_pending_exec(): void
    {
        $row = [
            'generation_status' => 'pending',
            'execution_status' => 'pending',
            'is_generation_stale' => true,
            'is_genuinely_running' => false,
            'lifecycle' => 'draft',
            'queue_status' => 'none',
        ];

        self::assertTrue(ContentProjectFailedOpsDefinition::matches($row));
        self::assertFalse(ContentProjectPendingOpsDefinition::matches($row));
    }

    public function test_presenter_exposes_one_smart_action_for_ready_pending(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_status' => 'pending',
            'generation_badge' => ['key' => 'pending'],
            'can_generate' => true,
            'is_generate_pending_runnable' => true,
            'can_regen' => false,
            'is_improve' => false,
            'is_scheduled' => false,
            'is_generation_stale' => false,
            'is_genuinely_running' => false,
            'has_resumable_checkpoint' => false,
            'article_edit_url' => null,
        ]);

        self::assertTrue($actions['create_or_rerun']);
        self::assertSame('create', $actions['create_or_rerun_label']);
        self::assertFalse($actions['generate']);
        self::assertFalse($actions['run_again']);
        self::assertFalse($actions['debug_rerun_from_start']);
        self::assertFalse($actions['retry_failed_step']);
    }

    public function test_presenter_exposes_one_smart_action_for_stale_and_failed(): void
    {
        $stale = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'generating',
            'queue_status' => 'none',
            'generation_status' => 'writing',
            'generation_badge' => ['key' => 'running'],
            'can_generate' => true,
            'can_regen' => false,
            'is_improve' => false,
            'is_scheduled' => false,
            'is_generation_stale' => true,
            'is_genuinely_running' => false,
            'has_resumable_checkpoint' => false,
            'article_edit_url' => null,
        ]);
        self::assertTrue($stale['create_or_rerun']);
        self::assertSame('rerun', $stale['create_or_rerun_label']);
        self::assertFalse($stale['generate']);
        self::assertFalse($stale['run_again']);

        $failed = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'queue_status' => 'none',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'can_generate' => true,
            'can_regen' => false,
            'is_improve' => false,
            'is_scheduled' => false,
            'is_generation_stale' => false,
            'is_genuinely_running' => false,
            'has_resumable_checkpoint' => false,
            'article_edit_url' => null,
        ]);
        self::assertTrue($failed['create_or_rerun']);
        self::assertSame('rerun', $failed['create_or_rerun_label']);
    }

    public function test_presenter_hides_smart_action_when_genuinely_running(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'generating',
            'queue_status' => 'none',
            'generation_status' => 'writing',
            'generation_badge' => ['key' => 'running'],
            'can_generate' => false,
            'can_regen' => false,
            'is_improve' => false,
            'is_scheduled' => false,
            'is_generation_stale' => false,
            'is_genuinely_running' => true,
            'has_resumable_checkpoint' => false,
            'article_edit_url' => null,
        ]);

        self::assertFalse($actions['create_or_rerun']);
        self::assertTrue($actions['stop_generation']);
    }

    public function test_stuck_pending_without_runnable_still_gets_smart_action(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_status' => 'pending',
            'generation_badge' => ['key' => 'pending'],
            'can_generate' => true,
            'is_generate_pending_runnable' => false,
            'can_regen' => false,
            'is_improve' => false,
            'is_scheduled' => false,
            'is_generation_stale' => false,
            'is_genuinely_running' => false,
            'has_resumable_checkpoint' => false,
            'article_edit_url' => null,
        ]);

        self::assertTrue($actions['create_or_rerun']);
        self::assertSame('rerun', $actions['create_or_rerun_label']);
    }

    public function test_rewrite_with_article_pending_shows_smart_rerun(): void
    {
        // Screenshot case: rewrite + article + Generation Pending + Skip visible, can_generate false.
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_status' => 'pending',
            'generation_badge' => ['key' => 'pending'],
            'can_generate' => false,
            'is_generate_pending_runnable' => false,
            'can_regen' => true,
            'available_actions' => ['rerun', 'archive'],
            'is_improve' => false,
            'is_scheduled' => false,
            'is_generation_stale' => false,
            'is_genuinely_running' => false,
            'has_resumable_checkpoint' => false,
            'article_edit_url' => '/seo/articles/885/edit',
            'message' => 'Article Ä‘Ã£ thuá»™c task khÃ¡c.',
            'has_unpublished_changes' => true,
        ]);

        self::assertTrue($actions['create_or_rerun']);
        self::assertSame('rerun', $actions['create_or_rerun_label']);
        self::assertTrue($actions['skip_generation']);
        self::assertTrue($actions['open_article']);
        self::assertFalse($actions['generate']);
        self::assertFalse($actions['run_again']);
    }

    public function test_pending_badge_alone_shows_smart_action_even_without_can_generate(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_status' => 'pending',
            'generation_badge' => ['key' => 'pending'],
            'can_generate' => false,
            'can_regen' => false,
            'is_improve' => false,
            'is_scheduled' => false,
            'is_generation_stale' => false,
            'is_genuinely_running' => false,
            'article_edit_url' => null,
        ]);

        self::assertTrue($actions['create_or_rerun']);
        self::assertSame('create', $actions['create_or_rerun_label']);
    }

    public function test_recovery_preserves_non_writing_task_status(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectGenerationRecoveryService::class))->getFileName(),
        );
        self::assertStringContainsString('preserved_task_status', $source);
        self::assertStringContainsString('Only mutate lifecycle generation status when Writing', $source);
        self::assertStringContainsString('activeStatuses()', $source);
    }

    public function test_handlers_and_planner_wire_canonical_path(): void
    {
        self::assertTrue(class_exists(ContentProjectItemGenerationLaunchPlanner::class));
        self::assertSame('BÃ i viáº¿t Ä‘ang Ä‘Æ°á»£c táº¡o.', ContentProjectItemGenerationLaunchPlanner::ACTIVE_MESSAGE);
        self::assertSame('blocked_active', ContentProjectItemGenerationLaunchPlanner::ACTION_BLOCKED_ACTIVE);
        self::assertSame('generate', ContentProjectItemGenerationLaunchPlanner::ACTION_GENERATE);
        self::assertSame('rerun', ContentProjectItemGenerationLaunchPlanner::ACTION_RERUN);
        self::assertSame(ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING, 'operation.already_processing');

        $planner = (string) file_get_contents(
            (new ReflectionClass(ContentProjectItemGenerationLaunchPlanner::class))->getFileName(),
        );
        self::assertStringContainsString('ContentProjectGenerationCapabilityResolver', $planner);
        self::assertStringContainsString('ACTION_GENERATE', $planner);
        self::assertStringContainsString('ACTION_RERUN', $planner);
        self::assertStringContainsString('ACTION_BLOCKED_ACTIVE', $planner);
        self::assertStringContainsString('ACTION_BLOCKED_NONE', $planner);
        self::assertStringContainsString('persist_article_repair', $planner);
        self::assertStringNotContainsString('startRun(', $planner);
        self::assertStringNotContainsString('prepareRunQueue', $planner);

        $generate = (string) file_get_contents((new ReflectionClass(GenerateProjectItemsHandler::class))->getFileName());
        self::assertStringContainsString('generationRecovery->reconcileProject', $generate);

        $rerun = (string) file_get_contents((new ReflectionClass(RerunProjectItemsHandler::class))->getFileName());
        self::assertStringContainsString('recoverTaskIfStale', $rerun);
        self::assertStringContainsString('articleReconciler->reconcileTask', $rerun);
        self::assertStringContainsString('capability->decide', $rerun);

        $guard = (string) file_get_contents(
            (new ReflectionClass(ContentProjectRerunEligibilityGuard::class))->getFileName(),
        );
        self::assertStringContainsString('isFreshActiveRunItem', $guard);
    }

    public function test_ops_menu_exposes_single_create_or_rerun_action(): void
    {
        $menu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('createOrRerunOne', $menu);
        self::assertStringContainsString('item_action_smart_create', $menu);
        self::assertStringContainsString('item_action_smart_rerun', $menu);
        self::assertStringNotContainsString('wire:click="generateOne(', $menu);
        self::assertStringNotContainsString('wire:click="rerunOne(', $menu);
        // Primary CTA + overflow menu share the same smart action (missing-article path uses Alpine modal).
        self::assertSame(2, substr_count($menu, 'wire:click="createOrRerunOne({{ $tid }})"'));
        self::assertStringContainsString('open-missing-article-confirm', $menu);

        $page = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );
        self::assertStringContainsString('function createOrRerunOne', $page);
        self::assertStringContainsString('ContentProjectItemGenerationLaunchPlanner', $page);
        self::assertStringContainsString('RerunProjectItemsCommand', $page);
        self::assertStringContainsString('dispatchGenerate', $page);
        self::assertStringContainsString('item_action_generation_active', $page);
    }

    public function test_bulk_generate_still_reconciles_before_classifier(): void
    {
        $generate = (string) file_get_contents((new ReflectionClass(GenerateProjectItemsHandler::class))->getFileName());
        $reconcilePos = strpos($generate, 'generationRecovery->reconcileProject');
        $previewPos = strpos($generate, 'classifier->preview');
        self::assertNotFalse($reconcilePos);
        self::assertNotFalse($previewPos);
        self::assertLessThan($previewPos, $reconcilePos);
    }

    public function test_recovery_does_not_force_release_foreign_lock(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(ContentProjectGenerationRecoveryService::class, 'releaseStaleDispatchIfOwnedBy'),
        );
        self::assertStringContainsString('ownedItemId !== (int) $item->id', $source);
        self::assertStringContainsString('do not force-release', $source);
        self::assertStringNotContainsString('forceRelease', $source);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
