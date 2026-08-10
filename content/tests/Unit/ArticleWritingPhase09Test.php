<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter;
use Omnichannel\Addons\AiPrompt\Services\WorkflowExistingAiOutputService;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowRoleMigrationSuggester;
use PHPUnit\Framework\TestCase;

final class ArticleWritingPhase09Test extends TestCase
{
    public function test_runner_has_no_title_heuristic_for_outline_capture(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringNotContainsString("str_contains(\$title, 'dÃ n Ã½')", $src);
        self::assertStringNotContainsString("str_contains(\$haystack, 'dÃ n Ã½')", $src);
        self::assertStringContainsString('isOutlineExecutionNode', $src);
        self::assertStringContainsString('WorkflowExecutionRole::ArticleOutlineGenerate', $src);
    }

    public function test_outline_producer_ignores_title_and_latest_content_artifact(): void
    {
        $outline = \Mockery::mock(ArticleOutlineResolver::class);
        $resolver = new ArticleGenerationInputResolver($outline);
        self::assertFalse($resolver->isOutlineProducerStep([
            'title' => 'Táº¡o dÃ n Ã½ SEO',
            'prompt_name' => 'Outline magic',
            'hook_key' => '',
            'output' => ArticleGenerationInputResolver::OUTLINE_START."\nx\n"
                .ArticleGenerationInputResolver::OUTLINE_END."\n"
                .ArticleGenerationInputResolver::VOCABULARY_START."\ny\n"
                .ArticleGenerationInputResolver::VOCABULARY_END,
        ]));
        self::assertTrue($resolver->isOutlineProducerStep([
            'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
            'output' => 'x',
        ]));
        self::assertFalse($resolver->isOutlineProducerStep([
            'title' => 'Viáº¿t bÃ i theo dÃ n Ã½',
            'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
            'hook_key' => 'article.content.generate',
            'output' => ArticleGenerationInputResolver::OUTLINE_START."\nx\n"
                .ArticleGenerationInputResolver::OUTLINE_END."\n"
                .ArticleGenerationInputResolver::VOCABULARY_START."\ny\n"
                .ArticleGenerationInputResolver::VOCABULARY_END,
        ]));
    }

    public function test_existing_ai_output_uses_role_not_prompt_name(): void
    {
        $svc = new WorkflowExistingAiOutputService;
        $prompt = (new SeoPrompt)->forceFill(['name' => 'DÃ n Ã½ bÃ i viáº¿t']);
        self::assertNull($svc->outputType([], $prompt));
        self::assertSame(
            WorkflowExistingAiOutputService::TYPE_OUTLINE,
            $svc->outputType([
                'data' => ['execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value],
            ], $prompt),
        );
        self::assertSame(
            WorkflowExistingAiOutputService::TYPE_CONTENT,
            $svc->outputType([
                'data' => ['execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value],
            ], $prompt),
        );
    }

    public function test_no_runtime_reads_rewrite_article_task_id(): void
    {
        $roots = [
            ProjectRoot::addonsPath().'/content-projects/src/Services/CreateArticlesFromTaskService.php',
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingExecutionService.php',
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingLegacyRewriteAdapter.php',
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepCatalogService.php',
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/TaskWorkflowTestRunner.php',
        ];
        foreach ($roots as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('getRewriteArticleTaskId(', $src, basename($file));
        }
    }

    public function test_legacy_adapter_only_delegates(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingLegacyRewriteAdapter.php',
        );
        self::assertStringNotContainsString('getPublishArticleTaskId', $src);
        self::assertStringNotContainsString('getRewriteArticleTaskId', $src);
        self::assertStringNotContainsString('SeoTask::', $src);
        self::assertStringContainsString('ArticleWritingExecutionService', $src);
        self::assertLessThan(160, substr_count($src, "\n"));
    }

    public function test_rewrite_hook_remaps_to_generate(): void
    {
        $adapter = new ArticleWritingLegacyRewriteAdapter(
            new \Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter,
        );
        self::assertSame(
            ArticleWritingLegacyRewriteAdapter::GENERATE_HOOK,
            $adapter->canonicalizeHookKey(ArticleWritingLegacyRewriteAdapter::LEGACY_REWRITE_HOOK),
        );
        self::assertSame(
            'existing_article',
            $adapter->defaultSourceTypeForLegacyRewrite()->value,
        );
    }

    public function test_retry_missing_snapshot_requires_rerun_message(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingExecutionService.php',
        );
        self::assertStringContainsString(
            'KhÃ´ng thá»ƒ thá»­ láº¡i láº§n cháº¡y cÅ©. HÃ£y chá»n Â«Cháº¡y láº¡i báº±ng cáº¥u hÃ¬nh hiá»‡n táº¡iÂ».',
            $src,
        );
        self::assertStringContainsString('retrySnapshotIsComplete', $src);
        // Retry khÃ´ng fallback live contentNodeId tá»« context khi snapshot thiáº¿u.
        self::assertDoesNotMatchRegularExpression(
            '/contentNodeId:\s*\$contentNodeId !== \'\' \? \$contentNodeId : \$context->contentNodeId/',
            $src,
        );
    }

    public function test_history_metadata_contract_keys(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingExecutionService.php',
        );
        foreach ([
            "'source_type'",
            "'workflow_id'",
            "'workflow_hash'",
            "'node_id'",
            "'execution_role'",
            "'source_hash'",
            "'retry_or_rerun'",
            "'legacy_adapter'",
        ] as $key) {
            self::assertStringContainsString($key, $src);
        }
    }

    public function test_heuristic_only_in_migration_suggester_for_roles(): void
    {
        self::assertTrue(class_exists(WorkflowRoleMigrationSuggester::class));
        $runner = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/TaskWorkflowTestRunner.php',
        );
        $resolver = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ArticleGenerationInputResolver.php',
        );
        $existing = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/WorkflowExistingAiOutputService.php',
        );
        foreach ([$runner, $resolver, $existing] as $src) {
            self::assertStringNotContainsString("str_contains(\$haystack, 'dÃ n Ã½')", $src);
        }
    }

    public function test_execution_service_hook_constant(): void
    {
        self::assertSame('article.content.generate', ArticleWritingExecutionService::HOOK_KEY);
    }
}
