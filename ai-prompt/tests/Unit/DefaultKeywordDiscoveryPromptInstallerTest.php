<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Console\InstallDefaultKeywordDiscoveryPromptCommand;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSpecV01Validator;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultKeywordDiscoveryPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class DefaultKeywordDiscoveryPromptInstallerTest extends TestCase
{
    public function test_hook_key_name_and_canonical_markdown_from_json(): void
    {
        self::assertSame('keyword.discovery.structured', DefaultKeywordDiscoveryPromptInstaller::HOOK_KEY);
        self::assertSame('0.1.0', DefaultKeywordDiscoveryPromptInstaller::HOOK_VERSION);
        self::assertSame('Keyword Discovery', DefaultKeywordDiscoveryPromptInstaller::PROMPT_NAME);

        $markdown = DefaultKeywordDiscoveryPromptInstaller::canonicalDefaultMarkdown();

        self::assertStringContainsString('{{seed_topic}}', $markdown);
        self::assertStringContainsString('{{content_type}}', $markdown);
        self::assertStringContainsString('{{brief}}', $markdown);
        self::assertStringContainsString('description', $markdown);
        self::assertStringContainsString('planning suggestions', strtolower($markdown));
    }

    public function test_installer_does_not_overwrite_existing_binding_or_markdown_without_restore(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptOwnership/DefaultKeywordDiscoveryPromptInstaller.php',
        );

        self::assertStringContainsString('if (! isset($bindings[self::HOOK_KEY]))', $source);
        self::assertStringContainsString('canonicalDefaultMarkdown()', $source);
        self::assertStringContainsString('restoreCanonical', $source);
        self::assertStringContainsString('PromptHookDefinitionLoader::defaultV01Directory()', $source);
        self::assertStringContainsString('canonical_default', $source);
        self::assertStringNotContainsString('MARKDOWN = <<<', $source);
    }

    public function test_command_and_migration_are_registered(): void
    {
        $props = (new \ReflectionClass(InstallDefaultKeywordDiscoveryPromptCommand::class))->getDefaultProperties();
        self::assertSame(
            'seo:prompt:install-default-keyword-discovery {--restore : Restore markdown from canonical Hook JSON (overwrites this system default prompt)}',
            $props['signature'] ?? null,
        );

        self::assertFileExists(
            ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_08_25_100000_install_default_keyword_discovery_prompt_binding.php',
        );

        $provider = (string) file_get_contents(LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'));
        self::assertStringContainsString('InstallDefaultKeywordDiscoveryPromptCommand::class', $provider);
    }

    public function test_hook_spec_is_settings_visible_legacy_editable_with_content_type(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/keyword.discovery.structured@0.1.0.json';
        self::assertFileExists($path);

        $spec = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($spec);
        self::assertSame([], (new PromptHookSpecV01Validator)->validate($spec));
        self::assertTrue($spec['settings_visible']);
        self::assertSame('legacy_prompt_content', $spec['template']['source']);
        self::assertSame('', trim((string) ($spec['template']['system'] ?? '')));
        self::assertSame('', trim((string) ($spec['template']['user'] ?? '')));
        self::assertNotSame('', trim((string) ($spec['canonical_default']['markdown'] ?? '')));
        self::assertArrayHasKey('content_type', $spec['input_schema']);
        self::assertArrayHasKey('post_type', $spec['input_schema']);
        self::assertArrayHasKey('seed_topic', $spec['input_schema']);
        self::assertStringContainsString('gsc_signal', (string) $spec['canonical_default']['markdown']);
        self::assertStringContainsString('description', (string) $spec['canonical_default']['markdown']);

        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $registry = new PromptHookRuntimeRegistry($loader);
        $definition = $registry->get('keyword.discovery.structured', '0.1.0');
        self::assertTrue($definition->settingsVisible);

        $catalog = new PromptHookEditorCatalog($registry);
        $keys = array_column($catalog->settingsVisibleHooks(), 'hook_key');
        self::assertContains('keyword.discovery.structured', $keys);

        $presentation = new PromptHookPresentationService($catalog);
        $view = $presentation->forHook('keyword.discovery.structured');
        self::assertNotNull($view);
        self::assertSame(PromptHookPresentationService::CONTENT_MODE_LEGACY_PROMPT, $view['content_mode']);
        self::assertTrue($view['uses_prompt_markdown']);
    }
}
