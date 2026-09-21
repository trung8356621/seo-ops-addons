<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STEP 3C.4 — Article Writing executeContentNode FROM_NODE enters System Workflow.
 */
final class ArticleWritingContentNodeSystemCutoverContractTest extends TestCase
{
    public function test_execute_content_node_uses_system_from_node_not_runner(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ArticleWritingExecutionService::class))->getFileName());

        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('WorkflowExecutionMode::FromNode', $src);
        self::assertStringContainsString("'source' => 'article_writing_content_node'", $src);
        self::assertStringContainsString("'seed_from_artifact' => true", $src);
        self::assertStringContainsString('runContentNodeViaSystemWorkflow', $src);
        self::assertStringContainsString('finalizeWorkflowSteps', $src);

        preg_match('/private function executeContentNode\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        $body = $m[0];
        self::assertStringContainsString('runContentNodeViaSystemWorkflow', $body);
        self::assertStringNotContainsString('workflowRunner->runFromNodeId', $body);
        self::assertStringContainsString('finalizeWorkflowSteps', $body);

        preg_match('/private function runContentNodeViaSystemWorkflow\([\s\S]*?\n    \}/', $src, $sys);
        self::assertNotSame([], $sys);
        self::assertStringContainsString('WorkflowExecutionMode::FromNode', $sys[0]);
        self::assertStringContainsString('startNodeId', $sys[0]);
        self::assertStringContainsString('seed_from_artifact', $sys[0]);
        self::assertStringNotContainsString('workflowRunner', $sys[0]);
        self::assertStringNotContainsString('runFromNodeId', $sys[0]);
    }

    public function test_no_caller_side_run_from_node_fallback(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ArticleWritingExecutionService::class))->getFileName());
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workflowRunner->runFromNodeId\s*\(/',
            $src,
        );
        // PublishGraph remains direct runner FULL_RUN.
        self::assertMatchesRegularExpression(
            '/private function executePublishGraph\([\s\S]*?\$this->workflowRunner->run\s*\(/',
            $src,
        );
    }

    public function test_execute_direct_generate_unchanged_no_system_workflow(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ArticleWritingExecutionService::class))->getFileName());
        preg_match('/private function executeDirectGenerate\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        self::assertStringNotContainsString('SystemWorkflowClient', $m[0]);
        self::assertStringNotContainsString('workflows->run', $m[0]);
        self::assertStringContainsString('hookBinding', $m[0]);
    }

    public function test_successful_from_node_maps_ordered_steps_and_seed_flag(): void
    {
        $workflows = new class implements SystemWorkflowClient
        {
            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                \PHPUnit\Framework\Assert::assertSame(WorkflowExecutionMode::FromNode->value, $request->executionMode);
                \PHPUnit\Framework\Assert::assertSame(1, $request->definitionId);
                \PHPUnit\Framework\Assert::assertSame('node_content', $request->startNodeId);
                \PHPUnit\Framework\Assert::assertSame('article_writing_content_node', $request->context['source'] ?? null);
                \PHPUnit\Framework\Assert::assertTrue((bool) ($request->context['seed_from_artifact'] ?? false));
                \PHPUnit\Framework\Assert::assertIsArray($request->context['task_test_context'] ?? null);
                \PHPUnit\Framework\Assert::assertSame(77, $request->context['task_test_context']['article_id'] ?? null);

                return new WorkflowRunResult(
                    id: 'wf_aw_content_ok',
                    status: 'completed',
                    steps: [
                        'node_content' => [
                            'node_id' => 'node_content',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'output' => '## Generated body',
                            'prompt_result_id' => 501,
                        ],
                    ],
                    meta: [
                        'execution_mode' => 'from_node',
                        'adapter' => 'legacy_seo_task',
                        'ordered_steps' => [[
                            'node_id' => 'node_content',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'output' => '## Generated body',
                            'prompt_result_id' => 501,
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
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'runContentNodeViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 77;
        $article->site_id = 2;
        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'writing',
            variables: ['input' => 'outline text'],
            summary: 'test',
            siteId: 2,
        );
        $task = new SeoTask;
        $task->id = 1;
        $task->exists = true;

        $steps = $method->invoke($service, $task, $context, 'node_content');
        self::assertCount(1, $steps);
        self::assertSame('node_content', $steps[0]['node_id'] ?? null);
        self::assertSame('## Generated body', $steps[0]['output'] ?? null);
        self::assertSame(501, $steps[0]['prompt_result_id'] ?? null);
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
                    id: 'wf_aw_content_fail',
                    status: 'failed',
                    errorCode: 'runner_exception',
                    errorMessage: 'forced from_node exception',
                    meta: ['execution_mode' => 'from_node'],
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
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'runContentNodeViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 1;
        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'w',
            variables: [],
            summary: '',
            siteId: 1,
        );
        $task = new SeoTask;
        $task->id = 1;
        $task->exists = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forced from_node exception');
        $method->invoke($service, $task, $context, 'n1');
    }

    public function test_failed_step_inside_system_result_is_returned_for_finalize(): void
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
                    id: 'wf_aw_step_fail',
                    status: 'failed',
                    steps: [
                        'n1' => [
                            'node_id' => 'n1',
                            'type' => 'prompt',
                            'status' => 'failed',
                            'message' => 'content prompt failed',
                        ],
                    ],
                    meta: [
                        'ordered_steps' => [[
                            'node_id' => 'n1',
                            'type' => 'prompt',
                            'status' => 'failed',
                            'message' => 'content prompt failed',
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
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'runContentNodeViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 1;
        $context = new TaskTestContext(article: $article, isNewArticle: false, matchedBy: 'w', variables: [], summary: '', siteId: 1);
        $task = new SeoTask;
        $task->id = 1;
        $task->exists = true;

        $steps = $method->invoke($service, $task, $context, 'n1');
        self::assertSame('failed', $steps[0]['status'] ?? null);
        self::assertSame('content prompt failed', $steps[0]['message'] ?? null);
    }

    public function test_other_production_callers_and_modes(): void
    {
        $createArticles = (string) file_get_contents(
            dirname(__DIR__, 3).'/content-projects/src/Services/CreateArticlesFromTaskService.php'
        );
        self::assertStringContainsString('TaskWorkflowTestRunner', $createArticles);
        self::assertStringNotContainsString('SystemWorkflowClient', $createArticles);

        $writing = (string) file_get_contents((new ReflectionClass(ArticleWritingExecutionService::class))->getFileName());
        preg_match('/private function executePublishGraph\([\s\S]*?\n    \}/', $writing, $pub);
        self::assertNotSame([], $pub);
        self::assertStringContainsString('workflowRunner->run', $pub[0]);
        self::assertStringNotContainsString('SystemWorkflowClient', $pub[0]);
        self::assertStringNotContainsString('workflows->run', $pub[0]);

        $editor = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\EditorWorkflowExecutionService::class))->getFileName()
        );
        self::assertStringContainsString("'source' => 'editor_media'", $editor);

        $editArticle = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle::class))->getFileName()
        );
        self::assertStringContainsString("'source' => 'edit_article_outline'", $editArticle);

        $stepRetry = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepRetryService::class))->getFileName()
        );
        self::assertStringContainsString("'source' => 'content_project_step_retry'", $stepRetry);
        self::assertStringContainsString('WorkflowExecutionMode::SingleStep', $stepRetry);
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
