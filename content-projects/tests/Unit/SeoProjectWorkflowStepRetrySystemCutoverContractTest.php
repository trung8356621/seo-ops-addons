<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectActiveExecutionResolver;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepCatalogService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepRetryService;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\Content\Models\SeoArticle;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STEP 3C.3 — CP step retry SINGLE_STEP enters System Workflow; domain guards/persistence stay local.
 */
final class SeoProjectWorkflowStepRetrySystemCutoverContractTest extends TestCase
{
    public function test_execute_prepared_step_uses_system_single_step_not_runner(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SeoProjectWorkflowStepRetryService::class))->getFileName());

        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('WorkflowExecutionMode::SingleStep', $src);
        self::assertStringContainsString("'source' => 'content_project_step_retry'", $src);
        self::assertStringContainsString('prior_steps', $src);
        self::assertStringContainsString('runSingleStepViaSystemWorkflow', $src);
        self::assertStringContainsString('applyParsedMetaFromSteps', $src);

        preg_match('/private function executePreparedStep\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        $body = $m[0];
        self::assertStringContainsString('runSingleStepViaSystemWorkflow', $body);
        self::assertStringNotContainsString('workflowRunner->runSingleStep', $body);
        self::assertStringContainsString('wasCancelledByUser', $body);
        self::assertStringContainsString('isExecutionTerminal', $body);
        self::assertStringContainsString('applyParsedMetaFromSteps', $body);

        // Guards must appear before System execution in source order.
        $cancelPos = strpos($body, 'wasCancelledByUser');
        $claimPos = strpos($body, 'Pending->value');
        $systemPos = strpos($body, 'runSingleStepViaSystemWorkflow');
        self::assertNotFalse($cancelPos);
        self::assertNotFalse($claimPos);
        self::assertNotFalse($systemPos);
        self::assertLessThan($systemPos, $cancelPos);
        self::assertLessThan($systemPos, $claimPos);

        preg_match('/private function runSingleStepViaSystemWorkflow\([\s\S]*?\n    \}/', $src, $sys);
        self::assertNotSame([], $sys);
        self::assertStringContainsString('WorkflowExecutionMode::SingleStep', $sys[0]);
        self::assertStringContainsString('targetNodeId', $sys[0]);
        self::assertStringContainsString("'prior_steps' => \$priorSteps", $sys[0]);
        self::assertStringNotContainsString('workflowRunner', $sys[0]);
        self::assertStringNotContainsString('runSingleStep(', $sys[0]);
    }

    public function test_no_caller_side_run_single_step_fallback(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SeoProjectWorkflowStepRetryService::class))->getFileName());
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workflowRunner->runSingleStep\s*\(/',
            $src,
        );
        self::assertStringContainsString('applyParsedMetaFromSteps', $src);
        self::assertStringContainsString('TaskWorkflowTestRunner $workflowRunner', $src);
    }

    public function test_successful_system_result_maps_target_step_and_preserves_prior_steps(): void
    {
        $workflows = new class implements SystemWorkflowClient
        {
            /** @var list<array<string, mixed>>|null */
            public ?array $seenPrior = null;

            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                \PHPUnit\Framework\Assert::assertSame(WorkflowExecutionMode::SingleStep->value, $request->executionMode);
                \PHPUnit\Framework\Assert::assertSame(1, $request->definitionId);
                \PHPUnit\Framework\Assert::assertSame('node_outline', $request->targetNodeId);
                \PHPUnit\Framework\Assert::assertSame('content_project_step_retry', $request->context['source'] ?? null);
                \PHPUnit\Framework\Assert::assertIsArray($request->context['task_test_context'] ?? null);
                \PHPUnit\Framework\Assert::assertSame(42, $request->context['task_test_context']['article_id'] ?? null);
                $this->seenPrior = is_array($request->context['prior_steps'] ?? null)
                    ? $request->context['prior_steps']
                    : null;

                return new WorkflowRunResult(
                    id: 'wf_cp_step_ok',
                    status: 'completed',
                    steps: [
                        'node_outline' => [
                            'node_id' => 'node_outline',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'output' => "# Outline\n- A",
                            'prompt_result_id' => 9001,
                            'message' => 'ok',
                        ],
                    ],
                    meta: [
                        'execution_mode' => 'single_step',
                        'adapter' => 'legacy_seo_task',
                        'ordered_steps' => [[
                            'node_id' => 'node_outline',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'output' => "# Outline\n- A",
                            'prompt_result_id' => 9001,
                            'message' => 'ok',
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
        $method = new ReflectionMethod(SeoProjectWorkflowStepRetryService::class, 'runSingleStepViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 42;
        $article->site_id = 2;
        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'cp_retry',
            variables: ['input' => 'title'],
            summary: 'test',
            siteId: 2,
        );
        $seoTask = new SeoTask;
        $seoTask->id = 1;
        $seoTask->exists = true;
        $run = new SeoProjectRun;
        $run->id = 7;
        $run->exists = true;

        $prior = [
            ['node_id' => 'node_article', 'type' => 'article', 'status' => 'ok', 'output' => ''],
            ['node_id' => 'node_filter', 'type' => 'filter', 'status' => 'completed', 'output' => 'kw'],
        ];

        $step = $method->invoke($service, $seoTask, $context, 'node_outline', $prior, $run, 99, 555);
        self::assertSame('node_outline', $step['node_id'] ?? null);
        self::assertSame('completed', $step['status'] ?? null);
        self::assertSame("# Outline\n- A", $step['output'] ?? null);
        self::assertSame(9001, $step['prompt_result_id'] ?? null);
        self::assertSame($prior, $workflows->seenPrior);
    }

    public function test_failed_system_result_without_step_throws_for_fail_prepared_parity(): void
    {
        $workflows = new class implements SystemWorkflowClient
        {
            public int $calls = 0;

            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                $this->calls++;

                return new WorkflowRunResult(
                    id: 'wf_cp_step_fail',
                    status: 'failed',
                    errorCode: 'runner_exception',
                    errorMessage: 'forced single_step exception',
                    meta: ['execution_mode' => 'single_step'],
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
        $method = new ReflectionMethod(SeoProjectWorkflowStepRetryService::class, 'runSingleStepViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 1;
        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'cp',
            variables: ['input' => 'x'],
            summary: 't',
            siteId: 1,
        );
        $seoTask = new SeoTask;
        $seoTask->id = 4;
        $seoTask->exists = true;
        $run = new SeoProjectRun;
        $run->id = 1;
        $run->exists = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forced single_step exception');
        $method->invoke($service, $seoTask, $context, 'n1', [], $run, 1, 1);
    }

    public function test_failed_step_inside_system_result_preserves_message(): void
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
                    id: 'wf_cp_step_fail_node',
                    status: 'failed',
                    steps: [
                        'n1' => [
                            'node_id' => 'n1',
                            'type' => 'prompt',
                            'status' => 'failed',
                            'message' => 'prompt blew up',
                        ],
                    ],
                    meta: [
                        'ordered_steps' => [[
                            'node_id' => 'n1',
                            'type' => 'prompt',
                            'status' => 'failed',
                            'message' => 'prompt blew up',
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
        $method = new ReflectionMethod(SeoProjectWorkflowStepRetryService::class, 'runSingleStepViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 1;
        $context = new TaskTestContext(article: $article, isNewArticle: false, matchedBy: 'cp', variables: [], summary: '', siteId: 1);
        $seoTask = new SeoTask;
        $seoTask->id = 1;
        $seoTask->exists = true;
        $run = new SeoProjectRun;
        $run->id = 1;
        $run->exists = true;

        $step = $method->invoke($service, $seoTask, $context, 'n1', [], $run, 1, 1);
        self::assertSame('failed', $step['status'] ?? null);
        self::assertSame('prompt blew up', $step['message'] ?? null);
    }

    public function test_cancel_and_terminal_guards_precede_system_and_block_call_contract(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SeoProjectWorkflowStepRetryService::class))->getFileName());
        preg_match('/private function executePreparedStep\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        $body = $m[0];

        // Early cancel return before claim/system.
        self::assertMatchesRegularExpression(
            '/wasCancelledByUser\(\$runItem\).*?return \[.*?status.*?failed/s',
            $body,
        );
        // Claim failure path returns without System.
        self::assertStringContainsString("if (\$claimed === 0)", $body);
        self::assertLessThan(
            (int) strpos($body, 'runSingleStepViaSystemWorkflow'),
            (int) strpos($body, 'if ($claimed === 0)'),
        );
    }

    public function test_other_production_callers_remain_direct_runner(): void
    {
        $path = dirname(__DIR__, 2).'/src/Services/CreateArticlesFromTaskService.php';
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

    public function test_editor_media_and_editarticle_system_cutovers_remain(): void
    {
        $editor = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\EditorWorkflowExecutionService::class))->getFileName()
        );
        self::assertStringContainsString('SystemWorkflowClient', $editor);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $editor);
        self::assertStringContainsString("'source' => 'editor_media'", $editor);

        $editArticle = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle::class))->getFileName()
        );
        self::assertStringContainsString('SystemWorkflowClient', $editArticle);
        self::assertStringContainsString("'source' => 'edit_article_outline'", $editArticle);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $editArticle);
    }

    private function makeService(SystemWorkflowClient $workflows): SeoProjectWorkflowStepRetryService
    {
        return new SeoProjectWorkflowStepRetryService(
            (new ReflectionClass(SeoProjectWorkflowStepCatalogService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(TaskTestInputResolver::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(TaskWorkflowTestRunner::class))->newInstanceWithoutConstructor(),
            $workflows,
            (new ReflectionClass(SeoProjectRunItemService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ArticleOutlineResolver::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ArticleGenerationInputResolver::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ContentProjectActiveExecutionResolver::class))->newInstanceWithoutConstructor(),
        );
    }
}
