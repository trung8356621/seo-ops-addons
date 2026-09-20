<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\DefinitionNotFound;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\AiPrompt\Support\ArticleContentGenerationHooks;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategyResolver;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategySnapshot;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryLegacyClassifier;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleRegistry;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Post-purge contracts: rewrite hook and WritingSplitPreference are retired from runtime.
 */
final class ArticleLegacyPurgeContractTest extends TestCase
{
    private function registry(): PromptHookRuntimeRegistry
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();

        return new PromptHookRuntimeRegistry($loader);
    }

    private function catalog(): PromptHookEditorCatalog
    {
        return new PromptHookEditorCatalog($this->registry());
    }

    public function test_content_generation_hooks_keys_are_generate_only(): void
    {
        self::assertSame(['article.content.generate'], ArticleContentGenerationHooks::keys());
        self::assertSame('article.content.generate', ArticleContentGenerationHooks::GENERATE);
    }

    public function test_writing_split_preference_class_does_not_exist(): void
    {
        self::assertFalse(class_exists('Omnichannel\\Addons\\Content\\Support\\WritingSplitPreference', false));
    }

    public function test_registry_has_no_executable_rewrite_definition(): void
    {
        $registry = $this->registry();

        self::assertFalse($registry->has('article.content.rewrite', '0.1.0'));

        $this->expectException(DefinitionNotFound::class);
        $registry->get('article.content.rewrite', '0.1.0');
    }

    public function test_editor_catalog_cannot_select_rewrite_for_new_prompts(): void
    {
        $catalog = $this->catalog();
        $hookKeys = array_column($catalog->optionsForTextPromptBlock(), 'hook_key');

        self::assertNotContains('article.content.rewrite', $hookKeys);
        self::assertNotContains('article.content.rewrite', array_keys($catalog->selectOptions()));
        self::assertFalse($catalog->isLegacyCompatibilityHook('article.content.rewrite'));
    }

    public function test_workflow_role_registry_does_not_allow_rewrite_binding(): void
    {
        $roles = new WorkflowExecutionRoleRegistry();
        self::assertFalse($roles->isHookAllowed(
            WorkflowExecutionRole::ArticleContentGenerate,
            'article.content.rewrite',
        ));
        self::assertTrue($roles->isHookAllowed(
            WorkflowExecutionRole::ArticleContentGenerate,
            'article.content.generate',
        ));
        self::assertSame(
            WorkflowExecutionRole::ArticleContentGenerate,
            $roles->suggestRoleFromHook('article.content.rewrite'),
        );
    }

    public function test_history_classifier_still_recognizes_old_rewrite_hook(): void
    {
        $outlineResolver = new ArticleOutlineResolver(
            new WorkflowParserService(new SeoPromptSettingsService, new SeoOverviewSettingsService),
        );
        $classifier = new ArticleAiHistoryLegacyClassifier($outlineResolver, new ArtifactReusePolicy);

        $body = str_repeat('Paragraph about the product features and benefits. ', 40);
        $result = $classifier->classify([
            'execution_role' => 'article.content.rewrite',
            'status' => 'success',
            'output' => $body,
        ], $body);

        self::assertSame('legacy', $result['classification']);
        self::assertTrue($result['can_apply']);
    }

    public function test_override_is_not_new_run_authority(): void
    {
        $resolver = new ArticleGenerationStrategyResolver();
        self::assertSame(
            ArticleGenerationStrategy::SinglePass,
            $resolver->resolve(['generation_strategy_override' => 'sectioned_free']),
        );
        self::assertTrue(
            $resolver->resolveFromHistory(['generation_strategy_override' => 'sectioned_free'])->isSectioned(),
        );

        $snap = ArticleGenerationStrategySnapshot::fromVariables([
            'generation_strategy_override' => 'sectioned_free',
            'generation_shape' => 'single_pass',
        ]);
        self::assertNull($snap->strategyOverride);
        self::assertSame('single_pass', $snap->strategyResolved);
    }

    public function test_sectioned_free_alias_parses_but_canonical_is_sectioned(): void
    {
        self::assertSame('sectioned', ArticleGenerationStrategy::resolve('sectioned_free')->value);
        self::assertSame(
            ArticleGenerationStrategy::SectionedFree,
            ArticleGenerationStrategy::tryFromMixed('sectioned_free'),
        );
    }
}
