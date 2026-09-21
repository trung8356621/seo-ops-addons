<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STEP 3C.2 — EditArticle::rewriteOutlineFromWorkflow FULL_RUN enters System Workflow.
 */
final class EditArticleOutlineSystemCutoverContractTest extends TestCase
{
    public function test_rewrite_outline_uses_system_workflow_full_run_not_runner_run(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(EditArticle::class))->getFileName());

        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $src);
        self::assertStringContainsString("'source' => 'edit_article_outline'", $src);
        self::assertStringContainsString('task_test_context', $src);
        self::assertStringContainsString('runOutlineRewriteViaSystemWorkflow', $src);
        self::assertStringContainsString('applyParsedMetaFromSteps', $src);

        preg_match('/public function rewriteOutlineFromWorkflow\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        $method = $m[0];
        self::assertStringContainsString('runOutlineRewriteViaSystemWorkflow', $method);
        self::assertStringNotContainsString('TaskWorkflowTestRunner::class)->run', $method);
        self::assertStringNotContainsString('->run($task', $method);
        self::assertStringContainsString('applyParsedMetaFromSteps', $method);

        preg_match('/private function runOutlineRewriteViaSystemWorkflow\([\s\S]*?\n    \}/', $src, $runSys);
        self::assertNotSame([], $runSys);
        self::assertStringContainsString('SystemWorkflowClient', $runSys[0]);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $runSys[0]);
        self::assertStringNotContainsString('TaskWorkflowTestRunner', $runSys[0]);
        self::assertStringNotContainsString('->run($task', $runSys[0]);
    }

    public function test_helper_only_runner_dependency_remains_for_parsed_meta(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(EditArticle::class))->getFileName());
        self::assertStringContainsString('use Omnichannel\\Addons\\AiPrompt\\Services\\TaskWorkflowTestRunner;', $src);
        self::assertStringContainsString('applyParsedMetaFromSteps', $src);
        self::assertStringNotContainsString('TaskWorkflowTestRunner::class)->run', $src);
        self::assertDoesNotMatchRegularExpression(
            '/app\(TaskWorkflowTestRunner::class\)->run\s*\(/',
            $src,
        );
    }

    public function test_failed_system_result_preserves_old_failure_shape_and_skips_meta_helper(): void
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
                \PHPUnit\Framework\Assert::assertSame('edit_article_outline', $request->context['source'] ?? null);
                \PHPUnit\Framework\Assert::assertIsArray($request->context['task_test_context'] ?? null);
                \PHPUnit\Framework\Assert::assertSame('demo title', $request->input['input'] ?? null);

                return new WorkflowRunResult(
                    id: 'wf_outline_fail',
                    status: 'failed',
                    steps: [
                        'n1' => [
                            'node_id' => 'n1',
                            'type' => 'prompt',
                            'status' => 'failed',
                            'message' => 'outline prompt blew up',
                        ],
                    ],
                    meta: [
                        'execution_mode' => 'full_run',
                        'ordered_steps' => [[
                            'node_id' => 'n1',
                            'type' => 'prompt',
                            'status' => 'failed',
                            'message' => 'outline prompt blew up',
                        ]],
                    ],
                    errorCode: 'step_failures',
                    errorMessage: '1 workflow step(s) failed.',
                );
            }

            public function getRun(string $id, array $context = []): ?WorkflowRunResult
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

        $this->app->instance(SystemWorkflowClient::class, $workflows);

        $page = (new ReflectionClass(EditArticle::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(EditArticle::class, 'runOutlineRewriteViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 99;
        $article->site_id = 1;
        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'edit_article',
            variables: ['input' => 'demo title'],
            summary: 'test',
            siteId: 1,
        );
        $task = new SeoTask;
        $task->id = 1;
        $task->exists = true;

        $out = $method->invoke($page, $task, $context);
        self::assertSame('failed', $out['status']);
        self::assertSame('wf_outline_fail', $out['run_id']);
        self::assertSame('outline prompt blew up', $out['steps'][0]['message'] ?? null);
        self::assertSame('1 workflow step(s) failed.', $out['error_message']);
    }

    public function test_successful_system_result_maps_ordered_steps_and_preserves_context(): void
    {
        $workflows = new class implements SystemWorkflowClient
        {
            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                \PHPUnit\Framework\Assert::assertSame(1, $request->definitionId);
                \PHPUnit\Framework\Assert::assertSame(WorkflowExecutionMode::FullRun->value, $request->executionMode);
                \PHPUnit\Framework\Assert::assertSame('edit_article_outline', $request->context['source'] ?? null);
                $ttc = $request->context['task_test_context'] ?? [];
                \PHPUnit\Framework\Assert::assertIsArray($ttc);
                \PHPUnit\Framework\Assert::assertSame(99, $ttc['article_id'] ?? null);
                \PHPUnit\Framework\Assert::assertSame('demo title', $ttc['variables']['input'] ?? null);
                \PHPUnit\Framework\Assert::assertSame('workflow.edit_article_outline', $request->correlation['capability'] ?? null);

                return new WorkflowRunResult(
                    id: 'wf_outline_ok',
                    status: 'completed',
                    steps: [
                        'n_outline' => [
                            'node_id' => 'n_outline',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'output' => "# Outline\n- A",
                        ],
                    ],
                    meta: [
                        'execution_mode' => 'full_run',
                        'adapter' => 'legacy_seo_task',
                        'ordered_steps' => [[
                            'node_id' => 'n_outline',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'output' => "# Outline\n- A",
                        ]],
                    ],
                );
            }

            public function getRun(string $id, array $context = []): ?WorkflowRunResult
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

        $this->app->instance(SystemWorkflowClient::class, $workflows);

        $page = (new ReflectionClass(EditArticle::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(EditArticle::class, 'runOutlineRewriteViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 99;
        $article->site_id = 1;
        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'edit_article',
            variables: ['input' => 'demo title'],
            summary: 'test',
            siteId: 1,
        );
        $task = new SeoTask;
        $task->id = 1;
        $task->exists = true;

        $out = $method->invoke($page, $task, $context);
        self::assertSame('completed', $out['status']);
        self::assertSame('wf_outline_ok', $out['run_id']);
        self::assertCount(1, $out['steps']);
        self::assertSame("# Outline\n- A", $out['steps'][0]['output'] ?? null);
        self::assertNull($out['error_message']);
    }

    public function test_first_failed_step_message_parity(): void
    {
        $page = (new ReflectionClass(EditArticle::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(EditArticle::class, 'firstFailedStepMessage');
        $method->setAccessible(true);

        $msg = $method->invoke($page, [
            ['status' => 'completed', 'message' => 'ok'],
            ['status' => 'failed', 'message' => '  first fail  '],
            ['status' => 'failed', 'message' => 'second fail'],
        ]);
        self::assertSame('first fail', $msg);

        self::assertNull($method->invoke($page, [
            ['status' => 'blocked', 'message' => 'blocked only'],
            ['status' => 'completed', 'message' => 'ok'],
        ]));
    }

    public function test_other_production_callers_remain_on_system_where_cut_over(): void
    {
        $path = dirname(__DIR__, 3).'/content-projects/src/Services/CreateArticlesFromTaskService.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('content_project_outline_vocabulary', $src);
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workflowRunner->runFromNodeId\s*\(/',
            $src,
        );

        $writing = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleWritingExecutionService::class))->getFileName()
        );
        preg_match('/private function executePublishGraph\([\s\S]*?\n    \}/', $writing, $pub);
        self::assertNotSame([], $pub);
        self::assertStringContainsString('runPublishGraphViaSystemWorkflow', $pub[0]);
        self::assertStringNotContainsString('workflowRunner->run', $pub[0]);
        self::assertStringContainsString("'source' => 'article_writing_publish_graph'", $writing);
    }

    public function test_editor_media_system_cutover_remains(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\EditorWorkflowExecutionService::class))->getFileName()
        );
        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $src);
        self::assertStringContainsString("'source' => 'editor_media'", $src);
        self::assertStringContainsString('runFullGraphViaSystemWorkflow', $src);
        self::assertDoesNotMatchRegularExpression(
            '/private function executeFullGraph\([\s\S]*?workflowRunner->run/',
            $src,
        );
    }
}
