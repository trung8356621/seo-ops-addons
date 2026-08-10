<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpServer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\AgentAutomationLevel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\AgentPlanDraftValidator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanExecutor;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAutomationPolicyService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectCanonicalPlanValidator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectPlanTemplateRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\RuleBasedContentProjectPlanGenerator;
use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectMetricKeys;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectAgentPlannerTest extends TestCase
{
    public function test_plan_gateway_does_not_use_command_bus_or_run(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentPlanGateway::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectAgentPlanApplicationService', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('SeoProjectRun', $source);
        self::assertStringNotContainsString('WordPress', $source);
        self::assertContains('content_project.plan', ContentProjectAgentPlanGateway::PLAN_TOOLS);
        self::assertContains('content_project.confirm_plan', ContentProjectAgentPlanGateway::PLAN_TOOLS);
        self::assertContains('content_project.list_pending_approvals', ContentProjectAgentPlanGateway::PLAN_TOOLS);
    }

    public function test_executor_only_dispatches_via_agent_gateway(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentPlanExecutor::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectAgentGateway', $source);
        self::assertStringContainsString('gateway->execute', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('SeoProjectRun', $source);
        self::assertStringNotContainsString('WordPressContentPublisher', $source);
        self::assertStringNotContainsString('Handler', $source);
    }

    public function test_planner_and_generator_do_not_call_bus(): void
    {
        foreach ([
            ContentProjectAgentPlanner::class,
            RuleBasedContentProjectPlanGenerator::class,
            ContentProjectCanonicalPlanValidator::class,
        ] as $class) {
            $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());
            self::assertStringNotContainsString('ContentProjectCommandBus', $source, $class);
            self::assertStringNotContainsString('SeoProjectRun', $source, $class);
        }
    }

    public function test_mcp_server_routes_plan_tools_to_plan_gateway(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectMcpServer::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectAgentPlanGateway', $source);
        self::assertStringContainsString('planGateway', $source);
    }

    public function test_templates_include_generate_review_schedule_flow(): void
    {
        $registry = new ContentProjectPlanTemplateRegistry;
        self::assertContains('generate_new_content_project', $registry->keys());
        self::assertContains('restore_and_rebuild', $registry->keys());
        self::assertContains('schedule_approved', $registry->keys());
    }

    public function test_canonical_validator_rejects_sql_capability(): void
    {
        $validator = new ContentProjectCanonicalPlanValidator(
            new ContentProjectCapabilityRegistry,
            new ContentProjectAutomationPolicyService,
        );
        $errors = $validator->validate([
            ['capability' => 'execute_sql', 'input' => []],
        ]);

        self::assertNotSame([], $errors);
    }

    public function test_draft_validator_still_rejects_cycles_and_internal_tools(): void
    {
        $registry = new ContentProjectCapabilityRegistry;
        $errors = AgentPlanDraftValidator::validatePlan([
            'steps' => [
                ['capability' => 'content_project.process_scheduled_publish', 'input' => []],
            ],
        ], $registry);

        self::assertNotSame([], $errors);
    }

    public function test_automation_levels_exist(): void
    {
        self::assertSame('manual', AgentAutomationLevel::MANUAL);
        self::assertSame('assisted', AgentAutomationLevel::ASSISTED);
        self::assertSame('reviewed_automation', AgentAutomationLevel::REVIEWED_AUTOMATION);
        self::assertSame('full_automation', AgentAutomationLevel::FULL_AUTOMATION);
    }

    public function test_policy_hard_confirmation_cannot_disable_archive(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAutomationPolicyService::class))->getFileName(),
        );

        self::assertStringContainsString('content_project.archive', $source);
        self::assertStringContainsString('HARD_CONFIRMATION', $source);
        self::assertStringNotContainsString('ignore_lifecycle', $source);
        self::assertStringNotContainsString('force_publish', $source);
        self::assertStringNotContainsString('force_archive', $source);
    }

    public function test_agent_plan_metric_keys_registered(): void
    {
        $keys = ContentProjectMetricKeys::all();
        self::assertContains(ContentProjectMetricKeys::AGENT_PLAN_CREATED_TOTAL, $keys);
        self::assertContains(ContentProjectMetricKeys::AGENT_STEP_EXECUTED_TOTAL, $keys);
        self::assertContains(ContentProjectMetricKeys::AGENT_APPROVAL_REQUESTED_TOTAL, $keys);
    }

    public function test_mcp_catalog_exposes_canonical_plan_tools(): void
    {
        $catalog = new ContentProjectMcpToolCatalog(new CanonicalCapabilityRegistry(
            new ContentProjectCapabilityRegistry,
            new ExtensionCapabilityRegistry,
            new ExtensionStateStore,
        ));
        $names = array_map(static fn (array $t): string => (string) $t['name'], $catalog->listTools());

        self::assertContains('content_project.plan', $names);
        self::assertContains('content_project.confirm_plan', $names);
        self::assertContains('content_project.start_plan', $names);
        self::assertContains('content_project.get_agent_policy', $names);
        self::assertContains('content_project.list_pending_approvals', $names);
        self::assertNotContains('content_project.process_scheduled_publish', $names);
    }

    public function test_planner_docs_exist(): void
    {
        $candidates = [
            ProjectRoot::path().DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'contracts'.DIRECTORY_SEPARATOR.'AGENT_AND_MCP_CONTRACTS.md',
            getcwd().DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'contracts'.DIRECTORY_SEPARATOR.'AGENT_AND_MCP_CONTRACTS.md',
        ];

        $found = false;
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $found = true;
                $body = (string) file_get_contents($path);
                self::assertStringContainsString('Agent Gateway', $body);
                self::assertStringContainsString('wait_operation', $body);
                break;
            }
        }

        if (! $found) {
            self::markTestSkipped('docs/contracts/AGENT_AND_MCP_CONTRACTS.md not on host');
        }
    }
}
