<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STEP 3C.5 — Article Writing executePublishGraph FULL_RUN enters System Workflow.
 */
final class ArticleWritingPublishGraphSystemCutoverContractTest extends TestCase
{
    public function test_execute_publish_graph_uses_system_full_run_not_runner(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ArticleWritingExecutionService::class))->getFileName());

        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $src);
        self::assertStringContainsString("'source' => 'article_writing_publish_graph'", $src);
        self::assertStringContainsString('runPublishGraphViaSystemWorkflow', $src);
        self::assertStringContainsString('finalizeWorkflowSteps', $src);

        preg_match('/private function executePublishGraph\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        $body = $m[0];
        self::assertStringContainsString('runPublishGraphViaSystemWorkflow', $body);
        self::assertStringNotContainsString('workflowRunner->run', $body);
        self::assertStringContainsString('finalizeWorkflowSteps', $body);

        preg_match('/private function runPublishGraphViaSystemWorkflow\([\s\S]*?\n    \}/', $src, $sys);
        self::assertNotSame([], $sys);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $sys[0]);
        self::assertStringContainsString("'source' => 'article_writing_publish_graph'", $sys[0]);
        self::assertStringContainsString('task_test_context', $sys[0]);
        self::assertStringNotContainsString('workflowRunner', $sys[0]);
        self::assertDoesNotMatchRegularExpression('/->run\(\$task/', $sys[0]);
    }

    public function test_no_caller_side_runner_run_fallback(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ArticleWritingExecutionService::class))->getFileName());
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workflowRunner->run\s*\(/',
            $src,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workflowRunner->runFromNodeId\s*\(/',
            $src,
        );
        self::assertStringContainsString('finalizeWorkflowSteps', $src);
    }

    public function test_execute_content_node_remains_system_from_node(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ArticleWritingExecutionService::class))->getFileName());
        self::assertStringContainsString("'source' => 'article_writing_content_node'", $src);
        self::assertStringContainsString('WorkflowExecutionMode::FromNode', $src);
        self::assertStringContainsString('runContentNodeViaSystemWorkflow', $src);
    }

    public function test_execute_direct_generate_unchanged(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ArticleWritingExecutionService::class))->getFileName());
        preg_match('/private function executeDirectGenerate\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        self::assertStringNotContainsString('workflows->run', $m[0]);
        self::assertStringContainsString('hookBinding', $m[0]);
    }

    public function test_successful_full_run_maps_ordered_steps_and_preserves_context(): void
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
                \PHPUnit\Framework\Assert::assertSame(1, $request->definitionId);
                \PHPUnit\Framework\Assert::assertSame('article_writing_publish_graph', $request->context['source'] ?? null);
                \PHPUnit\Framework\Assert::assertIsArray($request->context['task_test_context'] ?? null);
                \PHPUnit\Framework\Assert::assertSame(88, $request->context['task_test_context']['article_id'] ?? null);
                \PHPUnit\Framework\Assert::assertSame('outline seed', $request->input['input'] ?? null);

                return new WorkflowRunResult(
                    id: 'wf_aw_publish_ok',
                    status: 'completed',
                    steps: [
                        'n1' => [
                            'node_id' => 'n1',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'output' => '## body',
                            'prompt_result_id' => 701,
                        ],
                    ],
                    meta: [
                        'execution_mode' => 'full_run',
                        'adapter' => 'legacy_seo_task',
                        'ordered_steps' => [[
                            'node_id' => 'n1',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'output' => '## body',
                            'prompt_result_id' => 701,
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

        $service = $this->makeService($workflows);
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'runPublishGraphViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 88;
        $article->site_id = 2;
        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'publish',
            variables: ['input' => 'outline seed'],
            summary: 'test',
            siteId: 2,
        );
        $task = new SeoTask;
        $task->id = 1;
        $task->exists = true;

        $steps = $method->invoke($service, $task, $context);
        self::assertCount(1, $steps);
        self::assertSame('n1', $steps[0]['node_id'] ?? null);
        self::assertSame('## body', $steps[0]['output'] ?? null);
        self::assertSame(701, $steps[0]['prompt_result_id'] ?? null);
    }

    public function test_failed_system_without_steps_throws_for_bubble_parity(): void
    {
        $workflows = new class implements SystemWorkflowClient
        {
            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                return new WorkflowRunResult(
                    id: 'wf_aw_publish_fail',
                    status: 'failed',
                    errorCode: 'runner_exception',
                    errorMessage: 'forced publish full_run exception',
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

        $service = $this->makeService($workflows);
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'runPublishGraphViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 1;
        $context = new TaskTestContext(article: $article, isNewArticle: false, matchedBy: 'p', variables: [], summary: '', siteId: 1);
        $task = new SeoTask;
        $task->id = 1;
        $task->exists = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forced publish full_run exception');
        $method->invoke($service, $task, $context);
    }

    public function test_failed_step_inside_system_result_returned_for_finalize(): void
    {
        $workflows = new class implements SystemWorkflowClient
        {
            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                return new WorkflowRunResult(
                    id: 'wf_aw_publish_step_fail',
                    status: 'failed',
                    steps: [
                        'n1' => [
                            'node_id' => 'n1',
                            'type' => 'prompt',
                            'status' => 'failed',
                            'message' => 'outline prompt failed',
                        ],
                    ],
                    meta: [
                        'ordered_steps' => [[
                            'node_id' => 'n1',
                            'type' => 'prompt',
                            'status' => 'failed',
                            'message' => 'outline prompt failed',
                        ]],
                    ],
                    errorCode: 'step_failures',
                    errorMessage: '1 workflow step(s) failed.',
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

        $service = $this->makeService($workflows);
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'runPublishGraphViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 1;
        $context = new TaskTestContext(article: $article, isNewArticle: false, matchedBy: 'p', variables: [], summary: '', siteId: 1);
        $task = new SeoTask;
        $task->id = 1;
        $task->exists = true;

        $steps = $method->invoke($service, $task, $context);
        self::assertSame('failed', $steps[0]['status'] ?? null);
        self::assertSame('outline prompt failed', $steps[0]['message'] ?? null);
    }

    public function test_create_articles_outline_vocab_uses_system_workflow(): void
    {
        $path = dirname(__DIR__, 3).'/content-projects/src/Services/CreateArticlesFromTaskService.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('content_project_outline_vocabulary', $src);
        self::assertStringContainsString('WorkflowGraphScope::OutlineVocabulary', $src);
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workflowRunner->runFromNodeId\s*\(/',
            $src,
        );
    }

    private function makeService(SystemWorkflowClient $workflows): ArticleWritingExecutionService
    {
        $ref = new ReflectionClass(ArticleWritingExecutionService::class);
        /** @var ArticleWritingExecutionService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('workflows');
        $prop->setAccessible(true);
        $prop->setValue($service, $workflows);

        return $service;
    }
}
