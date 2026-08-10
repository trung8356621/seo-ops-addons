<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSchedulerService;
use Omnichannel\Addons\Publishing\Console\AutomationDispatchScheduledCommand;
use Omnichannel\Addons\Agent\Console\DispatchDueAgentAutomationsCommand;
use Omnichannel\Addons\ContentProjects\Jobs\DispatchContentProjectAutomationPoliciesJob;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomation;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAutomationPolicy;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationDispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: three scheduled automation dispatchers own disjoint persistence.
 */
final class AutomationDispatcherOwnershipContractTest extends TestCase
{
    public function test_dispatchers_read_disjoint_tables(): void
    {
        $tables = [
            (new AutomationRule)->getTable(),
            (new SeoAgentAutomation)->getTable(),
            (new ContentProjectAutomationPolicy)->getTable(),
        ];

        self::assertSame(
            ['automation_rules', 'seo_agent_automations', 'seo_content_project_automation_policies'],
            $tables,
        );
        self::assertCount(3, array_unique($tables));
    }

    public function test_command_signatures_are_distinct_and_point_at_owned_services(): void
    {
        $business = new ReflectionClass(AutomationDispatchScheduledCommand::class);
        $agent = new ReflectionClass(DispatchDueAgentAutomationsCommand::class);
        $policy = new ReflectionClass(DispatchContentProjectAutomationPoliciesJob::class);

        $businessSig = (string) $business->getDefaultProperties()['signature'];
        $agentSig = (string) $agent->getDefaultProperties()['signature'];

        self::assertStringStartsWith('automation:dispatch-scheduled', $businessSig);
        self::assertStringStartsWith('agent:automations:dispatch-due', $agentSig);
        self::assertNotSame($businessSig, $agentSig);

        $businessHandle = file_get_contents((string) $business->getFileName());
        $agentHandle = file_get_contents((string) $agent->getFileName());
        $policyHandle = file_get_contents((string) $policy->getFileName());

        self::assertIsString($businessHandle);
        self::assertIsString($agentHandle);
        self::assertIsString($policyHandle);

        self::assertStringContainsString(AutomationSchedulerService::class, $businessHandle);
        self::assertStringNotContainsString(AgentAutomationDispatcher::class, $businessHandle);
        self::assertStringNotContainsString(ContentProjectAutomationPolicy::class, $businessHandle);

        self::assertStringContainsString(AgentAutomationDispatcher::class, $agentHandle);
        self::assertStringNotContainsString(AutomationSchedulerService::class, $agentHandle);
        self::assertStringNotContainsString(ContentProjectAutomationPolicy::class, $agentHandle);

        self::assertStringContainsString(ContentProjectAutomationPolicy::class, $policyHandle);
        self::assertStringNotContainsString(AutomationSchedulerService::class, $policyHandle);
        self::assertStringNotContainsString(AgentAutomationDispatcher::class, $policyHandle);
    }

    public function test_business_scheduler_does_not_query_agent_or_policy_models(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(AutomationSchedulerService::class))->getFileName(),
        );

        self::assertStringContainsString('AutomationRule::query()', $source);
        self::assertStringNotContainsString('SeoAgentAutomation', $source);
        self::assertStringNotContainsString('ContentProjectAutomationPolicy', $source);
    }

    public function test_agent_dispatcher_entry_is_dispatch_due(): void
    {
        $method = new ReflectionMethod(AgentAutomationDispatcher::class, 'dispatchDue');
        self::assertTrue($method->isPublic());
    }
}
