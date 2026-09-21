<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit\System;

use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Contracts\WorkflowRuntimePort;
use App\System\Workflow\Dto\WorkflowRunRequest;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\AiPrompt\System\LegacySeoTaskWorkflowRuntimePort;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\TestTask;
use ReflectionClass;
use Tests\TestCase;

/**
 * STEP 3A — TestTask full-run enters System Workflow → port → TaskWorkflowTestRunner.
 */
final class SystemWorkflowTestTaskVerticalSliceContractTest extends TestCase
{
    public function test_test_task_run_test_uses_system_workflow_client_not_runner(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(TestTask::class))->getFileName());
        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('WorkflowRunRequest', $src);
        self::assertStringContainsString("'source' => 'test_task'", $src);
        self::assertStringContainsString('task_test_context', $src);

        preg_match('/public function runTest\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        $runTest = $m[0];
        self::assertStringContainsString('$workflows->run', $runTest);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $runTest);
        self::assertStringNotContainsString('$runner->run', $runTest);
        self::assertStringNotContainsString('TaskWorkflowTestRunner $runner', $runTest);

        preg_match('/public function rerunStep\([\s\S]*?\n    \}/', $src, $rerun);
        self::assertNotSame([], $rerun);
        self::assertStringContainsString('SystemWorkflowClient $workflows', $rerun[0]);
        self::assertStringContainsString('WorkflowExecutionMode::SingleStep', $rerun[0]);
        self::assertStringNotContainsString('TaskWorkflowTestRunner', $rerun[0]);
    }

    public function test_legacy_workflow_port_delegates_to_canonical_runner(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(LegacySeoTaskWorkflowRuntimePort::class))->getFileName());
        self::assertStringContainsString('TaskWorkflowTestRunner', $src);
        self::assertStringContainsString('$this->runner->run', $src);
        self::assertStringContainsString('runFromNodeId', $src);
        self::assertStringContainsString('runSingleStep', $src);
        self::assertStringNotContainsString("'execution' => 'deferred'", $src);
        self::assertStringContainsString('status: $failed > 0 ? \'failed\' : \'completed\'', $src);
    }

    public function test_provider_registers_workflow_runtime_port_binding(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/AiPromptServiceProvider.php'
        );
        self::assertStringContainsString('WorkflowRuntimePort::class', $provider);
        self::assertStringContainsString('LegacySeoTaskWorkflowRuntimePort::class', $provider);
        self::assertTrue(interface_exists(SystemWorkflowClient::class));
        self::assertTrue(interface_exists(WorkflowRuntimePort::class));
    }

    public function test_production_callers_use_system_workflow_client(): void
    {
        $paths = [
            dirname(__DIR__, 4).'/content-projects/src/Services/CreateArticlesFromTaskService.php',
            dirname(__DIR__, 4).'/content/src/Services/ArticleWritingExecutionService.php',
            dirname(__DIR__, 4).'/content/src/Services/EditorWorkflowExecutionService.php',
        ];
        foreach ($paths as $path) {
            self::assertFileExists($path);
            $src = (string) file_get_contents($path);
            self::assertStringContainsString('SystemWorkflowClient', $src, $path);
            self::assertDoesNotMatchRegularExpression(
                '/\$this->workflowRunner->run(FromNodeId|SingleStep)?\s*\(/',
                $src,
                $path,
            );
        }
    }

    public function test_workflow_run_request_carries_test_context_contract(): void
    {
        $req = new WorkflowRunRequest(
            definitionId: 999,
            context: [
                'source' => 'test_task',
                'task_test_context' => ['summary' => 'x', 'variables' => ['input' => 'hello']],
            ],
        );
        self::assertSame(999, $req->definitionId);
        self::assertSame('test_task', $req->context['source']);
        self::assertSame('hello', $req->context['task_test_context']['variables']['input']);
    }
}
