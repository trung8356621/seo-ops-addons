<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Enums\ArticleWritingExecutionMode;
use Omnichannel\Addons\AiPrompt\Enums\ArticleWritingPromptOwnerType;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Services\ArticleImproveExecutionService;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepCatalogService;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionContext;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0.6 — structural / contract tests (remote-first; không gọi AI).
 */
final class ArticleWritingPhase06Test extends TestCase
{
    public function test_execution_service_contract_exists(): void
    {
        self::assertTrue(class_exists(ArticleWritingExecutionService::class));
        self::assertTrue(method_exists(ArticleWritingExecutionService::class, 'execute'));
        self::assertSame('article.content.generate', ArticleWritingExecutionService::HOOK_KEY);
    }

    public function test_source_providers_exist(): void
    {
        $base = ProjectRoot::addonsPath().'/ai-prompt'.'/Services/ArticleWriting';
        self::assertFileExists($base.'/OutlineArticleWritingSourceProvider.php');
        self::assertFileExists($base.'/ExistingArticleWritingSourceProvider.php');
        self::assertFileExists($base.'/BriefArticleWritingSourceProvider.php');
    }

    public function test_improve_isolated_from_publish_and_generate(): void
    {
        $improve = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/Services/ArticleImproveExecutionService.php',
        );
        $create = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/Services/CreateArticlesFromTaskService.php',
        );
        $catalog = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/Services/SeoProjectWorkflowStepCatalogService.php',
        );

        self::assertSame('article.content.improve', ArticleImproveExecutionService::HOOK_KEY);
        self::assertStringContainsString("HOOK_KEY = 'article.content.improve'", $improve);
        // Hook execute chỉ dùng self::HOOK_KEY (improve) — không gọi generate service.
        self::assertMatchesRegularExpression(
            '/\$this->hookExecution->execute\(\s*self::HOOK_KEY/s',
            $improve,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\$this->hookExecution->execute\(\s*[\'"]article\.content\.generate[\'"]/s',
            $improve,
        );
        self::assertStringNotContainsString('ArticleWritingExecutionService', $improve);
        self::assertStringNotContainsString('getPublishArticleTaskId', $improve);
        self::assertStringContainsString('ArticleImproveExecutionService', $create);
        self::assertStringContainsString('TYPE_IMPROVE', $create);
        self::assertStringContainsString('executeFromTaskContext', $create);
        self::assertStringContainsString('TYPE_IMPROVE', $catalog);
        self::assertStringContainsString('return null', $catalog);
    }

    public function test_cp_regenerate_uses_content_node_mode(): void
    {
        $create = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/Services/CreateArticlesFromTaskService.php',
        );
        self::assertStringContainsString('ArticleWritingExecutionMode::ContentNode', $create);
        self::assertStringContainsString('TYPE_REWRITE', $create);
        self::assertStringContainsString('runOutlineThenArticleForContext', $create);
        self::assertStringContainsString('PublishGraph', $create);
        self::assertStringNotContainsString('getRewriteArticleTaskId', $create);
    }

    public function test_editor_full_rewrite_wired_to_ui_and_resolver(): void
    {
        $edit = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        $actions = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
        );
        $resolver = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/Services/TaskTestInputResolver.php',
        );

        self::assertStringContainsString('function queueEditorFullRewrite', $edit);
        self::assertStringContainsString('resolveEditorFullRewrite', $edit);
        self::assertStringContainsString('ArticleWritingExecutionMode::DirectGenerate', $edit);
        self::assertStringContainsString('queueEditorFullRewrite', $actions);
        self::assertStringContainsString('type_rewrite_editor', $actions);
        self::assertStringContainsString('function resolveEditorFullRewrite', $resolver);
        self::assertStringContainsString('applyExistingArticleFromArticle', $resolver);
    }

    public function test_brief_raw_input_stamps_source_type(): void
    {
        $resolver = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/Services/TaskTestInputResolver.php',
        );
        self::assertStringContainsString('ArticleWritingSourceType::Brief', $resolver);
        self::assertStringContainsString('user_brief', $resolver);
        self::assertStringContainsString('brief_free_input', $resolver);
    }

    public function test_legacy_adapter_is_thin(): void
    {
        $adapter = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/Services/ArticleWritingLegacyRewriteAdapter.php',
        );
        $lines = substr_count($adapter, "\n") + 1;

        self::assertLessThan(160, $lines);
        self::assertStringContainsString('executeViaWritingService', $adapter);
        self::assertStringNotContainsString('getRewriteArticleTaskId', $adapter);
        self::assertStringNotContainsString('getPublishArticleTaskId', $adapter);
        self::assertStringNotContainsString('PromptHookExecutionService', $adapter);
        self::assertStringNotContainsString('publishArticle', $adapter);
    }

    public function test_context_rejects_dual_owner_settings_with_prompt_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ArticleWritingExecutionContext(
            mode: ArticleWritingExecutionMode::DirectGenerate,
            promptOwnerType: ArticleWritingPromptOwnerType::SettingsBinding,
            promptId: 99,
        ))->assertOwnerXor();
    }

    public function test_retry_snapshot_flag_on_context(): void
    {
        $ctx = new ArticleWritingExecutionContext(
            mode: ArticleWritingExecutionMode::ContentNode,
            promptOwnerType: ArticleWritingPromptOwnerType::WorkflowNode,
            promptId: 1,
            contentNodeId: 'n1',
            useRetrySnapshot: true,
            retrySnapshot: [
                'article_writing_source_type' => ArticleWritingSourceType::Outline->value,
                'article_length' => 1500,
                'prompt_id' => 1,
                'prompt_owner_type' => 'workflow_node',
            ],
        );

        self::assertTrue($ctx->useRetrySnapshot);
        self::assertSame(1500, $ctx->retrySnapshot['article_length']);
    }

    public function test_improve_hook_manifest_exists(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks/v01/article.content.improve@0.1.0.json';
        self::assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json);
        self::assertSame('article.content.improve', $json['key']);
        self::assertTrue($json['settings_visible']);
        self::assertStringContainsString('ArticleImproveExecutionService', (string) ($json['legacy_binding'] ?? ''));
    }

    public function test_history_exposes_owner_and_source_metadata(): void
    {
        $history = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/Services/ArticlePromptRunHistoryService.php',
        );
        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/resources/views/filament/resources/article-resource/pages/view-article-prompts.blade.php',
        );

        self::assertStringContainsString('owner_badge', $history);
        self::assertStringContainsString('prompt_owner_type', $history);
        self::assertStringContainsString('Owner: Settings', $history);
        self::assertStringContainsString('owner_badge', $view);
        self::assertStringContainsString('Artifact:', $view);
        self::assertStringContainsString('Length:', $view);
    }

    public function test_writing_input_brief_accepts_free_input(): void
    {
        $writing = ArticleWritingInput::fromBrief(
            title: 'T',
            keyword: 'K',
            freeInput: 'free notes',
        );

        self::assertSame(ArticleWritingSourceType::Brief, $writing->sourceType);
        self::assertSame('free notes', $writing->input);
    }

    public function test_service_provider_does_not_need_manual_bind_for_concrete_classes(): void
    {
        // Concrete classes — Laravel auto-wires; đảm bảo không còn final chặn mock nếu cần.
        $ref = new \ReflectionClass(ArticleWritingExecutionService::class);
        self::assertFalse($ref->isFinal());
        $refImprove = new \ReflectionClass(ArticleImproveExecutionService::class);
        self::assertFalse($refImprove->isFinal());
        self::assertTrue(class_exists(CreateArticlesFromTaskService::class));
        self::assertTrue(class_exists(SeoProjectWorkflowStepCatalogService::class));
        self::assertTrue(class_exists(TaskTestInputResolver::class));
        self::assertTrue(class_exists(ArticleWritingLegacyRewriteAdapter::class));
    }
}
