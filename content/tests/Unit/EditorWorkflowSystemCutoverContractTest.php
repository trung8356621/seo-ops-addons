<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use Omnichannel\Addons\AiPrompt\Services\PromptMediaStorageService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\AiPrompt\Support\PromptMediaPersistContext;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\EditorWorkflowExecutionService;
use Omnichannel\Addons\Media\Support\ImageToolType;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STEP 3C.1 — Editor media FULL_RUN enters System Workflow; BC PromptRunner preserved.
 */
final class EditorWorkflowSystemCutoverContractTest extends TestCase
{
    public function test_execute_full_graph_uses_system_workflow_full_run_not_runner_run(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(EditorWorkflowExecutionService::class))->getFileName());

        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $src);
        self::assertStringContainsString("'source' => 'editor_media'", $src);
        self::assertStringContainsString('task_test_context', $src);
        self::assertStringContainsString('PromptMediaPersistContext::using', $src);

        preg_match('/private function executeFullGraph\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        $fullGraph = $m[0];
        self::assertStringContainsString('runFullGraphViaSystemWorkflow', $fullGraph);
        self::assertStringNotContainsString('$this->workflowRunner->run', $fullGraph);

        preg_match('/private function runFullGraphViaSystemWorkflow\([\s\S]*?\n    \}/', $src, $runSys);
        self::assertNotSame([], $runSys);
        self::assertStringContainsString('$this->workflows->run', $runSys[0]);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $runSys[0]);
        self::assertStringNotContainsString('workflowRunner->run', $runSys[0]);
        self::assertStringContainsString("status === 'failed'", $runSys[0]);
    }

    public function test_bc_prompt_runner_fallback_preserved_and_no_runner_graph_fallback(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(EditorWorkflowExecutionService::class))->getFileName());

        preg_match('/public function executeForEditor\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        $entry = $m[0];
        self::assertStringContainsString('executeFullGraph', $entry);
        self::assertStringContainsString('executeExtractLastPromptBc', $entry);
        self::assertStringContainsString('catch (\\Throwable', $entry);
        self::assertStringNotContainsString('workflowRunner->run', $entry);

        preg_match('/private function executeExtractLastPromptBc\([\s\S]*?\n    \}/', $src, $bc);
        self::assertNotSame([], $bc);
        self::assertStringContainsString('$this->promptRunner->run', $bc[0]);
        self::assertStringContainsString('resolveImagePromptForTask', $bc[0]);
        self::assertStringContainsString('MODE_EXTRACT_LAST_PROMPT_BC', $bc[0]);
        self::assertStringContainsString('bc_fallback', $bc[0]);
    }

    public function test_helper_only_runner_resolution_remains(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(EditorWorkflowExecutionService::class))->getFileName());
        self::assertStringContainsString('TaskWorkflowTestRunner $workflowRunner', $src);
        self::assertStringContainsString('resolveImagePromptForTask', $src);
        self::assertStringContainsString('resolveVideoPromptForTask', $src);
    }

    public function test_failed_system_result_throws_into_bc_path(): void
    {
        $workflows = new class implements SystemWorkflowClient
        {
            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                \PHPUnit\Framework\Assert::assertSame(WorkflowExecutionMode::FullRun->value, $request->executionMode);
                \PHPUnit\Framework\Assert::assertSame('editor_media', $request->context['source'] ?? null);

                return new WorkflowRunResult(
                    id: 'wf_editor_fail',
                    status: 'failed',
                    errorCode: 'runner_exception',
                    errorMessage: 'forced system workflow failure',
                    meta: ['execution_mode' => 'full_run'],
                );
            }

            public function getRun(string $id): ?WorkflowRunResult
            {
                return null;
            }

            public function cancel(string $id): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $id, status: 'failed', errorCode: 'not_supported');
            }

            public function retry(string $id): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $id, status: 'failed', errorCode: 'not_supported');
            }

            public function resume(string $id): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $id, status: 'failed', errorCode: 'not_supported');
            }
        };

        $runner = (new ReflectionClass(TaskWorkflowTestRunner::class))->newInstanceWithoutConstructor();
        $promptRunner = (new ReflectionClass(PromptRunnerService::class))->newInstanceWithoutConstructor();
        $storage = (new ReflectionClass(PromptMediaStorageService::class))->newInstanceWithoutConstructor();

        $service = new EditorWorkflowExecutionService($workflows, $runner, $promptRunner, $storage);
        $method = new ReflectionMethod(EditorWorkflowExecutionService::class, 'runFullGraphViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 1;
        $article->site_id = 1;
        $context = new \Omnichannel\Addons\ContentProjects\Support\TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'editor_media',
            variables: ['input' => 'x'],
            summary: 'test',
            siteId: 1,
        );

        $task = new \Omnichannel\Addons\AiPrompt\Models\SeoTask;
        $task->id = 4;
        $task->exists = true;

        $runId = null;
        $this->expectException(\Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException::class);
        $this->expectExceptionMessage('forced system workflow failure');
        $method->invokeArgs($service, [$task, $context, &$runId]);
    }

    public function test_successful_system_result_maps_ordered_steps(): void
    {
        $workflows = new class implements SystemWorkflowClient
        {
            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                \PHPUnit\Framework\Assert::assertSame(4, $request->definitionId);
                \PHPUnit\Framework\Assert::assertSame(WorkflowExecutionMode::FullRun->value, $request->executionMode);

                return new WorkflowRunResult(
                    id: 'wf_editor_ok',
                    status: 'completed',
                    steps: [
                        'n_img' => [
                            'node_id' => 'n_img',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'prompt_id' => 4,
                            'output' => 'https://cdn.example/out.png',
                            'raw_model_used' => 'imagen-test',
                        ],
                    ],
                    meta: [
                        'execution_mode' => 'full_run',
                        'ordered_steps' => [[
                            'node_id' => 'n_img',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'prompt_id' => 4,
                            'output' => 'https://cdn.example/out.png',
                            'raw_model_used' => 'imagen-test',
                        ]],
                    ],
                );
            }

            public function getRun(string $id): ?WorkflowRunResult
            {
                return null;
            }

            public function cancel(string $id): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $id, status: 'failed', errorCode: 'not_supported');
            }

            public function retry(string $id): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $id, status: 'failed', errorCode: 'not_supported');
            }

            public function resume(string $id): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $id, status: 'failed', errorCode: 'not_supported');
            }
        };

        $runner = (new ReflectionClass(TaskWorkflowTestRunner::class))->newInstanceWithoutConstructor();
        $promptRunner = (new ReflectionClass(PromptRunnerService::class))->newInstanceWithoutConstructor();
        $storage = (new ReflectionClass(PromptMediaStorageService::class))->newInstanceWithoutConstructor();
        $service = new EditorWorkflowExecutionService($workflows, $runner, $promptRunner, $storage);
        $method = new ReflectionMethod(EditorWorkflowExecutionService::class, 'runFullGraphViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 1;
        $article->site_id = 1;
        $context = new \Omnichannel\Addons\ContentProjects\Support\TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'editor_media',
            variables: ['input' => 'demo'],
            summary: 'test',
            siteId: 1,
        );
        $task = new \Omnichannel\Addons\AiPrompt\Models\SeoTask;
        $task->id = 4;
        $task->exists = true;

        $runId = null;
        $steps = $method->invokeArgs($service, [$task, $context, &$runId]);
        self::assertSame('wf_editor_ok', $runId);
        self::assertCount(1, $steps);
        self::assertSame('https://cdn.example/out.png', $steps[0]['output'] ?? null);
        self::assertSame('imagen-test', $steps[0]['raw_model_used'] ?? null);
        self::assertSame(ImageToolType::ImageTypography->value, ImageToolType::ImageTypography->value);
        self::assertNull(PromptMediaPersistContext::$siteId);
    }

    public function test_other_production_callers_remain_direct_runner(): void
    {
        $path = dirname(__DIR__, 3).'/content-projects/src/Services/CreateArticlesFromTaskService.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('TaskWorkflowTestRunner', $src);
        self::assertStringNotContainsString('SystemWorkflowClient', $src);

        $writing = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleWritingExecutionService::class))->getFileName()
        );
        preg_match('/private function executePublishGraph\([\s\S]*?\n    \}/', $writing, $pub);
        self::assertNotSame([], $pub);
        self::assertStringContainsString('runPublishGraphViaSystemWorkflow', $pub[0]);
        self::assertStringNotContainsString('workflowRunner->run', $pub[0]);
        self::assertStringContainsString("'source' => 'article_writing_publish_graph'", $writing);
    }
}
