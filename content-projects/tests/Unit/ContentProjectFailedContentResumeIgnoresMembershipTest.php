<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ArticleWritingExecutionMode;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectBulkItemDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectBulkItemDecisionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectFailedStepResumeResolver;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRunItemEvidenceIndex;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * P0: failed Content must resume Article — never re-enter Outline because a newer
 * lazy-bulk membership pending row shadows the real failed execution.
 */
final class ContentProjectFailedContentResumeIgnoresMembershipTest extends TestCase
{
    public function test_unvisited_pending_membership_does_not_count_as_latest_execution(): void
    {
        $membership = [
            'id' => 639,
            'task_id' => 3341,
            'run_id' => 220,
            'status' => 'pending',
            'lazy_bulk' => true,
        ];
        $failedContent = [
            'id' => 628,
            'task_id' => 3341,
            'run_id' => 219,
            'status' => 'failed',
            'finished_at' => '2026-09-14T10:00:00+00:00',
            'lazy_bulk' => false,
        ];

        self::assertFalse(
            ContentProjectRunItemEvidenceIndex::countsAsLatestExecution($membership, true, null),
        );
        self::assertTrue(
            ContentProjectRunItemEvidenceIndex::countsAsLatestExecution($failedContent, false, null),
        );

        $partition = ContentProjectRunItemEvidenceIndex::partition(
            [$membership, $failedContent],
            null,
            220,
        );

        self::assertSame(628, (int) ($partition['latest_execution_by_task'][3341]['id'] ?? 0));
        self::assertSame(639, (int) ($partition['current_membership_by_task'][3341]['id'] ?? 0));
        self::assertSame('failed', $partition['latest_execution_by_task'][3341]['status']);
    }

    public function test_resume_resolver_latest_run_item_skips_membership_via_evidence_index(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectFailedStepResumeResolver::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectRunItemEvidenceIndex', $src);
        self::assertStringContainsString('countsAsLatestExecution', $src);
        self::assertStringContainsStringIgnoringCase('lazy-bulk membership', $src);

        $method = new ReflectionMethod(ContentProjectFailedStepResumeResolver::class, 'latestRunItem');
        self::assertTrue($method->isPrivate());
    }

    public function test_content_failure_snapshot_resolves_article_not_outline(): void
    {
        $resolver = new ContentProjectFailedStepResumeResolver(new ArtifactReusePolicy);
        $resolveKey = new ReflectionMethod($resolver, 'resolveFailedStepKey');
        $resolveKey->setAccessible(true);

        $item = new \Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
        $item->forceFill([
            'action' => 'article.create',
            'status' => 'failed',
            'error_message' => 'article.content.generate — forced local test reset: content_artifact_missing',
            'output_snapshot' => [
                'steps' => [[
                    'status' => 'failed',
                    'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                    'hook_key' => 'article.content.generate',
                    'error_code' => 'content_artifact_missing',
                ]],
            ],
        ]);

        self::assertSame(
            ContentProjectRerunFromStep::Article->value,
            $resolveKey->invoke($resolver, $item),
        );
    }

    public function test_bulk_resume_settings_are_article_without_downstream(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectBulkItemDecisionService::class))->getFileName(),
        );

        self::assertStringContainsString("'rerun_from_step' => \$fromStep->value", $src);
        self::assertStringContainsString("'rerun_include_downstream' => (bool) (\$plan['include_downstream'] ?? true)", $src);
        self::assertSame('resume_from_failed_step', ContentProjectBulkItemDecision::OP_RESUME_FROM_FAILED_STEP);

        // Contract: Article resume from FailedStepResumeResolver sets include_downstream=false.
        $planInclude = false;
        $fromStep = ContentProjectRerunFromStep::Article;
        $settings = [
            'rerun' => true,
            'rerun_scope' => 'step',
            'rerun_from_step' => $fromStep->value,
            'rerun_include_downstream' => $planInclude,
        ];

        self::assertSame('article', $settings['rerun_from_step']);
        self::assertFalse($settings['rerun_include_downstream']);
    }

    public function test_article_rerun_uses_content_node_not_outline_structure(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(CreateArticlesFromTaskService::class))->getFileName(),
        );

        $pos = strpos($src, 'function runRerunFromStepForContext');
        self::assertNotFalse($pos);
        $chunk = substr($src, $pos, 3500);

        self::assertStringContainsString('ContentProjectRerunFromStep::Article', $chunk);
        self::assertStringContainsString('ArticleWritingExecutionMode::ContentNode', $chunk);
        self::assertStringContainsString('ArticleWritingSourceType::Outline', $chunk);
        // Outline+downstream path must not be taken for Article from_step.
        $articleBranchEnd = strpos($chunk, 'if ($includeDownstream)');
        self::assertNotFalse($articleBranchEnd);
        $articleBranch = substr($chunk, 0, $articleBranchEnd);
        self::assertStringContainsString('ContentNode', $articleBranch);
        self::assertStringNotContainsString('runOutlineThenArticleForContext', $articleBranch);
        self::assertStringNotContainsString('article.outline.structure.generate', $articleBranch);
        self::assertStringNotContainsString('ArticleOutlineGenerate', $articleBranch);

        self::assertSame('content_node', ArticleWritingExecutionMode::ContentNode->value);
        self::assertSame('article.content.generate', WorkflowExecutionRole::ArticleContentGenerate->value);
    }

    public function test_workflow_run_service_honors_rerun_from_step_before_full_generate(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService::class,
            ))->getFileName(),
        );

        self::assertStringContainsString('runRerunFromStepForContext', $src);
        self::assertStringContainsString("\$runSettings['rerun_from_step']", $src);
        self::assertStringContainsString('ContentProjectRerunFromStep::tryFromMixed', $src);
    }
}
