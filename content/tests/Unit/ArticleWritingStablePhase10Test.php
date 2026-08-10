<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Services\ArticleWritingStableHealthService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use PHPUnit\Framework\TestCase;

final class ArticleWritingStablePhase10Test extends TestCase
{
    public function test_settings_ui_does_not_render_rewrite_field(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Filament/Pages/SeoSettingsWorkflows.php',
        );
        self::assertStringNotContainsString(
            "taskSelect(\n                            SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE",
            $src,
        );
        self::assertStringContainsString('KEY_REWRITE_ARTICLE: legacy DB field', $src);
        self::assertStringContainsString(
            'KEY_REWRITE_ARTICLE => $settings->getSettings()[SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE]',
            $src,
        );
    }

    public function test_save_settings_preserves_legacy_rewrite_value(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Filament/Pages/SeoSettingsWorkflows.php',
        );
        // Form khÃ´ng sá»Ÿ há»¯u field â†’ save láº¥y láº¡i tá»« DB/settings hiá»‡n táº¡i.
        self::assertStringContainsString(
            'SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE => $settings->getSettings()[SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE] ?? null',
            $src,
        );
        self::assertSame('rewrite_article_task_id', SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE);
    }

    public function test_hook_selector_excludes_rewrite_for_new_prompts(): void
    {
        $ref = new \ReflectionClass(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog::class);
        self::assertTrue($ref->hasMethod('isLegacyCompatibilityHook'));
        self::assertTrue($ref->hasMethod('selectOptionsForEditing'));

        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Runtime/PromptHookEditorCatalog.php',
        );
        self::assertStringContainsString('isLegacyCompatibilityHook', $src);
        self::assertStringContainsString('selectOptionsForEditing', $src);
    }

    public function test_catalog_select_options_skip_rewrite(): void
    {
        // KhÃ´ng bootstrap full registry â€” assert source filter + editing keep.
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Runtime/PromptHookEditorCatalog.php',
        );
        self::assertStringContainsString(
            'if ($this->isLegacyCompatibilityHook($definition->key->value))',
            $src,
        );
        self::assertStringContainsString('Rewrite article content (Legacy)', $src);
    }

    public function test_legacy_prompt_warning_in_form_schema(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/PromptHookFormSchema.php',
        );
        self::assertStringContainsString('hook_legacy_rewrite_warning', $src);
        self::assertStringContainsString('selectOptionsForEditing', $src);
    }

    public function test_prompt_duplicate_remaps_legacy_hook(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Filament/Resources/PromptResource.php',
        );
        self::assertStringContainsString('isLegacyCompatibilityHook', $src);
        self::assertStringContainsString('duplicate_legacy_remapped', $src);
        self::assertStringContainsString('ArticleWritingExecutionService::HOOK_KEY', $src);
    }

    public function test_explicit_generate_does_not_log_legacy_adapter(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Runtime/PromptHookExplicitBindingExecutor.php',
        );
        self::assertStringContainsString('isLegacyRewriteHook($binding->hookKey)', $src);
        self::assertStringContainsString('DEPRECATED COMPATIBILITY ONLY', $src);
        // Log chá»‰ trong nhÃ¡nh isLegacyRewriteHook.
        self::assertMatchesRegularExpression(
            '/if \(\$this->legacyRewriteAdapter->isLegacyRewriteHook\(\$binding->hookKey\)\) \{[^}]*logLegacyAdapterUsed/s',
            $src,
        );
    }

    public function test_task_runner_adapter_only_for_rewrite_hook(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringContainsString('DEPRECATED COMPATIBILITY ONLY', $src);
        self::assertStringContainsString('isLegacyRewriteHook($hookKey)', $src);
        self::assertStringContainsString("caller: self::class.'::runPromptNode'", $src);
    }

    public function test_editor_action_uses_generate_existing_article(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        self::assertStringContainsString('queueEditorFullRewrite', $src);
        self::assertStringContainsString('existing_article', $src);
        self::assertStringContainsString('type_rewrite_editor', $src);
    }

    public function test_no_runtime_reader_rewrite_task_id(): void
    {
        $health = new \ReflectionClass(ArticleWritingStableHealthService::class);
        self::assertTrue($health->hasMethod('runtimeStillReadsRewriteTaskId'));
        // Instantiating without container: call static-like via partial mock pattern â€” use file scan helper.
        $svcFile = ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingStableHealthService.php';
        self::assertFileExists($svcFile);
        $probe = new class
        {
            public function stillReads(): bool
            {
                $files = [
                    dirname(__DIR__, 4).'/app/Addons'.'/Services/CreateArticlesFromTaskService.php',
                    dirname(__DIR__, 4).'/app/Addons'.'/Services/ArticleWritingExecutionService.php',
                    dirname(__DIR__, 4).'/app/Addons'.'/Services/ArticleWritingLegacyRewriteAdapter.php',
                    dirname(__DIR__, 4).'/app/Addons'.'/Services/TaskWorkflowTestRunner.php',
                ];
                // paths wrong in anon â€” use absolute from test
                return false;
            }
        };
        unset($probe);

        foreach ([
            'CreateArticlesFromTaskService.php' => 'addons/content-projects/src/Services/CreateArticlesFromTaskService.php',
            'ArticleWritingExecutionService.php' => 'addons/content/src/Services/ArticleWritingExecutionService.php',
            'ArticleWritingLegacyRewriteAdapter.php' => 'addons/content/src/Services/ArticleWritingLegacyRewriteAdapter.php',
            'SeoProjectWorkflowStepCatalogService.php' => 'addons/content-projects/src/Services/SeoProjectWorkflowStepCatalogService.php',
            'TaskWorkflowTestRunner.php' => 'addons/ai-prompt/src/Services/TaskWorkflowTestRunner.php',
        ] as $name => $relative) {
            $src = (string) file_get_contents(dirname(__DIR__, 4).'/'.$relative);
            self::assertStringNotContainsString('getRewriteArticleTaskId(', $src, $name);
        }
    }

    public function test_stable_health_file_gates_and_evaluate_fail_contract(): void
    {
        $ref = new \ReflectionClass(ArticleWritingStableHealthService::class);
        $health = $ref->newInstanceWithoutConstructor();

        self::assertFalse($health->runtimeStillReadsRewriteTaskId());
        self::assertFalse($health->runtimeStillHasTitleHeuristic());
        self::assertFalse($health->retryFallsBackToLiveNode());

        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingStableHealthService.php',
        );
        self::assertStringContainsString('Thiáº¿u Settings binding article.content.generate', $src);
        self::assertStringContainsString('Thiáº¿u Settings binding article.content.improve', $src);
        self::assertStringContainsString('STATUS_FAIL', $src);
        self::assertStringContainsString('rewrite_task_id_populated', $src);
    }

    public function test_stable_health_warns_when_legacy_db_populated(): void
    {
        $settings = new class implements \Omnichannel\Addons\Content\Contracts\SeoCreateArticleSettingsReader
        {
            public function getSettings(): array
            {
                return ['rewrite_article_task_id' => 9];
            }

            public function getPublishArticleTaskId(): ?int
            {
                return null;
            }
        };

        $ref = new \ReflectionClass(ArticleWritingStableHealthService::class);
        $health = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('settings')->setValue($health, $settings);

        $legacy = $health->legacyInventory();
        self::assertSame(1, $legacy['rewrite_task_id_populated']);
    }

    public function test_doctor_prints_stable_gate(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Console/WorkflowDoctorCommand.php',
        );
        self::assertStringContainsString('Article Writing Stable Gate:', $src);
        self::assertStringContainsString('Legacy compatibility', $src);
        self::assertStringContainsString('ArticleWritingStableHealthService', $src);
    }

    public function test_builder_does_not_treat_rewrite_as_write_from_outline(): void
    {
        $jsx = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/js/components/ArticleFlowBuilder.jsx',
        );
        self::assertStringContainsString("hook === 'article.content.generate'", $jsx);
        self::assertStringNotContainsString("hook === 'article.content.rewrite'", $jsx);
    }

    public function test_content_project_labels_final(): void
    {
        $vi = (string) file_get_contents(LegacyAddonPath::resolve('lang/vi/filament.php'));
        self::assertStringContainsString("'type_rewrite' => 'Táº¡o láº¡i bÃ i tá»« dÃ n Ã½'", $vi);
        self::assertStringContainsString("'type_rewrite_editor' => 'Viáº¿t láº¡i toÃ n bá»™ bÃ i hiá»‡n cÃ³'", $vi);
    }

    public function test_option_labels_include_hook_key_code(): void
    {
        $catalogClass = \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog::class;
        self::assertTrue((new \ReflectionClass($catalogClass))->hasMethod('labelWithHookKey'));

        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Runtime/PromptHookEditorCatalog.php',
        );
        // Single-quoted needle â€” double-quote sáº½ ná»™i suy $definition vÃ  needle thÃ nh rÃ¡c.
        self::assertStringContainsString(
            '.$definition->key->value.',
            $src,
        );
        self::assertStringContainsString('function labelWithHookKey', $src);

        $loader = new \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader(
            \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader::defaultV01Directory(),
            \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $catalog = new \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog(
            new \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry($loader),
        );

        self::assertSame(
            'Write article [article.content.generate]',
            $catalog->labelWithHookKey('Write article', 'article.content.generate'),
        );

        $byKey = [];
        foreach ($catalog->optionsForTextPromptBlock() as $row) {
            $byKey[$row['hook_key']] = $row;
        }
        self::assertArrayHasKey('article.content.generate', $byKey);
        self::assertStringContainsString(
            '[article.content.generate]',
            $byKey['article.content.generate']['option_label'],
        );
    }
}
