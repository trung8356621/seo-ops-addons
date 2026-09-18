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
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Behavioral FULL RERUN via real runOutlineThenArticleForContext().
 * Phase-1 steps injected (runner is final); Phase-2 Writing mocked once.
 */
final class FullRerunTwoPhaseBehavioralTest extends TestCase
{
    public const OUTLINE_ARTIFACT = "## H2 New Outline For Checkpoint\n\n### H3 Detail Section One\n\nBody hint.";

    public function test_run_outline_then_article_uses_strict_phase1_scope_and_explicit_writing(): void
    {
        $writingCalls = 0;
        $persistCalls = 0;

        $article = new SeoArticle;
        $article->forceFill([
            'id' => 8553,
            'site_id' => 7,
            'title' => 'Old body article',
            'body' => '<p>old wordpress body</p>',
        ]);
        $article->syncOriginal();

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
                // Content NOT reachable from Outline.
                'edges' => [
                    ['sourceNode' => 'outline_a', 'targetNode' => 'vocab_save'],
                    ['sourceNode' => 'orphan_x', 'targetNode' => 'content_b'],
                ],
            ],
        ]);

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

        $outlinePersist = $this->createMock(ArticleOutlineResolver::class);
        $outlinePersist->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(SeoArticle::class), self::OUTLINE_ARTIFACT)
            ->willReturnCallback(function () use (&$persistCalls): array {
                $persistCalls++;

                return ['ok' => true, 'message' => 'ok'];
            });
        $outlinePersist->method('resolveMarkdown')->willReturn(self::OUTLINE_ARTIFACT);

        $articleWriting = $this->createMock(ArticleWritingExecutionService::class);
        $articleWriting->expects(self::once())
            ->method('execute')
            ->willReturnCallback(function (
                ArticleWritingInput $input,
                ArticleWritingExecutionContext $ctx,
            ) use (&$writingCalls, $article): ArticleWritingExecutionResult {
                $writingCalls++;
                self::assertSame(ArticleWritingSourceType::Outline, $input->sourceType);
                self::assertSame(self::OUTLINE_ARTIFACT, $input->input);

                return new ArticleWritingExecutionResult(
                    success: true,
                    message: 'Writing ok',
                    sourceType: ArticleWritingSourceType::Outline,
                    promptOwnerType: ArticleWritingPromptOwnerType::WorkflowNode,
                    hookKey: ArticleWritingExecutionService::HOOK_KEY,
                    articleId: (int) $article->id,
                    persistStatus: ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
                    steps: [
                        [
                            'node_id' => 'content_b',
                            'status' => 'completed',
                            'hook_key' => ArticleWritingExecutionService::HOOK_KEY,
                            'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                            'artifact_type' => 'article_content',
                            'result_id' => 4242,
                            'output' => '<p>new body from writing</p>',
                        ],
                    ],
                );
            });

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

        $service = new class (
            $roleResolver,
            $outlinePersist,
            $articleWriting,
            $outlineResolver,
            $snapshotBuilder,
            $task,
        ) extends CreateArticlesFromTaskService {
            public int $phase1Count = 0;

            public function __construct(
                WorkflowExecutionRoleResolver $roleResolver,
                ArticleOutlineResolver $articleOutlinePersist,
                ArticleWritingExecutionService $articleWriting,
                ArticleGenerationInputResolver $outlineResolver,
                WorkflowExecutionSnapshotBuilder $workflowSnapshotBuilder,
                private readonly SeoTask $forcedTask,
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
                $this->phase1Count++;
                \PHPUnit\Framework\Assert::assertSame('outline_a', $outlineNodeId);

                return [
                    [
                        'node_id' => 'outline_a',
                        'type' => 'prompt',
                        'title' => 'Outline',
                        'status' => 'completed',
                        'hook_key' => 'article.outline.structure.generate',
                        'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
                        'outline_markdown' => FullRerunTwoPhaseBehavioralTest::OUTLINE_ARTIFACT,
                        'vocabulary_markdown' => '',
                        'artifact_type' => 'article_outline',
                    ],
                    [
                        'node_id' => 'vocab_save',
                        'type' => 'action',
                        'title' => 'Save vocabulary',
                        'status' => 'completed',
                        'action_type' => 'save_vocabulary_research',
                    ],
                    [
                        'node_id' => 'content_b',
                        'type' => 'prompt',
                        'title' => 'Content',
                        'status' => 'skipped',
                        'skip_reason' => 'outline_vocabulary_scope',
                        'hook_key' => ArticleWritingExecutionService::HOOK_KEY,
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                    ],
                    [
                        'node_id' => 'image_c',
                        'type' => 'prompt',
                        'title' => 'Image',
                        'status' => 'skipped',
                        'skip_reason' => 'outline_vocabulary_scope',
                        'execution_role' => WorkflowExecutionRole::ArticleImageGenerate->value,
                    ],
                    [
                        'node_id' => 'save_d',
                        'type' => 'action',
                        'title' => 'Save',
                        'status' => 'skipped',
                        'skip_reason' => 'outline_vocabulary_scope',
                        'action_type' => 'save_article',
                    ],
                ];
            }
        };

        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: null,
            variables: [
                'focus_keyword' => 'balo',
                'post_title' => 'Balo test',
            ],
            summary: 'full rerun behavioral',
            siteId: 7,
            projectTaskType: SeoProjectTask::TYPE_REWRITE,
        );

        $result = $service->runOutlineThenArticleForContext($context, 7);

        self::assertTrue((bool) ($result['success'] ?? false));
        self::assertSame(1, $service->phase1Count, 'Phase1 once');
        self::assertSame(1, $persistCalls, 'Outline checkpoint = 1');
        self::assertSame(1, $writingCalls, 'Writing provider = 1');
        self::assertSame(
            hash('sha256', trim(self::OUTLINE_ARTIFACT)),
            $result['outline_checkpoint_hash'] ?? null,
        );

        $outlineCompleted = 0;
        $vocabCompleted = 0;
        $phase1ContentSkips = 0;
        $phase1ImageSkips = 0;
        $phase1SaveSkips = 0;
        $phase2ContentCompletes = 0;
        foreach ($result['steps'] ?? [] as $step) {
            if (! is_array($step)) {
                continue;
            }
            $hook = (string) ($step['hook_key'] ?? '');
            $phase = (string) ($step['full_rerun_phase'] ?? '');
            $role = (string) ($step['execution_role'] ?? '');
            $action = (string) ($step['action_type'] ?? '');
            $status = (string) ($step['status'] ?? '');

            if ($phase === 'outline' && $role === WorkflowExecutionRole::ArticleOutlineGenerate->value && $status === 'completed') {
                $outlineCompleted++;
            }
            if ($phase === 'outline' && $action === 'save_vocabulary_research' && $status === 'completed') {
                $vocabCompleted++;
            }
            if ($phase === 'outline' && $hook === ArticleWritingExecutionService::HOOK_KEY) {
                self::assertSame('skipped', $status);
                self::assertSame('outline_vocabulary_scope', $step['skip_reason'] ?? null);
                $phase1ContentSkips++;
            }
            if ($phase === 'outline' && $role === WorkflowExecutionRole::ArticleImageGenerate->value) {
                self::assertSame('skipped', $status);
                $phase1ImageSkips++;
            }
            if ($phase === 'outline' && $action === 'save_article') {
                self::assertSame('skipped', $status);
                $phase1SaveSkips++;
            }
            if ($phase === 'writing' && $hook === ArticleWritingExecutionService::HOOK_KEY) {
                self::assertSame('completed', $status);
                $phase2ContentCompletes++;
            }
        }

        self::assertSame(1, $outlineCompleted, 'Outline = 1');
        self::assertSame(1, $vocabCompleted, 'Vocabulary = 1');
        self::assertSame(1, $phase1ContentSkips, 'Phase1 Content executed = 0');
        self::assertSame(1, $phase1ImageSkips, 'Phase1 Image executed = 0');
        self::assertSame(1, $phase1SaveSkips, 'Phase1 Save body executed = 0');
        self::assertSame(1, $phase2ContentCompletes, 'Phase2 Content = 1');
    }
}
