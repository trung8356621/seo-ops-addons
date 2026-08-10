<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectArticleRowStatusResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRowStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use PHPUnit\Framework\TestCase;

final class ContentProjectActiveExecutionLifecycleTest extends TestCase
{
    public function test_failed_is_not_active(): void
    {
        self::assertFalse(ContentProjectExecutionStatus::isActive('failed'));
        self::assertFalse(ContentProjectExecutionStatus::isActive('error'));
        self::assertTrue(ContentProjectExecutionStatus::isTerminal('failed'));
        self::assertTrue(ContentProjectExecutionStatus::isTerminal('error'));
    }

    public function test_ignored_stale_blocked_cancelled_timeout_not_active(): void
    {
        foreach (['ignored_stale', 'blocked', 'cancelled', 'canceled', 'timeout', 'timed_out', 'skipped', 'stopped'] as $status) {
            self::assertFalse(ContentProjectExecutionStatus::isActive($status), $status.' must not be active');
            self::assertTrue(ContentProjectExecutionStatus::isTerminal($status), $status.' must be terminal');
        }
    }

    public function test_pending_and_running_are_active(): void
    {
        self::assertTrue(ContentProjectExecutionStatus::isActive('pending'));
        self::assertTrue(ContentProjectExecutionStatus::isActive('processing'));
        self::assertTrue(ContentProjectExecutionStatus::isActive('queued'));
        self::assertTrue(ContentProjectExecutionStatus::isActive('running'));
        self::assertTrue(ContentProjectExecutionStatus::isActive('dispatching'));
    }

    public function test_normalize_aliases(): void
    {
        self::assertSame('success', ContentProjectExecutionStatus::normalize('completed'));
        self::assertSame('failed', ContentProjectExecutionStatus::normalize('error'));
        self::assertSame('cancelled', ContentProjectExecutionStatus::normalize('canceled'));
        self::assertSame('timeout', ContentProjectExecutionStatus::normalize('timed_out'));
        self::assertSame('pending', ContentProjectExecutionStatus::normalize('queued'));
        self::assertSame('processing', ContentProjectExecutionStatus::normalize('running'));
    }

    public function test_failed_row_shows_error_and_not_running_without_active(): void
    {
        $resolver = new ContentProjectArticleRowStatusResolver;
        $status = $resolver->resolve([
            'status' => 'failed',
            'active_execution' => null,
            'workflow_steps' => [
                ['label' => 'Cháº¡y láº¡i bÃ i viáº¿t', 'busy' => false, 'status' => 'failed'],
            ],
        ]);
        self::assertSame(ContentProjectArticleRowStatus::CODE_FAILED, $status->code);
        self::assertSame('Lá»—i: Cháº¡y láº¡i bÃ i viáº¿t', $status->label);
    }

    public function test_false_busy_on_failed_step_does_not_win_as_active(): void
    {
        $resolver = new ContentProjectArticleRowStatusResolver;
        $status = $resolver->resolve([
            'status' => 'failed',
            'active_execution' => null,
            'workflow_steps' => [
                ['label' => 'Cháº¡y láº¡i bÃ i viáº¿t', 'busy' => true, 'status' => 'failed'],
            ],
        ]);
        self::assertSame(ContentProjectArticleRowStatus::CODE_FAILED, $status->code);
    }

    public function test_canonical_active_meta_wins(): void
    {
        $resolver = new ContentProjectArticleRowStatusResolver;
        $status = $resolver->resolve([
            'status' => 'failed',
            'active_execution' => [
                'status' => 'processing',
                'finished_at' => null,
                'node_id' => 'outline-1',
            ],
            'workflow_steps' => [],
        ]);
        self::assertSame(ContentProjectArticleRowStatus::CODE_RUNNING, $status->code);
    }

    public function test_resolver_sot_wired_into_rerun_eligibility(): void
    {
        $eligibility = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Support/ContentProjectRerunEligibilityGuard.php',
        );
        $handler = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/RerunProjectItemStepHandler.php',
        );
        $retry = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepRetryService.php',
        );
        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php',
        );

        self::assertStringContainsString('hasConflictingActiveExecution', $eligibility);
        self::assertStringContainsString('Active conflicting execution', $eligibility);
        self::assertStringContainsString('eligibility->validateStep', $handler);
        self::assertStringContainsString('ContentProjectActiveExecutionResolver', $retry);
        self::assertStringContainsString('whereNull(\'finished_at\')', $retry);
        self::assertStringContainsString('getProjectWorkspaceUrl', $view);
        self::assertStringNotContainsString('active_execution', $view);
    }

    public function test_finalizer_and_repair_command_exist(): void
    {
        self::assertTrue(class_exists(
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionFinalizer::class
        ));
        self::assertTrue(class_exists(
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectActiveExecutionRepairService::class
        ));
        self::assertTrue(class_exists(
            \Omnichannel\Addons\ContentProjects\Console\RepairContentProjectActiveExecutionsCommand::class
        ));

        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Console/RepairContentProjectActiveExecutionsCommand.php',
        );
        self::assertStringContainsString('seo:content-project:repair-active-executions', $cmd);
        self::assertStringContainsString('--apply', $cmd);
        self::assertStringContainsString('--run=', $cmd);
        self::assertStringContainsString('--article=', $cmd);

        $provider = (string) file_get_contents(
            LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'),
        );
        self::assertStringContainsString('RepairContentProjectActiveExecutionsCommand::class', $provider);

        $repair = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectActiveExecutionRepairService.php',
        );
        self::assertStringContainsString('upstream_blocked_leftover', $repair);
        self::assertStringContainsString('officially_stale', $repair);
        self::assertStringContainsString('terminal_missing_finished_at', $repair);
        self::assertStringContainsString('active_with_finished_at', $repair);
        self::assertStringContainsString('content_project.execution_repaired', $repair);
    }

    public function test_active_statuses_canonical_list(): void
    {
        self::assertSame(['pending', 'processing'], ContentProjectExecutionStatus::activeStatuses());
        self::assertContains('failed', ContentProjectExecutionStatus::terminalStatuses());
        self::assertContains('ignored_stale', ContentProjectExecutionStatus::terminalStatuses());
        self::assertContains('blocked', ContentProjectExecutionStatus::terminalStatuses());
    }

    public function test_phase_b_step_rerun_creates_engine_run_not_append_legacy(): void
    {
        $handler = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/RerunProjectItemStepHandler.php',
        );
        self::assertStringContainsString('prepareRunQueue', $handler);
        self::assertStringContainsString('runEngine->start', $handler);
        self::assertStringContainsString("'rerun' => true", $handler);
    }
}
