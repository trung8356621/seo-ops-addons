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
        self::assertStringContainsString('resolveContentPersistExpectation', $src);
        self::assertStringContainsString('content_step_missing_output', $src);
        self::assertStringContainsString('no_content_step', $src);
        self::assertStringNotContainsString('bodyReflectsGeneratedMarkdown', $src);
        self::assertStringNotContainsString('mid-body fingerprint', $src);
    }

    public function test_ai_success_extracts_content_output_and_fail_closed_missing(): void
    {
        $service = $this->serviceWithoutConstructor();
        $latest = new ReflectionMethod(ArticleWritingExecutionService::class, 'latestCompletedContentOutput');
        $latest->setAccessible(true);
        $expect = new ReflectionMethod(ArticleWritingExecutionService::class, 'resolveContentPersistExpectation');
        $expect->setAccessible(true);

        $generated = "## Generated writing content\n\nThis is distinctive AI body text for persist gate.";
        $output = $latest->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
                'output' => $generated,
            ],
        ]);
        self::assertSame($generated, $output);

        $missing = $expect->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
                'output' => '',
            ],
        ]);
        self::assertSame('content_step_missing_output', $missing['kind']);

        $none = $expect->invoke($service, [
            ['status' => 'completed', 'hook_key' => 'article.outline.structure.generate', 'output' => 'x'],
        ]);
        self::assertSame('no_content_step', $none['kind']);
    }

    public function test_persist_gate_uses_prepare_article_content_hash(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingExecutionService.php',
        );
        self::assertStringContainsString('prepareArticleContent', $src);
        self::assertStringContainsString('expected_content_hash', $src);
        self::assertStringContainsString('persisted_content_hash', $src);
        self::assertStringContainsString('publishArticle($article, $generated', $src);
        self::assertStringContainsString('Writing generation succeeded but body was not applied', $src);

        $publishSrc = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptTestPublishService.php',
        );
        self::assertStringContainsString('function prepareArticleContent', $publishSrc);
        self::assertStringContainsString('contentConflictGuard->contentHash', $publishSrc);
        self::assertStringContainsString("'expected_content_hash'", $publishSrc);
        self::assertStringContainsString("'persisted_content_hash'", $publishSrc);
    }

    public function test_stale_guard_constants_and_passes_stale_guard_exist(): void
    {
        self::assertSame('ignored_stale', ArticleWritingExecutionResult::PERSIST_IGNORED_STALE);
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingExecutionService.php',
        );
        self::assertStringContainsString('passesStaleGuard', $src);
        self::assertStringContainsString('PERSIST_IGNORED_STALE', $src);
        self::assertStringContainsString('late_publish_conflict', $src);
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

    public function test_sectioned_free_parent_links_immediately_for_split_history(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/SectionedFree/SectionedFreeHookOrchestrator.php',
        );
        self::assertStringContainsString('linkPromptResultImmediately', $src);
        self::assertStringContainsString('PromptResultLinkService', $src);
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

    public function test_ai_history_reset_is_manual_destructive_not_scheduled(): void
    {
        $reset = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/AiHistoryResetService.php',
        );
        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Console/ResetAiHistoryCommand.php',
        );
        self::assertStringContainsString('Destructive dev/test reset', $reset);
        self::assertStringContainsString('PromptResult::query()->delete()', $reset);
        self::assertStringContainsString('ResetAiHistoryCommand', $cmd);
        self::assertStringNotContainsString('Schedule::', $cmd);
    }

    private function serviceWithoutConstructor(): ArticleWritingExecutionService
    {
        $ref = new ReflectionClass(ArticleWritingExecutionService::class);

        /** @var ArticleWritingExecutionService $service */
        return $ref->newInstanceWithoutConstructor();
    }
}
