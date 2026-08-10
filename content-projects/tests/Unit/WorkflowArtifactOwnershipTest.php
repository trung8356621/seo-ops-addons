<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use Omnichannel\Addons\ContentProjects\Support\Workflow\WorkflowTypedArtifact;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class WorkflowArtifactOwnershipTest extends TestCase
{
    public function test_outline_marker_never_valid_article_content_payload(): void
    {
        $policy = new ArtifactReusePolicy;
        $outline = "[START_TASK_1_OUTLINE]\n## Heading\n[END_TASK_1_OUTLINE]";

        self::assertTrue($policy->looksLikeOutlineMarkerPayload($outline));
        self::assertFalse($policy->isValidArticleContentPayload($outline));
        self::assertTrue($policy->isValidArticleContentPayload("# Title\n\nBody paragraph with enough text."));
    }

    public function test_should_publish_markdown_as_article_fail_closed(): void
    {
        $runner = (new ReflectionClass(TaskWorkflowTestRunner::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($runner);
        $prop = $ref->getProperty('artifactReusePolicy');
        $prop->setAccessible(true);
        $prop->setValue($runner, new ArtifactReusePolicy);

        $method = new ReflectionMethod(TaskWorkflowTestRunner::class, 'shouldPublishMarkdownAsArticle');
        $method->setAccessible(true);

        $outline = "[START_TASK_1_OUTLINE]\n## A\n### B\n".str_repeat('x', 400)."\n[END_TASK_1_OUTLINE]";
        self::assertFalse($method->invoke($runner, $outline));
        self::assertFalse($method->invoke($runner, "# Title\n\n".str_repeat('paragraph ', 40)));
    }

    public function test_outline_payload_rejected_even_when_parked_as_article_markdown(): void
    {
        $policy = new ArtifactReusePolicy;
        $outline = "[START_TASK_1_OUTLINE]\n## Heading one\n[END_TASK_1_OUTLINE]";

        // Legacy bug parked outline into direct_publish_article_markdown — must not pass content gate.
        self::assertFalse($policy->isValidArticleContentPayload($outline));

        $state = new WorkflowExecutionState;
        $state->lastPromptOutput = $outline;
        $state->meta['direct_publish_article_markdown'] = $outline;
        $state->meta['typed_artifacts'] = [
            WorkflowArtifactType::ArticleOutline->value => (new WorkflowTypedArtifact(
                artifactType: WorkflowArtifactType::ArticleOutline,
                payload: $outline,
                projectTaskId: 427,
            ))->toArray(),
        ];

        $bag = $state->meta['typed_artifacts'];
        $content = WorkflowTypedArtifact::tryFromArray($bag[WorkflowArtifactType::ArticleContent->value] ?? []);
        self::assertNull($content);
        self::assertNotNull(WorkflowTypedArtifact::tryFromArray($bag[WorkflowArtifactType::ArticleOutline->value]));
    }

    public function test_action_node_consumes_typed_article_content_only(): void
    {
        $policy = new ArtifactReusePolicy;
        $content = "# Real Article\n\n**Meta Description:** hello world\n\nBody text here.";
        self::assertTrue($policy->isValidArticleContentPayload($content));

        $artifact = new WorkflowTypedArtifact(
            artifactType: WorkflowArtifactType::ArticleContent,
            payload: $content,
            projectTaskId: 10,
            workflowNodeId: 'content-node',
            producerHookKey: 'article.content.generate',
        );
        self::assertTrue($artifact->isReusable());

        $outlineArtifact = new WorkflowTypedArtifact(
            artifactType: WorkflowArtifactType::ArticleOutline,
            payload: "[START_TASK_1_OUTLINE]\nx\n[END_TASK_1_OUTLINE]",
            projectTaskId: 10,
        );
        self::assertFalse(
            $policy->canReuse($outlineArtifact, WorkflowArtifactType::ArticleContent, 10),
        );
        self::assertTrue(
            $policy->canReuse($artifact, WorkflowArtifactType::ArticleContent, 10),
        );
        self::assertFalse(
            $policy->canReuse($artifact, WorkflowArtifactType::ArticleContent, 99),
        );
    }

    public function test_schedule_change_does_not_invalidate_outline_fingerprint(): void
    {
        $policy = new ArtifactReusePolicy;
        $before = ['keyword' => 'a', 'post_title' => 'T', 'scheduled_publish_at' => '2026-01-01'];
        $after = ['keyword' => 'a', 'post_title' => 'T', 'scheduled_publish_at' => '2026-02-01'];
        self::assertFalse($policy->outlineInvalidatedByInputChange($before, $after));

        $after['keyword'] = 'b';
        self::assertTrue($policy->outlineInvalidatedByInputChange($before, $after));
    }

    public function test_runner_source_removes_last_prompt_output_body_fallback(): void
    {
        $src = $this->source(TaskWorkflowTestRunner::class);
        self::assertStringNotContainsString(
            '$fallbackMarkdown = trim((string) ($state->lastPromptOutput ?? \'\'));',
            $src,
        );
        self::assertStringContainsString('article_content artifact hợp lệ', $src);
        self::assertStringContainsString('shouldBlockAfterContentFailure', $src);
        self::assertStringContainsString('registerArticleContentFromPromptOutput', $src);
        self::assertStringContainsString('SKIPPED_NOT_APPLICABLE', $src);
    }

    public function test_finalize_treats_blocked_as_failure(): void
    {
        $src = $this->source(ArticleWritingExecutionService::class);
        self::assertStringContainsString("['failed', 'blocked']", $src);
    }

    public function test_step_rerun_article_does_not_call_outline_then_article(): void
    {
        $src = $this->source(CreateArticlesFromTaskService::class);
        self::assertStringContainsString('runRerunFromStepForContext', $src);
        self::assertStringContainsString('ArticleWritingExecutionMode::ContentNode', $src);
        self::assertStringContainsString('ContentProjectRerunFromStep::Article', $src);
        self::assertStringContainsString('withForcedAiRegenerate', $src);
        self::assertStringContainsString('force_ai_regenerate', $src);
    }

    public function test_runner_disables_reuse_on_forced_ai_regenerate(): void
    {
        $src = $this->source(\Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner::class);
        self::assertStringContainsString('force_ai_regenerate', $src);
        self::assertStringContainsString("rerun_from_step", $src);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($path);

        return (string) file_get_contents($path);
    }
}
