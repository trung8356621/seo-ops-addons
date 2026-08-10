<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionStalenessPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure PHPUnit â€” no mock of final SeoProjectRunItemService.
 */
final class ContentProjectStaleGenerationRecoveryTest extends TestCase
{
    private function policy(): ContentProjectExecutionStalenessPolicy
    {
        /** @var ContentProjectExecutionStalenessPolicy $policy */
        $policy = (new ReflectionClass(ContentProjectExecutionStalenessPolicy::class))
            ->newInstanceWithoutConstructor();

        return $policy;
    }

    public function test_week_old_writing_without_heartbeat_or_article_is_stale(): void
    {
        $stale = $this->policy()->isStaleSnapshot([
            'task_status' => SeoProjectTask::STATUS_WRITING,
            'article_id' => null,
            'article_has_body' => false,
            'has_fresh_active_execution' => false,
            'has_valid_owned_lock' => false,
            'last_progress_at' => Carbon::now()->subWeek(),
            'task_updated_at' => Carbon::now()->subWeek(),
        ], timeoutMinutes: 30);

        self::assertTrue($stale);
    }

    public function test_fresh_active_execution_is_not_stale(): void
    {
        $stale = $this->policy()->isStaleSnapshot([
            'task_status' => SeoProjectTask::STATUS_WRITING,
            'article_id' => null,
            'article_has_body' => false,
            'has_fresh_active_execution' => true,
            'has_valid_owned_lock' => true,
            'last_progress_at' => Carbon::now()->subMinutes(2),
        ], timeoutMinutes: 30);

        self::assertFalse($stale);
    }

    public function test_valid_lock_for_other_fresh_execution_blocks_stale(): void
    {
        $stale = $this->policy()->isStaleSnapshot([
            'task_status' => SeoProjectTask::STATUS_WRITING,
            'article_id' => 0,
            'article_has_body' => false,
            'has_fresh_active_execution' => false,
            'has_valid_owned_lock' => true,
            'last_progress_at' => Carbon::now()->subWeek(),
        ], timeoutMinutes: 30);

        self::assertFalse($stale);
    }

    public function test_article_with_body_not_treated_as_orphan_stale(): void
    {
        $stale = $this->policy()->isStaleSnapshot([
            'task_status' => SeoProjectTask::STATUS_WRITING,
            'article_id' => 99,
            'article_has_body' => true,
            'has_fresh_active_execution' => false,
            'has_valid_owned_lock' => false,
            'last_progress_at' => Carbon::now()->subWeek(),
        ], timeoutMinutes: 30);

        self::assertFalse($stale);
    }

    public function test_stale_item_excluded_from_genuine_running_actions_and_shows_run_again(): void
    {
        $staleRow = [
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
        ];
        $actions = ContentProjectItemActionsPresenter::forRow($staleRow);
        self::assertTrue($actions['create_or_rerun']);
        self::assertSame('rerun', $actions['create_or_rerun_label']);
        self::assertFalse($actions['generate']);
        self::assertFalse($actions['run_again']);
        self::assertFalse($actions['stop_generation']);
        self::assertFalse($actions['resume_generation']);

        $activeRow = $staleRow;
        $activeRow['is_generation_stale'] = false;
        $activeRow['is_genuinely_running'] = true;
        $activeRow['can_generate'] = false;
        $active = ContentProjectItemActionsPresenter::forRow($activeRow);
        self::assertFalse($active['create_or_rerun']);
        self::assertFalse($active['run_again']);
        self::assertTrue($active['stop_generation']);
        self::assertFalse($active['resume_generation']);
    }

    public function test_resume_hidden_without_checkpoint(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
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

        self::assertTrue($actions['create_or_rerun']);
        self::assertFalse($actions['run_again']);
        self::assertFalse($actions['resume_generation']);
    }

    public function test_recovery_service_and_handlers_wire_reconcile(): void
    {
        self::assertTrue(class_exists(ContentProjectGenerationRecoveryService::class));
        self::assertSame(
            'Interrupted: stale generation runtime (no heartbeat / no active worker).',
            ContentProjectGenerationRecoveryService::RECOVERY_MESSAGE,
        );
        self::assertStringContainsString(
            'stale_runtime_abandoned',
            (string) file_get_contents((new ReflectionClass(ContentProjectExecutionStalenessPolicy::class))->getFileName()),
        );
        self::assertStringContainsString(
            'RECOVERY_MESSAGE',
            (string) file_get_contents((new ReflectionClass(ContentProjectGenerationRecoveryService::class))->getFileName()),
        );
        self::assertStringContainsString(
            'releaseStaleDispatchIfOwnedBy',
            (string) file_get_contents((new ReflectionClass(ContentProjectGenerationRecoveryService::class))->getFileName()),
        );

        $generate = (string) file_get_contents((new ReflectionClass(GenerateProjectItemsHandler::class))->getFileName());
        self::assertStringContainsString('generationRecovery->reconcileProject', $generate);
        self::assertStringContainsString('runEngine->start', $generate);
        self::assertStringContainsString("'use_php_engine' => true", $generate);
        self::assertStringContainsString('content_project.generate_started', $generate);

        $rerun = (string) file_get_contents((new ReflectionClass(RerunProjectItemsHandler::class))->getFileName());
        self::assertStringContainsString('recoverTaskIfStale', $rerun);
        self::assertStringContainsString("'rerun' => true", $rerun);
        self::assertStringContainsString('prepareRunQueue', $rerun);
        self::assertStringContainsString('runEngine->start', $rerun);
        self::assertStringContainsString("'use_php_engine' => true", $rerun);
        self::assertStringContainsString('content_project.rerun_started', $rerun);

        $readModel = (string) file_get_contents((new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName());
        self::assertStringContainsString('reconcileProject', $readModel);
        self::assertStringContainsString('is_genuinely_running', $readModel);
        self::assertStringContainsString('is_generation_stale', $readModel);
    }

    public function test_ops_menu_and_page_expose_run_again(): void
    {
        $menu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('createOrRerunOne', $menu);
        self::assertStringContainsString('item_action_smart_rerun', $menu);

        $page = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );
        self::assertStringContainsString('function createOrRerunOne', $page);
        self::assertStringContainsString('function rerunOne', $page);
        self::assertStringContainsString('RerunProjectItemsCommand', $page);
    }

    public function test_pending_with_stale_run_items_counts_as_stale_snapshot(): void
    {
        $stale = $this->policy()->isStaleSnapshot([
            'task_status' => SeoProjectTask::STATUS_PENDING,
            'has_fresh_active_execution' => false,
            'has_valid_owned_lock' => false,
            'stale_active_run_item_count' => 1,
        ], timeoutMinutes: 30);

        self::assertTrue($stale);
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
