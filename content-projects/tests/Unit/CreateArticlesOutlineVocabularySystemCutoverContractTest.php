<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowGraphScope;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\Content\Models\SeoArticle;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STEP 3C.6 — CreateArticles OutlineVocabulary phase enters System Workflow FROM_NODE.
 */
final class CreateArticlesOutlineVocabularySystemCutoverContractTest extends TestCase
{
    public function test_phase1_uses_system_from_node_with_outline_vocabulary_scope(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(CreateArticlesFromTaskService::class))->getFileName());

        self::assertStringContainsString('SystemWorkflowClient', $src);
        self::assertStringContainsString('runOutlineVocabularyViaSystemWorkflow', $src);
        self::assertStringContainsString("'source' => 'content_project_outline_vocabulary'", $src);
        self::assertStringContainsString('WorkflowGraphScope::OutlineVocabulary', $src);
        self::assertStringContainsString('WorkflowExecutionMode::FromNode', $src);
        self::assertStringContainsString('finalizeWorkflowGraphRun', $src);
        self::assertStringContainsString('applyParsedMetaFromSteps', $src);
        self::assertStringContainsString('runArticleWritingForContext', $src);

        preg_match('/protected function runPhase1OutlineVocabularySteps\([\s\S]*?\n    \}/', $src, $phase1);
        self::assertNotSame([], $phase1);
        self::assertStringContainsString('runOutlineVocabularyViaSystemWorkflow', $phase1[0]);
        self::assertStringNotContainsString('workflowRunner->runFromNodeId', $phase1[0]);

        preg_match('/private function runOutlineVocabularyViaSystemWorkflow\([\s\S]*?\n    \}/', $src, $sys);
        self::assertNotSame([], $sys);
        self::assertStringContainsString('WorkflowExecutionMode::FromNode', $sys[0]);
        self::assertStringContainsString('executionScope: WorkflowGraphScope::OutlineVocabulary->value', $sys[0]);
        self::assertStringContainsString("'seed_from_artifact' => false", $sys[0]);
        self::assertStringNotContainsString('workflowRunner', $sys[0]);
        self::assertStringNotContainsString('runFromNodeId', $sys[0]);
        self::assertStringNotContainsString('catch', $sys[0]);
    }

    public function test_no_caller_side_run_from_node_fallback(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(CreateArticlesFromTaskService::class))->getFileName());
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workflowRunner->runFromNodeId\s*\(/',
            $src,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workflowRunner->run\s*\(/',
            $src,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workflowRunner->runSingleStep\s*\(/',
            $src,
        );
        // Helper-only runner dependency remains for applyParsedMetaFromSteps.
        self::assertStringContainsString('applyParsedMetaFromSteps', $src);
        self::assertStringContainsString('TaskWorkflowTestRunner', $src);
    }

    public function test_article_writing_delegation_unchanged(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(CreateArticlesFromTaskService::class))->getFileName());
        self::assertStringContainsString('ArticleWritingExecutionService', $src);
        self::assertStringContainsString('runArticleWritingForContext', $src);
        preg_match('/private function runArticleWritingForContext\([\s\S]*?\n    \}/', $src, $m);
        self::assertNotSame([], $m);
        self::assertStringContainsString('articleWriting', $m[0]);
        self::assertStringNotContainsString('SystemWorkflowClient', $m[0]);
        self::assertStringNotContainsString('runOutlineVocabularyViaSystemWorkflow', $m[0]);

        $writing = (string) file_get_contents((new ReflectionClass(ArticleWritingExecutionService::class))->getFileName());
        self::assertStringContainsString('runContentNodeViaSystemWorkflow', $writing);
        self::assertStringContainsString('runPublishGraphViaSystemWorkflow', $writing);
    }

    public function test_successful_system_result_maps_ordered_steps_and_scope(): void
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
                \PHPUnit\Framework\Assert::assertSame(42, $request->definitionId);
                \PHPUnit\Framework\Assert::assertSame('n_outline', $request->startNodeId);
                \PHPUnit\Framework\Assert::assertSame(
                    WorkflowGraphScope::OutlineVocabulary->value,
                    $request->executionScope,
                );
                \PHPUnit\Framework\Assert::assertSame(
                    'content_project_outline_vocabulary',
                    $request->context['source'] ?? null,
                );
                \PHPUnit\Framework\Assert::assertFalse((bool) ($request->context['seed_from_artifact'] ?? true));
                \PHPUnit\Framework\Assert::assertIsArray($request->context['task_test_context'] ?? null);
                \PHPUnit\Framework\Assert::assertSame(88, $request->context['task_test_context']['article_id'] ?? null);

                return new WorkflowRunResult(
                    id: 'wf_cp_outline_ok',
                    status: 'completed',
                    steps: [
                        'n_outline' => [
                            'node_id' => 'n_outline',
                            'type' => 'prompt',
                            'status' => 'completed',
                            'outline_markdown' => '# Outline',
                            'prompt_result_id' => 901,
                        ],
                        'n_content' => [
                            'node_id' => 'n_content',
                            'type' => 'prompt',
                            'status' => 'skipped',
                            'skip_reason' => 'outline_only_scope',
                        ],
                    ],
                    meta: [
                        'execution_mode' => 'from_node',
                        'execution_scope' => 'outline_vocabulary',
                        'adapter' => 'legacy_seo_task',
                        'ordered_steps' => [
                            [
                                'node_id' => 'n_outline',
                                'type' => 'prompt',
                                'status' => 'completed',
                                'outline_markdown' => '# Outline',
                                'prompt_result_id' => 901,
                            ],
                            [
                                'node_id' => 'n_content',
                                'type' => 'prompt',
                                'status' => 'skipped',
                                'skip_reason' => 'outline_only_scope',
                            ],
                        ],
                    ],
                );
            }

            public function getRun(string $id, array $context = []): ?WorkflowRunResult
            {
                return null;
            }

            public function cancel(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed');
            }

            public function retry(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed');
            }

            public function resume(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed');
            }
        };

        $service = $this->makeService($workflows);
        $method = new ReflectionMethod(CreateArticlesFromTaskService::class, 'runOutlineVocabularyViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 88;
        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 't',
            variables: ['focus_keyword' => 'kw'],
            summary: '',
            siteId: 2,
        );
        $task = new SeoTask;
        $task->id = 42;
        $task->exists = true;

        $steps = $method->invoke($service, $task, $context, 'n_outline');
        self::assertCount(2, $steps);
        self::assertSame('n_outline', $steps[0]['node_id'] ?? null);
        self::assertSame('completed', $steps[0]['status'] ?? null);
        self::assertSame('# Outline', $steps[0]['outline_markdown'] ?? null);
        self::assertSame(901, $steps[0]['prompt_result_id'] ?? null);
        self::assertSame('skipped', $steps[1]['status'] ?? null);
        self::assertSame('outline_only_scope', $steps[1]['skip_reason'] ?? null);
    }

    public function test_failed_system_without_steps_throws_like_legacy(): void
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
                    id: 'wf_cp_outline_fail',
                    status: 'failed',
                    steps: [],
                    meta: ['execution_mode' => 'from_node'],
                    errorCode: 'runner_exception',
                    errorMessage: 'Không tìm thấy bước bắt đầu: missing',
                );
            }

            public function getRun(string $id, array $context = []): ?WorkflowRunResult
            {
                return null;
            }

            public function cancel(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed');
            }

            public function retry(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed');
            }

            public function resume(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed');
            }
        };

        $service = $this->makeService($workflows);
        $method = new ReflectionMethod(CreateArticlesFromTaskService::class, 'runOutlineVocabularyViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 1;
        $context = new TaskTestContext(article: $article, isNewArticle: false, matchedBy: 't', variables: [], summary: '', siteId: 1);
        $task = new SeoTask;
        $task->id = 1;
        $task->exists = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Không tìm thấy bước bắt đầu: missing');
        $method->invoke($service, $task, $context, 'missing');
    }

    public function test_failed_system_with_ordered_steps_preserves_cp_failure_shape(): void
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
                    id: 'wf_cp_outline_step_fail',
                    status: 'failed',
                    steps: [
                        'n_outline' => [
                            'node_id' => 'n_outline',
                            'type' => 'prompt',
                            'status' => 'failed',
                            'message' => 'outline prompt failed',
                        ],
                    ],
                    meta: [
                        'ordered_steps' => [[
                            'node_id' => 'n_outline',
                            'type' => 'prompt',
                            'status' => 'failed',
                            'message' => 'outline prompt failed',
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

            public function cancel(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed');
            }

            public function retry(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed');
            }

            public function resume(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed');
            }
        };

        $service = $this->makeService($workflows);
        $method = new ReflectionMethod(CreateArticlesFromTaskService::class, 'runOutlineVocabularyViaSystemWorkflow');
        $method->setAccessible(true);

        $article = new SeoArticle;
        $article->id = 1;
        $context = new TaskTestContext(article: $article, isNewArticle: false, matchedBy: 't', variables: [], summary: '', siteId: 1);
        $task = new SeoTask;
        $task->id = 1;
        $task->exists = true;

        $steps = $method->invoke($service, $task, $context, 'n_outline');
        self::assertSame('failed', $steps[0]['status'] ?? null);
        self::assertSame('outline prompt failed', $steps[0]['message'] ?? null);
    }

    public function test_final_production_runner_audit_no_unexpected_direct_execution(): void
    {
        $roots = [
            dirname(__DIR__, 2).'/src',
            dirname(__DIR__, 3).'/content/src',
            dirname(__DIR__, 3).'/ai-prompt/src',
        ];
        $allowedHelperFiles = [
            'LegacySeoTaskWorkflowRuntimePort.php',
            'TaskWorkflowTestRunner.php',
        ];
        $unexpected = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $basename = $file->getBasename();
                if (in_array($basename, $allowedHelperFiles, true)) {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                if (preg_match('/\$this->workflowRunner->run(FromNodeId|SingleStep)?\s*\(/', $src)) {
                    $unexpected[] = $file->getPathname();
                }
                if (preg_match('/TaskWorkflowTestRunner::run(FromNodeId|SingleStep)?\s*\(/', $src)) {
                    $unexpected[] = $file->getPathname();
                }
            }
        }

        self::assertSame([], $unexpected, 'UNEXPECTED_REMAINING_PRODUCTION_CALLER: '.implode(', ', $unexpected));
    }

    private function makeService(SystemWorkflowClient $workflows): CreateArticlesFromTaskService
    {
        $ref = new ReflectionClass(CreateArticlesFromTaskService::class);
        /** @var CreateArticlesFromTaskService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('workflows');
        $prop->setAccessible(true);
        $prop->setValue($service, $workflows);

        return $service;
    }
}
