<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Console\DispatchDueAgentAutomationsCommand;
use Omnichannel\Addons\Agent\Jobs\RunAgentAutomationJob;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceApplicationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationDefinitionData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationDefinitionValidator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationDispatcher;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationRunStateMachine;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationConditionEvaluator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationRunner;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationScheduleResolver;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\AutomationSkills;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\BuiltinSkillCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentAutomationTest extends TestCase
{
    public function test_automation_skills_registered(): void
    {
        $keys = array_column(BuiltinSkillCatalog::definitions(), 'key');
        foreach ([
            'automation.list',
            'automation.create',
            'automation.status',
            'automation.run',
            'automation.pause',
            'automation.resume',
            'automation.delete',
            'automation.history',
        ] as $key) {
            self::assertContains($key, $keys);
        }
        $slash = array_column(AutomationSkills::definitions(), 'slash_command');
        self::assertContains('/automations', $slash);
        self::assertContains('/create-automation', $slash);
        self::assertContains('/run-automation', $slash);
    }

    public function test_allowed_automation_types(): void
    {
        self::assertContains('scheduled_report', AgentAutomationDefinitionData::ALLOWED_TYPES);
        self::assertContains('condition_watch', AgentAutomationDefinitionData::ALLOWED_TYPES);
        self::assertContains('planning_workflow', AgentAutomationDefinitionData::ALLOWED_TYPES);
        self::assertContains('guarded_action', AgentAutomationDefinitionData::ALLOWED_TYPES);
    }

    public function test_planning_schema_has_automation_proposal(): void
    {
        self::assertContains(AgentPlanningResponse::TYPE_AUTOMATION_PROPOSAL, AgentPlanningResponse::ALLOWED_TYPES);
    }

    public function test_application_routes_automations_not_command_bus(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceApplicationService::class))->getFileName(),
        );
        self::assertStringContainsString('AgentAutomationOrchestrator', $source);
        self::assertStringContainsString('handleAutomation', $source);
        self::assertStringContainsString('explicit_save', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
    }

    public function test_runner_uses_phase2_and_phase3_only(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentAutomationRunner::class))->getFileName(),
        );
        self::assertStringContainsString('AgentExecutionOrchestrator', $source);
        self::assertStringContainsString('AgentPlanningOrchestrator', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringContainsString('waiting_for_approval', $source);
        self::assertStringContainsString('permission_lost', $source);
    }

    public function test_job_only_calls_runner(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(RunAgentAutomationJob::class))->getFileName(),
        );
        self::assertStringContainsString('AgentAutomationRunner', $source);
        self::assertStringContainsString('$runner->run', $source);
        self::assertStringNotContainsString('CommandBus', $source);
        self::assertStringNotContainsString('AgentGateway', $source);
    }

    public function test_dispatcher_does_not_execute_workflow(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentAutomationDispatcher::class))->getFileName(),
        );
        self::assertStringContainsString('RunAgentAutomationJob::dispatch', $source);
        self::assertStringNotContainsString('AgentExecutionOrchestrator', $source);
        self::assertStringNotContainsString('CommandBus', $source);

        $cmd = (string) file_get_contents(
            (new ReflectionClass(DispatchDueAgentAutomationsCommand::class))->getFileName(),
        );
        self::assertStringContainsString('agent:automations:dispatch-due', $cmd);
        self::assertStringContainsString('AgentAutomationDispatcher', $cmd);
    }

    public function test_orchestrator_requires_explicit_save_and_rejects_ai_approve(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentAutomationOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('explicit_save_required', $source);
        self::assertStringContainsString('ai_cannot_approve', $source);
        $validator = (string) file_get_contents(
            (new ReflectionClass(AgentAutomationDefinitionValidator::class))->getFileName(),
        );
        self::assertStringContainsString('browser_owner_site_override_rejected', $validator);
    }

    public function test_validator_rejects_auto_confirm_and_cron(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentAutomationDefinitionValidator::class))->getFileName(),
        );
        self::assertStringContainsString('auto_confirm_rejected', $source);
        self::assertStringContainsString('auto_execute_safe_writes', $source);
        self::assertStringContainsString('cross_site_scope_rejected', $source);
        self::assertStringContainsString('internal_skill_rejected', $source);
        self::assertStringContainsString('too_many_steps', $source);

        $scheduleSource = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentAutomationScheduleResolver::class))->getFileName(),
        );
        self::assertStringContainsString('raw_cron_not_supported', $scheduleSource);
    }

    public function test_run_state_machine_transitions(): void
    {
        $sm = new AgentAutomationRunStateMachine;
        $sm->assertCanTransition('queued', 'running');
        $sm->assertCanTransition('running', 'waiting_for_approval');
        $sm->assertCanTransition('running', 'succeeded');
        $sm->assertCanTransition('running', 'no_change');
        $this->expectException(\InvalidArgumentException::class);
        $sm->assertCanTransition('succeeded', 'running');
    }

    public function test_migration_phase5_tables_exist_in_file(): void
    {
        $path = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_07_28_240000_phase5_agent_automations.php');
        self::assertFileExists($path);
        $body = (string) file_get_contents($path);
        foreach ([
            'seo_agent_automations',
            'seo_agent_automation_runs',
            'seo_agent_automation_approvals',
            'seo_agent_automation_states',
        ] as $table) {
            self::assertStringContainsString($table, $body);
        }
        self::assertStringContainsString('occurrence_key', $body);
        self::assertStringContainsString('idempotency_key', $body);
        self::assertStringContainsString('token_hash', $body);
    }
}
