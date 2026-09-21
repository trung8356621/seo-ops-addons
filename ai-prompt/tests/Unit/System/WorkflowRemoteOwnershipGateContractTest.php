<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit\System;

use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowRunRequest;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\AiPrompt\System\LegacySeoTaskWorkflowRuntimePort;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STEP 4A-SEC — Workflow remote ownership fail-closed on domain adapter.
 */
final class WorkflowRemoteOwnershipGateContractTest extends TestCase
{
    public function test_via_http_without_owner_fails_closed(): void
    {
        $port = $this->makePort();
        $task = new SeoTask();
        $task->id = 10;
        $task->user_id = 1;
        $task->exists = true;

        $method = new ReflectionMethod(LegacySeoTaskWorkflowRuntimePort::class, 'assertExecutionOwnership');
        $method->setAccessible(true);

        $req = new WorkflowRunRequest(
            definitionId: 10,
            context: ['via_http_api' => true],
            correlation: [],
        );
        $gate = $method->invoke($port, $req, $task);
        self::assertNotNull($gate);
        self::assertSame('owner_required', $gate['code']);
    }

    public function test_owner_mismatch_fails_closed(): void
    {
        $port = $this->makePort();
        $task = new SeoTask();
        $task->id = 10;
        $task->user_id = 1;
        $task->exists = true;

        $method = new ReflectionMethod(LegacySeoTaskWorkflowRuntimePort::class, 'assertExecutionOwnership');
        $method->setAccessible(true);

        $req = new WorkflowRunRequest(
            definitionId: 10,
            context: ['via_http_api' => true, 'owner_user_id' => 2],
            correlation: ['owner_user_id' => 2],
        );
        $gate = $method->invoke($port, $req, $task);
        self::assertNotNull($gate);
        self::assertSame('owner_mismatch', $gate['code']);
    }

    public function test_matching_owner_passes(): void
    {
        $port = $this->makePort();
        $task = new SeoTask();
        $task->id = 10;
        $task->user_id = 5;
        $task->exists = true;

        $method = new ReflectionMethod(LegacySeoTaskWorkflowRuntimePort::class, 'assertExecutionOwnership');
        $method->setAccessible(true);

        $req = new WorkflowRunRequest(
            definitionId: 10,
            context: ['via_http_api' => true, 'owner_user_id' => 5],
            correlation: ['owner_user_id' => 5],
            executionMode: WorkflowExecutionMode::FullRun->value,
        );
        self::assertNull($method->invoke($port, $req, $task));
    }

    public function test_local_without_owner_preserves_bc(): void
    {
        $port = $this->makePort();
        $task = new SeoTask();
        $task->id = 10;
        $task->user_id = 1;

        $method = new ReflectionMethod(LegacySeoTaskWorkflowRuntimePort::class, 'assertExecutionOwnership');
        $method->setAccessible(true);

        $req = new WorkflowRunRequest(
            definitionId: 10,
            context: ['via_http_api' => false],
        );
        self::assertNull($method->invoke($port, $req, $task));
    }

    public function test_adapter_source_contains_ownership_helpers(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(LegacySeoTaskWorkflowRuntimePort::class))->getFileName());
        self::assertStringContainsString('assertExecutionOwnership', $src);
        self::assertStringContainsString('owner_mismatch', $src);
        self::assertStringContainsString('owner_required', $src);
        self::assertStringContainsString('assertArticleSiteOwnership', $src);
        self::assertStringContainsString("'owner_user_id' => \$ownerUserId", $src);
    }

    private function makePort(): LegacySeoTaskWorkflowRuntimePort
    {
        $runner = (new ReflectionClass(TaskWorkflowTestRunner::class))->newInstanceWithoutConstructor();

        return new LegacySeoTaskWorkflowRuntimePort($runner);
    }
}
