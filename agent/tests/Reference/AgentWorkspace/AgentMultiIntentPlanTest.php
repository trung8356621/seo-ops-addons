<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentPlanOutputBinder;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentPlanStepRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentMultiIntentPlanTest extends TestCase
{
    public function test_plan_step_runner_has_no_run_all(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentPlanStepRunner::class))->getFileName(),
        );

        self::assertStringContainsString('runCurrentStep', $source);
        self::assertStringContainsString('can_run_all', $source);
        self::assertStringContainsString("'can_run_all' => false", $source);
        self::assertStringNotContainsString('function runAll', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
    }

    public function test_step_locked_until_dependency_succeeded_is_encoded(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentPlanStepRunner::class))->getFileName(),
        );

        self::assertStringContainsString("'status' => 'locked'", $source);
        self::assertStringContainsString('agent.plan.step_locked', $source);
        self::assertStringContainsString('Plan dừng vì step thất bại', $source);
    }

    public function test_output_binding_allowlist(): void
    {
        self::assertContains('project_ref', AgentPlanOutputBinder::ALLOWED_KEYS);
        self::assertNotContains('tenant_ref', AgentPlanOutputBinder::ALLOWED_KEYS);
        self::assertNotContains('confirmation_token', AgentPlanOutputBinder::ALLOWED_KEYS);
    }
}
