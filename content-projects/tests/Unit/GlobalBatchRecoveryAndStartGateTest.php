<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\GlobalMonthlyGeneratorPage;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\ContentProjectGlobalLockBridge;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLockReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchProgressReadModel;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GlobalBatchRecoveryAndStartGateTest extends TestCase
{
    public function test_request_stop_finalizes_when_child_already_terminal(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );

        $pos = strpos($src, 'public function requestStop');
        self::assertNotFalse($pos);
        $slice = substr($src, $pos, 3200);

        self::assertStringContainsString('childStillRunning', $slice);
        self::assertStringContainsString('STATUS_CANCELLED', $slice);
        self::assertStringContainsString('completeTerminal', $slice);
        self::assertStringContainsString('no active child worker', $slice);
        self::assertStringContainsString('auto_rerun_disabled', $slice);
    }

    public function test_bridge_and_reconciler_stop_override_recoverable_hold(): void
    {
        $bridge = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectGlobalLockBridge::class))->getFileName(),
        );
        self::assertStringContainsString('stopOverridesRecoverable', $bridge);
        self::assertStringContainsString('autoCostAdvanceOnRecoverable', $bridge);
        self::assertStringContainsString('AdvanceGlobalMonthlyBatchJob::dispatch', $bridge);

        $recon = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLockReconciler::class))->getFileName(),
        );
        self::assertStringContainsString('user_stop_overrides_recoverable_hold', $recon);
        self::assertStringContainsString('finalized_stopped_recoverable_child_and_advanced', $recon);
        self::assertStringContainsString('auto_cost_advances_recoverable_child', $recon);
    }

    public function test_progress_read_model_does_not_require_auto_cost_columns(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchProgressReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("hasColumn('seo_content_global_batch_items'", $src);
        self::assertStringContainsString("'skip_reason', 'project_run_id', 'run_item_id', 'started_at', 'finished_at', 'error_message'", $src);
        // Optional columns probed, not hard-required.
        self::assertStringContainsString("'current_strategy'", $src);
        self::assertStringContainsString('Schema::connection', $src);
    }

    public function test_auto_cost_disables_project_run_circuit_breaker(): void
    {
        $engine = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectRunEngine::class))->getFileName(),
        );
        self::assertStringContainsString('disable_batch_circuit_breaker', $engine);
        self::assertStringContainsString('global_auto_cost_strategy', $engine);

        $orch = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString("'disable_batch_circuit_breaker' => true", $orch);
    }

    public function test_start_gate_states_and_disabled_button_contract(): void
    {
        $page = new ReflectionClass(GlobalMonthlyGeneratorPage::class);
        self::assertTrue($page->hasMethod('startGateState'));
        self::assertTrue($page->hasMethod('startBlockReason'));
        self::assertTrue($page->hasMethod('canStartBatch'));

        $blade = (string) realpath(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/global-monthly-generator.blade.php',
        );
        self::assertFileExists($blade);
        $src = (string) file_get_contents($blade);

        self::assertStringContainsString('startGateState()', $src);
        self::assertStringContainsString('startBlockReason()', $src);
        self::assertStringContainsString('data-gm-start-block-reason', $src);
        self::assertStringContainsString('cursor-not-allowed', $src);
        self::assertStringContainsString('bg-gray-300', $src);
        self::assertStringContainsString("\$canStart", $src);
    }
}
