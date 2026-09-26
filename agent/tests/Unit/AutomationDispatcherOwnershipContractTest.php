<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSchedulerService;
use Omnichannel\Addons\Publishing\Console\AutomationDispatchScheduledCommand;
use Omnichannel\Addons\ContentProjects\Jobs\DispatchContentProjectAutomationPoliciesJob;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAutomationPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract: active automation dispatchers own disjoint persistence.
 * Legacy Agent Workspace dispatcher (seo_agent_automations) is retired.
 */
final class AutomationDispatcherOwnershipContractTest extends TestCase
{
    public function test_active_dispatchers_read_disjoint_tables(): void
    {
        $tables = [
            (new AutomationRule)->getTable(),
            (new ContentProjectAutomationPolicy)->getTable(),
        ];

        self::assertSame(
            ['automation_rules', 'seo_content_project_automation_policies'],
            $tables,
        );
        self::assertCount(2, array_unique($tables));
    }

    public function test_active_command_signatures_are_distinct(): void
    {
        $business = new ReflectionClass(AutomationDispatchScheduledCommand::class);
        $policy = new ReflectionClass(DispatchContentProjectAutomationPoliciesJob::class);

        $businessSig = (string) $business->getDefaultProperties()['signature'];

        self::assertStringStartsWith('automation:dispatch-scheduled', $businessSig);

        $businessHandle = (string) file_get_contents((string) $business->getFileName());
        $policyHandle = (string) file_get_contents((string) $policy->getFileName());

        self::assertStringContainsString(AutomationSchedulerService::class, $businessHandle);
        self::assertStringNotContainsString(ContentProjectAutomationPolicy::class, $businessHandle);

        self::assertStringContainsString(ContentProjectAutomationPolicy::class, $policyHandle);
        self::assertStringNotContainsString(AutomationSchedulerService::class, $policyHandle);
    }

    public function test_business_scheduler_does_not_query_retired_agent_workspace_models(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(AutomationSchedulerService::class))->getFileName(),
        );

        self::assertStringContainsString('AutomationRule::query()', $source);
        self::assertStringNotContainsString('SeoAgentAutomation', $source);
        self::assertStringNotContainsString('ContentProjectAutomationPolicy', $source);
    }

    public function test_legacy_agent_workspace_dispatch_command_is_not_registered_by_compat(): void
    {
        $providerPath = dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'SeoContentAiServiceProvider.php';
        self::assertFileExists($providerPath);
        $provider = (string) file_get_contents($providerPath);

        self::assertStringNotContainsString('DispatchDueAgentAutomationsCommand', $provider);
        self::assertStringNotContainsString('agent-automations-dispatch-due', $provider);
        self::assertStringNotContainsString('agent-metrics-aggregate', $provider);
        self::assertStringNotContainsString('agent-observability-prune', $provider);
    }
}
