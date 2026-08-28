<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Console\InstallDefaultSplitOutlinePromptsCommand;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSpecV01Validator;
use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class DefaultSplitOutlinePromptsInstallerTest extends TestCase
{
    public function test_prompt_names_and_hooks(): void
    {
        self::assertSame('article.outline.structure.generate', DefaultSplitOutlinePromptsInstaller::OUTLINE_HOOK);
        self::assertSame('article.vocabulary.generate', DefaultSplitOutlinePromptsInstaller::VOCABULARY_HOOK);
        self::assertSame('Dàn ý bài viết — Outline', DefaultSplitOutlinePromptsInstaller::OUTLINE_PROMPT_NAME);
        self::assertSame('Từ vựng bài viết — Vocabulary', DefaultSplitOutlinePromptsInstaller::VOCABULARY_PROMPT_NAME);
    }

    public function test_outline_markdown_contains_only_task_1(): void
    {
        $markdown = DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN;
        self::assertStringContainsString('{{post_title}}', $markdown);
        self::assertStringContainsString('[START_TASK_1_OUTLINE]', $markdown);
        self::assertStringContainsString('[END_TASK_1_OUTLINE]', $markdown);
        self::assertStringNotContainsString('START_TASK_2_VOCABULARY', $markdown);
        self::assertStringNotContainsString('Holonymy', $markdown);
    }

    public function test_vocabulary_markdown_contains_only_task_2(): void
    {
        $markdown = DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN;
        self::assertStringContainsString('{{post_title}}', $markdown);
        self::assertStringContainsString('{{outline}}', $markdown);
        self::assertStringContainsString('Holonymy', $markdown);
        self::assertStringContainsString('[START_TASK_2_VOCABULARY]', $markdown);
        self::assertStringNotContainsString('START_TASK_1_OUTLINE', $markdown);
        self::assertStringNotContainsString('Nhiệm vụ: Dàn ý', $markdown);
    }

    public function test_installer_is_idempotent_by_design(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptOwnership/DefaultSplitOutlinePromptsInstaller.php',
        );
        self::assertStringContainsString('portable_uuid', $source);
        self::assertStringContainsString('if (! isset($bindings[$hookKey]))', $source);
        self::assertStringContainsString('findExisting', $source);
    }

    public function test_split_executor_binds_outline_and_post_title_for_vocabulary(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/ArticleOutlineVocabularySplitExecutor.php',
        );
        self::assertStringNotContainsString('normalizeOutlinePromptHook', $source);
        self::assertStringNotContainsString('normalizeOutlinePromptHook($fallbackPrompt', $source);
        self::assertStringContainsString('bindVocabularyVariables', $source);
        self::assertStringContainsString("missing required post_title", $source);
        self::assertStringContainsString("missing required outline", $source);
        self::assertStringContainsString("\$out['outline'] = \$outlineMarkdown", $source);
    }

    public function test_command_and_migration_are_registered(): void
    {
        $props = (new \ReflectionClass(InstallDefaultSplitOutlinePromptsCommand::class))->getDefaultProperties();
        self::assertStringStartsWith('seo:prompt:install-split-outline-prompts', (string) ($props['signature'] ?? ''));

        self::assertFileExists(
            ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_08_23_120000_install_split_outline_vocabulary_prompt_bindings.php',
        );

        $provider = (string) file_get_contents(LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'));
        self::assertStringContainsString('InstallDefaultSplitOutlinePromptsCommand::class', $provider);
    }

    public function test_split_hook_specs_validate_with_markers(): void
    {
        $validator = new PromptHookSpecV01Validator;
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $registry = new PromptHookRuntimeRegistry($loader);

        foreach ([ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK, ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK] as $hook) {
            $path = ProjectRoot::addonsPath()."/ai-prompt/resources/prompt-hooks/v01/{$hook}@0.1.0.json";
            $spec = json_decode((string) file_get_contents($path), true);
            self::assertIsArray($spec);
            self::assertSame([], $validator->validate($spec));
            $registry->get($hook, '0.1.0');
        }
    }

    public function test_legacy_combined_hook_unchanged(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/article.outline.generate@0.1.0.json';
        $spec = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($spec);
        self::assertSame('article.outline.generate', $spec['key']);
        self::assertTrue($spec['output_schema']['combined_output']['enabled'] ?? false);
    }

    public function test_assemble_ports_legacy_projection(): void
    {
        $executor = (new \ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))
            ->newInstanceWithoutConstructor();
        $ports = $executor->assemblePorts('## Outline', '### Holonymy\n- a');

        self::assertArrayHasKey('task_1_outline', $ports);
        self::assertArrayHasKey('task_2_vocabulary', $ports);
        self::assertArrayHasKey('total', $ports);
        self::assertStringContainsString(ArticleGenerationInputResolver::OUTLINE_START, $ports['total']);
        self::assertStringContainsString(ArticleGenerationInputResolver::VOCABULARY_START, $ports['total']);
    }
}
