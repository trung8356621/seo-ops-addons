<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\ContentProjects\Filament\Pages\GlobalMonthlyGeneratorPage;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGenerationLock;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatchItem;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartGlobalMonthlyBatchCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\StartGlobalMonthlyBatchHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\ContentProjectGlobalLockBridge;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyAiPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchSnapshotBuilder;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GlobalMonthlyGeneratorContractTest extends TestCase
{
    public function test_command_bus_registers_global_batch_commands(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectCommandBusRegistrar::class))->getFileName(),
        );

        self::assertStringContainsString('StartGlobalMonthlyBatchCommand::class => StartGlobalMonthlyBatchHandler::class', $src);
        self::assertStringContainsString('StopGlobalMonthlyBatchCommand::class => StopGlobalMonthlyBatchHandler::class', $src);
        self::assertStringContainsString('ResumeGlobalMonthlyBatchCommand::class => ResumeGlobalMonthlyBatchHandler::class', $src);
    }

    public function test_generate_handler_uses_global_lock_bridge(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GenerateProjectItemsHandler::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectGlobalLockBridge', $src);
        self::assertStringContainsString('assertMayStart', $src);
        self::assertStringContainsString('acquireForProjectRun', $src);
        self::assertStringContainsString('releaseForProjectRun', $src);

        $bridgeSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectGlobalLockBridge::class))->getFileName(),
        );
        self::assertStringContainsString('inherits_global_lock', $bridgeSrc);
    }

    public function test_run_engine_notifies_lock_bridge_on_terminal(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectRunEngine::class))->getFileName(),
        );

        self::assertStringContainsString('notifyGlobalLockBridge', $src);
        self::assertStringContainsString('ContentProjectGlobalLockBridge', $src);
    }

    public function test_orchestrator_safety_contracts_present(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );

        self::assertStringContainsString('legacyScanner->findActiveRuns', $src);
        self::assertStringContainsString('failBatchAndRelease', $src);
        self::assertStringContainsString('lock->release', $src);
        self::assertStringContainsString('STATUS_SUCCEEDED', $src);
        self::assertStringContainsString('No pending items. Failed items require explicit retry', $src);
        self::assertStringContainsString('GlobalMonthlyAiPolicy::childRunSettings', $src);
        self::assertStringContainsString('assertUnchanged', $src);
        self::assertStringContainsString('fail_closed', $src);
        self::assertStringContainsString('Stale advance ignored', $src);
    }

    public function test_lock_bridge_holds_on_recoverable_worker_lost(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectGlobalLockBridge::class))->getFileName(),
        );

        self::assertStringContainsString('isRecoverableRun', $src);
        self::assertStringContainsString('hold_recoverable', $src);
        self::assertStringContainsString('AdvanceGlobalMonthlyBatchJob::dispatch', $src);
    }

    public function test_ui_entry_and_page_exist(): void
    {
        $listSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );
        self::assertStringContainsString('global_monthly_generator', $listSrc);
        self::assertStringContainsString('Generate tháng', $listSrc);

        $page = new ReflectionClass(GlobalMonthlyGeneratorPage::class);
        self::assertTrue($page->hasMethod('startBatch'));
        self::assertTrue($page->hasMethod('stopBatch'));
        self::assertTrue($page->hasMethod('resumeBatch'));

        $pageSrc = (string) file_get_contents((string) $page->getFileName());
        self::assertStringContainsString("slug = 'global-monthly-generator'", $pageSrc);
    }

    public function test_models_and_lock_constants(): void
    {
        self::assertSame('global', SeoContentGenerationLock::SLOT_GLOBAL);
        self::assertSame('global_batch', SeoContentGenerationLock::OWNER_GLOBAL_BATCH);
        self::assertSame('project_run', SeoContentGenerationLock::OWNER_PROJECT_RUN);
        self::assertSame('pending', SeoContentGlobalBatch::STATUS_PENDING);
        self::assertSame('succeeded', SeoContentGlobalBatchItem::STATUS_SUCCEEDED);
        self::assertSame('Đang có tiến trình Generate khác hoạt động.', GlobalGenerationLock::BUSY_MESSAGE);
    }

    public function test_ai_policy_sticky_free_and_paid(): void
    {
        $free = GlobalMonthlyAiPolicy::normalize('free_only');
        $paid = GlobalMonthlyAiPolicy::normalize('default');

        self::assertTrue($free->isFreeOnly());
        self::assertFalse($paid->isFreeOnly());

        $freeSettings = GlobalMonthlyAiPolicy::childRunSettings($free);
        self::assertSame(AiCostPolicy::FreeOnly->value, $freeSettings[AiCostPolicy::SETTING_KEY]);
        self::assertSame('free_only', $freeSettings['ai_generation_mode']);

        $paidSettings = GlobalMonthlyAiPolicy::childRunSettings($paid);
        self::assertSame(AiCostPolicy::Default->value, $paidSettings[AiCostPolicy::SETTING_KEY]);
        self::assertSame('normal', $paidSettings['ai_generation_mode']);

        GlobalMonthlyAiPolicy::assertUnchanged($free, 'free_only');

        $this->expectException(\InvalidArgumentException::class);
        GlobalMonthlyAiPolicy::assertUnchanged($free, 'default');
    }

    public function test_new_global_batches_use_auto_cost(): void
    {
        self::assertSame('auto_cost', GlobalMonthlyAiPolicy::AUTO_COST);
        self::assertTrue(GlobalMonthlyAiPolicy::isAutoCost('auto_cost'));

        $handlerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(StartGlobalMonthlyBatchHandler::class))->getFileName(),
        );
        self::assertStringContainsString('GlobalMonthlyAiPolicy::AUTO_COST', $handlerSrc);

        $orchSrc = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('startNextAutoCostItem', $orchSrc);
        self::assertStringContainsString('isAutoCost', $orchSrc);
    }

    public function test_snapshot_builder_class_wires_eligibility_gate(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchSnapshotBuilder::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectProjectGenerationGate', $src);
        self::assertStringContainsString('eligibleTaskIds', $src);
        self::assertStringContainsString('orderBy(\'id\')', $src);
        self::assertStringContainsString('project_bulk_active', $src);
        self::assertStringContainsString('pending_count', $src);
        self::assertStringContainsString('failed_count', $src);
        self::assertStringContainsString('STATUS_FAILED', $src);
    }

    public function test_start_command_name(): void
    {
        $cmd = new StartGlobalMonthlyBatchCommand('2026-10', 'free_only');
        self::assertSame('content_project.start_global_monthly_batch', $cmd->name());
        self::assertSame('2026-10', $cmd->planningMonth);
        self::assertSame('free_only', $cmd->aiCostPolicy);
    }

    public function test_handler_exists(): void
    {
        self::assertTrue(class_exists(StartGlobalMonthlyBatchHandler::class));
    }
}
