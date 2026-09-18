<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

/**
 * Locks the AI-success ≠ Content-Project-success boundary for Rerun from Writing.
 *
 * @covers \Omnichannel\Addons\Content\Services\ArticleWritingExecutionService
 */
final class ArticleWritingPersistHistoryTraceTest extends TestCase
{
    public function test_finalize_workflow_requires_body_persist_gate(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingExecutionService.php',
        );
        self::assertStringContainsString('ensureGeneratedContentPersisted', $src);
        self::assertStringContainsString('writing.trace.persist', $src);
        self::assertStringContainsString('content_body_not_applied', $src);
        self::assertStringContainsString('PERSIST_FAILED', $src);
    }

    public function test_ai_success_detects_body_mismatch_and_extracts_content_output(): void
    {
        $service = $this->serviceWithoutConstructor();
        $latest = new ReflectionMethod(ArticleWritingExecutionService::class, 'latestCompletedContentOutput');
        $latest->setAccessible(true);
        $reflects = new ReflectionMethod(ArticleWritingExecutionService::class, 'bodyReflectsGeneratedMarkdown');
        $reflects->setAccessible(true);

        $generated = "## Generated writing content\n\nThis is distinctive AI body text for persist gate.";
        $output = $latest->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
                'output' => $generated,
            ],
        ]);
        self::assertSame($generated, $output);

        $oldBody = '<p>Old WordPress-like body that must be replaced.</p>';
        self::assertFalse($reflects->invoke($service, $oldBody, $generated));

        $appliedBody = '<p>Generated writing content</p><p>This is distinctive AI body text for persist gate.</p>';
        self::assertTrue($reflects->invoke($service, $appliedBody, $generated));
    }

    public function test_persist_gate_calls_publish_article_on_mismatch(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingExecutionService.php',
        );
        self::assertMatchesRegularExpression(
            '/bodyReflectsGeneratedMarkdown\(\$body, \$generated\).*publishArticle\(\$article, \$generated/s',
            $src,
        );
        self::assertStringContainsString('Writing generation succeeded but body was not applied', $src);
    }

    public function test_stale_guard_constants_and_passes_stale_guard_exist(): void
    {
        self::assertSame('ignored_stale', ArticleWritingExecutionResult::PERSIST_IGNORED_STALE);
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingExecutionService.php',
        );
        self::assertStringContainsString('passesStaleGuard', $src);
        self::assertStringContainsString('PERSIST_IGNORED_STALE', $src);
    }

    public function test_wp_hydrate_skips_when_body_non_empty(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressArticleContentService.php',
        );
        self::assertStringContainsString('function hydrateEditorBodyFromWordPress', $src);
        self::assertStringContainsString("\$existingBody !== ''", $src);
        self::assertStringContainsString("'source' => 'body'", $src);
        self::assertStringContainsString("'fetched' => false", $src);
        self::assertTrue(method_exists(WordPressArticleContentService::class, 'hydrateEditorBodyFromWordPress'));
    }

    public function test_system_ai_handler_merges_correlation_into_context_extras(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/System/ArticleContentGenerateCapabilityHandler.php',
        );
        self::assertStringContainsString("\$context['correlation']", $src);
        self::assertStringContainsString('project_item_id', $src);
        self::assertStringContainsString('content_project_id', $src);
        self::assertStringContainsString('correlation_id', $src);
        self::assertStringContainsString('via_system_ai', $src);
    }

    public function test_prompt_execution_persistence_accepts_project_task_id_alias(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptExecutionPersistence.php',
        );
        self::assertStringContainsString("\$snapshot['project_task_id']", $src);
        self::assertStringContainsString("\$variables['project_task_id']", $src);
        self::assertStringContainsString('project_item_id', $src);
    }

    public function test_explicit_binding_links_prompt_result_to_article_at_write_time(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Runtime/PromptHookExplicitBindingExecutor.php',
        );
        self::assertStringContainsString('linkPromptResultToArticleIfPossible', $src);
        self::assertStringContainsString('prompt_hook_explicit_binding', $src);
        self::assertStringContainsString("'stage' => 'writing'", $src);
        self::assertStringContainsString('correlation', $src);
    }

    public function test_mark_success_clears_stale_error_fields(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectRunItemService.php',
        );
        self::assertStringContainsString("'error_code' => null", $src);
        self::assertStringContainsString("'error_message' => null", $src);
        self::assertStringContainsString('never leave stale failure fields', $src);
    }

    public function test_ops_read_model_hides_error_on_success_status(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectItemOperationsReadModel.php',
        );
        self::assertStringContainsString('Success rows must not surface stale failure text', $src);
        self::assertStringContainsString("['success', 'completed', 'skipped', 'manual']", $src);
    }

    public function test_flush_pending_logs_rejected_persist(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringContainsString('workflow.flush_pending_article_content_failed', $src);
        self::assertStringContainsString('workflow.flush_pending_article_content_rejected', $src);
    }

    private function serviceWithoutConstructor(): ArticleWritingExecutionService
    {
        $ref = new ReflectionClass(ArticleWritingExecutionService::class);

        /** @var ArticleWritingExecutionService $service */
        return $ref->newInstanceWithoutConstructor();
    }
}
