<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Enums\ArticleWritingPromptOwnerType;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionContext;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionSnapshotBuilder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOutlineReviewCheckpoint;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OutlineReviewCheckpointBehavioralTest extends TestCase
{
    public const OUTLINE_ARTIFACT = "## H2 Review Checkpoint Outline\n\n### H3 Detail\n\nBody hint for length.";

    public function test_paused_result_is_not_treated_as_failure_flag(): void
    {
        $result = ContentProjectOutlineReviewCheckpoint::pausedResult(99, [['status' => 'completed']], 'abc');

        $this->assertFalse($result['success']);
        $this->assertTrue(ContentProjectOutlineReviewCheckpoint::isPausedResult($result));
        $this->assertSame(ContentProjectOutlineReviewCheckpoint::PAUSE_REASON, $result['pause_reason']);
        $this->assertSame(99, $result['article_id']);
    }

    public function test_checkpoint_off_dispatches_content_after_outline(): void
    {
        $writingCalls = 0;
        $service = $this->makeService($writingCalls, expectWriting: true);

        $article = new SeoArticle;
        $article->forceFill(['id' => 9001, 'site_id' => 7, 'title' => 'T', 'body' => '']);
        $article->syncOriginal();

        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'test',
            variables: [
                'focus_keyword' => 'balo',
                'post_title' => 'Balo test',
                'project_task_id' => '0',
            ],
            summary: 'test',
            siteId: 7,
            projectTaskType: SeoProjectTask::TYPE_REWRITE,
        );

        $result = $service->runOutlineThenArticleForContext($context, 7);

        if (! ($result['success'] ?? false)) {
            self::fail('Expected success, got: '.json_encode([
                'success' => $result['success'] ?? null,
                'message' => $result['message'] ?? null,
                'error_code' => $result['error_code'] ?? null,
                'awaiting_review' => $result['awaiting_review'] ?? null,
                'writing_calls' => $writingCalls,
            ], JSON_UNESCAPED_UNICODE));
        }

        $this->assertTrue($result['success']);
        $this->assertSame(1, $writingCalls);
        $this->assertFalse(ContentProjectOutlineReviewCheckpoint::isPausedResult($result));
    }

    public function test_checkpoint_on_persists_outline_and_does_not_dispatch_content(): void
    {
        $writingCalls = 0;
        $persistCalls = 0;
        $service = $this->makeService($writingCalls, expectWriting: false, persistCalls: $persistCalls);

        $article = new SeoArticle;
        $article->forceFill(['id' => 9002, 'site_id' => 7, 'title' => 'T', 'body' => '']);
        $article->syncOriginal();

        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'test',
            variables: [
                'focus_keyword' => 'balo',
                'post_title' => 'Balo test',
                'review_checkpoint_enabled' => '1',
            ],
            summary: 'test',
            siteId: 7,
            projectTaskType: SeoProjectTask::TYPE_REWRITE,
        );

        $result = $service->runOutlineThenArticleForContext($context, 7);

        if (! ContentProjectOutlineReviewCheckpoint::isPausedResult($result)) {
            self::fail('Expected paused result, got: '.json_encode([
                'success' => $result['success'] ?? null,
                'message' => $result['message'] ?? null,
                'error_code' => $result['error_code'] ?? null,
                'awaiting_review' => $result['awaiting_review'] ?? null,
            ], JSON_UNESCAPED_UNICODE));
        }

        $this->assertTrue(ContentProjectOutlineReviewCheckpoint::isPausedResult($result));
        $this->assertFalse($result['success']);
        $this->assertSame(0, $writingCalls);
        $this->assertSame(1, $persistCalls);
        $this->assertSame(9002, $result['article_id']);
    }

    public function test_resume_article_path_contract_uses_content_node_and_clears_pause(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/CreateArticlesFromTaskService.php',
        );

        $articlePos = strpos($src, 'ContentProjectRerunFromStep::Article');
        self::assertNotFalse($articlePos);
        $articleBranch = substr($src, $articlePos, 2800);
        self::assertStringContainsString('ContentProjectOutlineReviewCheckpoint::clearPause', $articleBranch);
        self::assertStringContainsString('ArticleWritingExecutionMode::ContentNode', $articleBranch);
        self::assertStringContainsString('runArticleWritingForContext', $articleBranch);
        // Must drop stale outline seeds so Content uses latest persisted Outline.
        self::assertStringContainsString("\$resumeVars['article_writing_raw_input']", $articleBranch);
        self::assertStringContainsString("\$resumeVars['direct_publish_outline_markdown']", $articleBranch);
        self::assertStringContainsString('unset(', $articleBranch);
    }

    public function test_create_first_run_always_uses_two_phase_seam_not_publish_graph(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/CreateArticlesFromTaskService.php',
        );
        $methodPos = strpos($src, 'public function runPublishWorkflowForContext');
        self::assertNotFalse($methodPos);
        $method = substr($src, $methodPos, 5500);

        self::assertStringContainsString('TYPE_CREATE', $method);
        self::assertStringContainsString('runOutlineThenArticleForContext', $method);
        // CREATE must hit two-phase before any PublishGraph fallback.
        $createPos = strpos($method, 'TYPE_CREATE');
        $twoPhasePos = strpos($method, 'runOutlineThenArticleForContext');
        $publishGraphPos = strpos($method, 'ArticleWritingExecutionMode::PublishGraph');
        self::assertNotFalse($createPos);
        self::assertNotFalse($twoPhasePos);
        self::assertTrue($createPos < $twoPhasePos);
        if ($publishGraphPos !== false) {
            self::assertTrue($twoPhasePos < $publishGraphPos);
        }
    }

    public function test_checkpoint_seam_is_after_outline_persist_before_writing(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/CreateArticlesFromTaskService.php',
        );
        $methodPos = strpos($src, 'public function runOutlineThenArticleForContext');
        self::assertNotFalse($methodPos);
        $method = substr($src, $methodPos, 8000);
        $persistPos = strpos($method, 'articleOutlinePersist->persist');
        $checkpointPos = strpos($method, 'ContentProjectOutlineReviewCheckpoint::isEnabledForContext');
        $writingPos = strpos($method, '// ── PHASE 2: Explicit Writing');
        self::assertNotFalse($persistPos);
        self::assertNotFalse($checkpointPos);
        self::assertNotFalse($writingPos);
        self::assertTrue($persistPos < $checkpointPos);
        self::assertTrue($checkpointPos < $writingPos);
    }

    /**
     * @param-out int $writingCalls
     * @param-out int $persistCalls
     */
    private function makeService(int &$writingCalls, bool $expectWriting, ?int &$persistCalls = null): CreateArticlesFromTaskService
    {
        $persistBucket = 0;
        if ($persistCalls === null) {
            $persistCalls = &$persistBucket;
        }

        $task = $this->publishTask();
        $roleResolver = $this->roleResolver();

        $outlinePersist = $this->createMock(ArticleOutlineResolver::class);
        $outlinePersist->expects(self::once())
            ->method('persist')
            ->willReturnCallback(function () use (&$persistCalls): array {
                $persistCalls++;

                return ['ok' => true, 'message' => 'ok'];
            });
        $outlinePersist->method('resolveMarkdown')->willReturn(self::OUTLINE_ARTIFACT);

        $articleWriting = $this->createMock(ArticleWritingExecutionService::class);
        if ($expectWriting) {
            $articleWriting->expects(self::once())
                ->method('execute')
                ->willReturnCallback(function (
                    ArticleWritingInput $input,
                ) use (&$writingCalls): ArticleWritingExecutionResult {
                    $writingCalls++;
                    self::assertSame(self::OUTLINE_ARTIFACT, $input->input);

                    return new ArticleWritingExecutionResult(
                        success: true,
                        message: 'Writing ok',
                        sourceType: ArticleWritingSourceType::Outline,
                        promptOwnerType: ArticleWritingPromptOwnerType::WorkflowNode,
                        hookKey: ArticleWritingExecutionService::HOOK_KEY,
                        articleId: 9001,
                        persistStatus: ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
                        steps: [[
                            'node_id' => 'content_b',
                            'status' => 'completed',
                            'hook_key' => ArticleWritingExecutionService::HOOK_KEY,
                            'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                            'artifact_type' => 'article_content',
                            'result_id' => 4242,
                        ]],
                    );
                });
        } else {
            $articleWriting->expects(self::never())->method('execute');
        }

        $phase1 = 0;

        return $this->buildService($roleResolver, $outlinePersist, $articleWriting, $task, $phase1);
    }

    private function buildService(
        WorkflowExecutionRoleResolver $roleResolver,
        ArticleOutlineResolver $outlinePersist,
        ArticleWritingExecutionService $articleWriting,
        SeoTask $task,
        int &$phase1Count,
    ): CreateArticlesFromTaskService {
        $outlineResolver = $this->createMock(ArticleGenerationInputResolver::class);
        $outlineResolver->method('isValidArtifact')->willReturnCallback(
            static fn (string $raw): bool => strlen(trim($raw)) > 20,
        );
        $outlineResolver->method('tryResolveFromRawArtifact')->willReturn(null);

        $snapshotBuilder = (new ReflectionClass(WorkflowExecutionSnapshotBuilder::class))
            ->newInstanceWithoutConstructor();
        $snapRole = (new ReflectionClass(WorkflowExecutionSnapshotBuilder::class))->getProperty('roleResolver');
        $snapRole->setAccessible(true);
        $snapRole->setValue($snapshotBuilder, $roleResolver);

        return new class (
            $roleResolver,
            $outlinePersist,
            $articleWriting,
            $outlineResolver,
            $snapshotBuilder,
            $task,
            $phase1Count,
        ) extends CreateArticlesFromTaskService {
            public function __construct(
                WorkflowExecutionRoleResolver $roleResolver,
                ArticleOutlineResolver $articleOutlinePersist,
                ArticleWritingExecutionService $articleWriting,
                ArticleGenerationInputResolver $outlineResolver,
                WorkflowExecutionSnapshotBuilder $workflowSnapshotBuilder,
                private readonly SeoTask $forcedTask,
                private int &$phase1CountRef,
            ) {
                $ref = new ReflectionClass(CreateArticlesFromTaskService::class);
                foreach ([
                    'roleResolver' => $roleResolver,
                    'articleOutlinePersist' => $articleOutlinePersist,
                    'articleWriting' => $articleWriting,
                    'outlineResolver' => $outlineResolver,
                    'workflowSnapshotBuilder' => $workflowSnapshotBuilder,
                ] as $prop => $value) {
                    $p = $ref->getProperty($prop);
                    $p->setAccessible(true);
                    $p->setValue($this, $value);
                }
            }

            protected function resolvePublishWorkflowTask(): SeoTask
            {
                return $this->forcedTask;
            }

            protected function assertSiteAccessible(int $siteId): void {}

            protected function runPhase1OutlineVocabularySteps(
                SeoTask $task,
                TaskTestContext $context,
                string $outlineNodeId,
            ): array {
                $this->phase1CountRef++;

                return [
                    [
                        'node_id' => 'outline_a',
                        'type' => 'prompt',
                        'title' => 'Outline',
                        'status' => 'completed',
                        'hook_key' => 'article.outline.structure.generate',
                        'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
                        'outline_markdown' => OutlineReviewCheckpointBehavioralTest::OUTLINE_ARTIFACT,
                        'artifact_type' => 'article_outline',
                    ],
                    [
                        'node_id' => 'content_b',
                        'type' => 'prompt',
                        'status' => 'skipped',
                        'skip_reason' => 'outline_vocabulary_scope',
                        'hook_key' => ArticleWritingExecutionService::HOOK_KEY,
                    ],
                ];
            }
        };
    }

    private function publishTask(): SeoTask
    {
        $task = new SeoTask;
        $task->forceFill([
            'id' => 1,
            'is_active' => true,
            'name' => 'Publish',
            'flow_data' => [
                'nodes' => [
                    [
                        'id' => 'outline_a',
                        'type' => 'prompt',
                        'data' => ['execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value],
                    ],
                    [
                        'id' => 'content_b',
                        'type' => 'prompt',
                        'data' => ['execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value],
                    ],
                ],
                'edges' => [],
            ],
        ]);

        return $task;
    }

    private function roleResolver(): WorkflowExecutionRoleResolver
    {
        $roleResolver = $this->createMock(WorkflowExecutionRoleResolver::class);
        $roleResolver->method('requireNodeId')->willReturnCallback(
            static function (SeoTask $t, WorkflowExecutionRole $role): string {
                return match ($role) {
                    WorkflowExecutionRole::ArticleOutlineGenerate => 'outline_a',
                    WorkflowExecutionRole::ArticleContentGenerate => 'content_b',
                    default => 'unknown',
                };
            },
        );
        $roleResolver->method('findNode')->willReturn([
            'node_id' => 'content_b',
            'prompt_id' => 5,
        ]);

        return $roleResolver;
    }
}
