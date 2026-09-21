<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit\System;

use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Contracts\WorkflowRuntimePort;
use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowRunRequest;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\AiPrompt\System\LegacySeoTaskWorkflowRuntimePort;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\TestTask;
use ReflectionClass;
use Tests\TestCase;

/**
 * STEP 3B — System Workflow local execution mode parity (full_run / from_node / single_step).
 */
final class SystemWorkflowExecutionModeParityContractTest extends TestCase
{
    public function test_workflow_run_request_supports_three_execution_modes(): void
    {
        self::assertSame('full_run', WorkflowExecutionMode::FullRun->value);
        self::assertSame('from_node', WorkflowExecutionMode::FromNode->value);
        self::assertSame('single_step', WorkflowExecutionMode::SingleStep->value);

        $full = WorkflowRunRequest::fromArray(['definition_id' => 1, 'execution_mode' => 'full_run']);
        $from = WorkflowRunRequest::fromArray([
            'definition_id' => 1,
            'execution_mode' => 'from_node',
            'start_node_id' => 'n_prompt_a',
        ]);
        $single = WorkflowRunRequest::fromArray([
            'definition_id' => 1,
            'execution_mode' => 'single_step',
            'target_node_id' => 'n_prompt_b',
        ]);

        self::assertSame('full_run', $full->executionMode);
        self::assertSame('from_node', $from->executionMode);
        self::assertSame('n_prompt_a', $from->startNodeId);
        self::assertSame('single_step', $single->executionMode);
        self::assertSame('n_prompt_b', $single->targetNodeId);
        self::assertSame(WorkflowExecutionMode::FullRun, WorkflowExecutionMode::tryParse('full_run'));
        self::assertNull(WorkflowExecutionMode::tryParse(null));
        self::assertNull(WorkflowExecutionMode::tryParse('not_a_mode'));
    }

    public function test_adapter_maps_modes_to_canonical_runner_methods(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(LegacySeoTaskWorkflowRuntimePort::class))->getFileName());
        self::assertStringContainsString('WorkflowExecutionMode::FullRun => $this->runner->run', $src);
        self::assertStringContainsString('runFromNodeId', $src);
        self::assertStringContainsString('runSingleStep', $src);
        self::assertStringContainsString('invalid_execution_mode', $src);
        self::assertStringNotContainsString("'execution' => 'deferred'", $src);
    }

    public function test_test_task_full_run_and_rerun_use_system_workflow_client(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(TestTask::class))->getFileName());
        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $src);
        self::assertStringContainsString('WorkflowExecutionMode::SingleStep', $src);
        self::assertStringNotContainsString('use Omnichannel\\Addons\\AiPrompt\\Services\\TaskWorkflowTestRunner', $src);

        preg_match('/public function runTest\([\s\S]*?\n    \}/', $src, $runTestMatch);
        self::assertNotSame([], $runTestMatch);
        self::assertStringContainsString('$workflows->run', $runTestMatch[0]);
        self::assertStringNotContainsString('$runner->run', $runTestMatch[0]);

        preg_match('/public function rerunStep\([\s\S]*?\n    \}/', $src, $rerunMatch);
        self::assertNotSame([], $rerunMatch);
        $rerun = $rerunMatch[0];
        self::assertStringContainsString('SystemWorkflowClient $workflows', $rerun);
        self::assertStringContainsString('WorkflowExecutionMode::SingleStep', $rerun);
        self::assertStringContainsString('$workflows->run', $rerun);
        self::assertStringNotContainsString('TaskWorkflowTestRunner', $rerun);
        self::assertStringNotContainsString('$runner->runSingleStep', $rerun);
    }

    public function test_production_callers_use_system_workflow_client(): void
    {
        $paths = [
            dirname(__DIR__, 4).'/content-projects/src/Services/CreateArticlesFromTaskService.php',
            dirname(__DIR__, 4).'/content/src/Services/ArticleWritingExecutionService.php',
            dirname(__DIR__, 4).'/content-projects/src/Services/SeoProjectWorkflowStepRetryService.php',
            dirname(__DIR__, 4).'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
            dirname(__DIR__, 4).'/content/src/Services/EditorWorkflowExecutionService.php',
        ];
        foreach ($paths as $path) {
            self::assertFileExists($path, $path);
            $src = (string) file_get_contents($path);
            self::assertStringContainsString('SystemWorkflowClient', $src, $path);
            self::assertDoesNotMatchRegularExpression(
                '/\$this->workflowRunner->run(FromNodeId|SingleStep)?\s*\(/',
                $src,
                $path,
            );
        }

        self::assertTrue(interface_exists(SystemWorkflowClient::class));
        self::assertTrue(interface_exists(WorkflowRuntimePort::class));
        self::assertTrue(class_exists(TaskWorkflowTestRunner::class));
    }
}
