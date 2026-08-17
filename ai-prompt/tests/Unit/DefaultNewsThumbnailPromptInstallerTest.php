<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Console\InstallDefaultNewsThumbnailPromptCommand;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSpecV01Validator;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultNewsThumbnailPromptInstaller;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleRegistry;
use Omnichannel\Addons\Media\Support\ImageToolType;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class DefaultNewsThumbnailPromptInstallerTest extends TestCase
{
    public function test_hook_key_name_and_title_variable(): void
    {
        self::assertSame('article.featured_image.generate', DefaultNewsThumbnailPromptInstaller::HOOK_KEY);
        self::assertSame('Create news thumbnail', DefaultNewsThumbnailPromptInstaller::PROMPT_NAME);
        self::assertSame("Tôi cần thumbnail cho bài viết {{title}}", trim(DefaultNewsThumbnailPromptInstaller::MARKDOWN));
        self::assertStringContainsString('{{title}}', DefaultNewsThumbnailPromptInstaller::MARKDOWN);
    }

    public function test_installer_does_not_overwrite_existing_binding(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptOwnership/DefaultNewsThumbnailPromptInstaller.php',
        );

        self::assertStringContainsString('if (! isset($bindings[self::HOOK_KEY]))', $source);
        self::assertStringContainsString('savePromptHookBindings', $source);
        self::assertStringContainsString("ImageToolType::Image->value", $source);
        self::assertStringContainsString("'name' => 'title'", $source);
    }

    public function test_command_and_migration_are_registered(): void
    {
        $props = (new \ReflectionClass(InstallDefaultNewsThumbnailPromptCommand::class))->getDefaultProperties();
        self::assertSame('seo:prompt:install-default-news-thumbnail', $props['signature'] ?? null);

        self::assertFileExists(
            ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_08_17_110000_install_default_news_thumbnail_prompt_binding.php',
        );

        $provider = (string) file_get_contents(LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'));
        self::assertStringContainsString('InstallDefaultNewsThumbnailPromptCommand::class', $provider);
    }

    public function test_hook_spec_is_settings_visible_image_capability_without_model_settings(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/article.featured_image.generate@0.1.0.json';
        self::assertFileExists($path);

        $spec = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($spec);
        self::assertSame([], (new PromptHookSpecV01Validator)->validate($spec));
        self::assertTrue($spec['settings_visible']);
        self::assertSame('image', $spec['model']['capability']);
        self::assertSame([], $spec['model']['settings']);
        self::assertSame('legacy_prompt_content', $spec['template']['source']);
        self::assertArrayHasKey('title', $spec['input_schema']);

        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $definition = (new PromptHookRuntimeRegistry($loader))->get('article.featured_image.generate', '0.1.0');
        self::assertTrue($definition->settingsVisible);
        self::assertSame('image', $definition->model->capability);
    }

    public function test_automation_maps_featured_image_hook_to_image_generate_role(): void
    {
        $registry = new WorkflowExecutionRoleRegistry;

        self::assertTrue($registry->isHookAllowed(
            WorkflowExecutionRole::ArticleImageGenerate,
            DefaultNewsThumbnailPromptInstaller::HOOK_KEY,
        ));
        self::assertSame(
            WorkflowExecutionRole::ArticleImageGenerate,
            $registry->suggestRoleFromHook(DefaultNewsThumbnailPromptInstaller::HOOK_KEY),
        );

        $builder = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/js/components/ArticleFlowBuilder.jsx',
        );
        self::assertStringContainsString("hook === 'article.featured_image.generate'", $builder);
        self::assertStringContainsString("'article.image.generate'", $builder);
    }

    public function test_default_tools_are_general_image_pipeline(): void
    {
        self::assertSame(ImageToolType::Image->value, 'image');
    }
}
