<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\WorkflowExistingAiOutputService;
use Omnichannel\Addons\AiPrompt\Support\WorkflowPublishContentEvidence;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * False-success guard: Outline-only / skeleton body must never become Đã tạo.
 */
final class WorkflowPublishContentEvidenceTest extends TestCase
{
    public function test_skeleton_body_is_not_reusable_existing_content(): void
    {
        $svc = new WorkflowExistingAiOutputService;
        $skeletons = [
            '',
            '   ',
            '<p></p>',
            '<p><br></p>',
            '<p>&nbsp;</p>',
            '<div class="editor"><p></p></div>',
            '<p>[START_TASK_1_OUTLINE]</p><h2>Intro</h2>',
        ];

        foreach ($skeletons as $body) {
            $this->assertFalse(
                $svc->hasSemanticArticleBody($body),
                'Expected non-reusable: '.substr($body, 0, 40),
            );
            $article = (new SeoArticle)->forceFill(['body' => $body]);
            $reuse = $svc->resolve([
                'data' => ['execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value],
            ], (new SeoPrompt)->forceFill(['name' => 'x']), $article);
            $this->assertNull($reuse, 'Reuse must be null for: '.substr($body, 0, 40));
        }
    }

    public function test_real_body_is_reusable(): void
    {
        $svc = new WorkflowExistingAiOutputService;
        $body = '<p>Đây là nội dung bài viết thật với đủ từ ngữ.</p>';
        $this->assertTrue($svc->hasSemanticArticleBody($body));

        $reuse = $svc->resolve([
            'data' => ['execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value],
        ], (new SeoPrompt)->forceFill(['name' => 'x']), (new SeoArticle)->forceFill(['body' => $body]));

        $this->assertNotNull($reuse);
        $this->assertSame(WorkflowExistingAiOutputService::TYPE_CONTENT, $reuse['type']);
    }

    public function test_outline_completed_content_missing_fails_evidence(): void
    {
        $steps = [
            [
                'status' => 'completed',
                'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
                'hook_key' => 'article.outline.structure.generate',
            ],
        ];
        $article = (new SeoArticle)->forceFill(['body' => '']);
        $result = WorkflowPublishContentEvidence::evaluate($steps, $article, requireContent: true);

        $this->assertFalse($result['ok']);
        $this->assertSame(WorkflowPublishContentEvidence::ERROR_CODE, $result['code']);
    }

    public function test_content_skipped_for_skeleton_reuse_fails_evidence(): void
    {
        $steps = [
            [
                'status' => 'completed',
                'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
            ],
            [
                'status' => 'skipped',
                'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                'hook_key' => 'article.content.generate',
                'message' => 'Bỏ qua AI: bài viết đã có nội dung.',
                'skip_reason' => WorkflowPublishContentEvidence::SKIP_REASON_EXISTING_CONTENT,
                'output' => '<p></p>',
            ],
        ];
        $article = (new SeoArticle)->forceFill(['body' => '<p><br></p>']);
        $result = WorkflowPublishContentEvidence::evaluate($steps, $article, requireContent: true);

        $this->assertFalse($result['ok']);
        $this->assertSame(WorkflowPublishContentEvidence::ERROR_CODE, $result['code']);
    }

    public function test_legitimate_existing_body_reuse_passes(): void
    {
        $body = '<p>Nội dung thật đã có sẵn trong bài viết.</p>';
        $steps = [
            [
                'status' => 'skipped',
                'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                'hook_key' => 'article.content.generate',
                'message' => 'Bỏ qua AI: bài viết đã có nội dung.',
                'skip_reason' => WorkflowPublishContentEvidence::SKIP_REASON_EXISTING_CONTENT,
                'output' => $body,
            ],
        ];
        $article = (new SeoArticle)->forceFill(['body' => $body]);
        $result = WorkflowPublishContentEvidence::evaluate($steps, $article, requireContent: true);

        $this->assertTrue($result['ok']);
    }

    public function test_completed_content_with_semantic_body_passes(): void
    {
        $body = '<p>Bài viết mới tạo với nội dung đầy đủ từ AI.</p>';
        $steps = [
            [
                'status' => 'completed',
                'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
            ],
            [
                'status' => 'completed',
                'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                'hook_key' => 'article.content.generate',
                'artifact_type' => WorkflowArtifactType::ArticleContent->value,
                'output' => $body,
            ],
        ];
        $article = (new SeoArticle)->forceFill(['body' => $body]);
        $result = WorkflowPublishContentEvidence::evaluate($steps, $article, requireContent: true);

        $this->assertTrue($result['ok']);
    }

    public function test_intentional_outline_only_scope_does_not_require_content(): void
    {
        $steps = [
            [
                'status' => 'completed',
                'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
            ],
            [
                'status' => 'skipped',
                'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                'hook_key' => 'article.content.generate',
                'skip_reason' => WorkflowPublishContentEvidence::SKIP_REASON_OUTLINE_ONLY_SCOPE,
                'message' => 'Bỏ qua — phạm vi outline/vocabulary (không viết bài).',
            ],
        ];
        $result = WorkflowPublishContentEvidence::evaluate($steps, null, requireContent: true);

        $this->assertTrue($result['ok'], 'Outline-only scope must remain allowed');
        $this->assertTrue(WorkflowPublishContentEvidence::isIntentionalOutlineOnlyScope($steps));
    }

    public function test_finalize_workflow_steps_requires_content_evidence_contract(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleWritingExecutionService::class))->getFileName(),
        );
        $this->assertStringContainsString('WorkflowPublishContentEvidence::evaluate', $src);
        $this->assertStringContainsString('PublishGraph', $src);
        $this->assertStringContainsString('ContentNode', $src);
        $this->assertStringContainsString('content-evidence-guard', $src);
    }

    public function test_graph_finalize_and_reuse_skip_reason_contracts(): void
    {
        $createSrc = (string) file_get_contents(
            (string) (new ReflectionClass(CreateArticlesFromTaskService::class))->getFileName(),
        );
        $this->assertStringContainsString('requireContent: false', $createSrc);
        $this->assertStringContainsString('WorkflowPublishContentEvidence::evaluate', $createSrc);

        $runnerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(TaskWorkflowTestRunner::class))->getFileName(),
        );
        $this->assertStringContainsString('SKIP_REASON_EXISTING_CONTENT', $runnerSrc);
        $this->assertStringContainsString('existing_outline_reuse', $runnerSrc);
        // Reuse skip must not poison downstream as skipped_upstream.
        $this->assertStringContainsString("statusByNodeId[\$nodeId] = 'completed'", $runnerSrc);
    }

    public function test_resume_include_downstream_forces_ai_and_requires_content(): void
    {
        $createSrc = (string) file_get_contents(
            (string) (new ReflectionClass(CreateArticlesFromTaskService::class))->getFileName(),
        );
        $this->assertStringContainsString('runOutlineThenArticleForContext', $createSrc);
        $this->assertStringContainsString('withForcedAiRegenerate', $createSrc);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$includeDownstream\s*\)\s*\{[\s\S]*?runOutlineThenArticleForContext/u',
            $createSrc,
        );
        // Default FULL path is two-phase: outline checkpoint then explicit writing (not single finalize).
        $this->assertStringContainsString('WorkflowExecutionScope::OutlineVocabulary', $createSrc);
        $this->assertStringContainsString('runArticleWritingForContext', $createSrc);
        $this->assertStringContainsString('full_rerun_writing_not_executed', $createSrc);
        $this->assertStringContainsString('articleOutlinePersist->persist', $createSrc);
    }
}
