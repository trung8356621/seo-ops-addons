<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultAgentRuntimePromptInstaller;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

final class AgentRuntimePromptOwnershipTest extends TestCase
{
    public function test_agent_hooks_are_settings_visible_structured_text_hooks_without_variables_or_side_effects(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $registry = new PromptHookRuntimeRegistry($loader);
        $catalog = new PromptHookEditorCatalog($registry);
        $visible = array_column($catalog->settingsVisibleHooks(), 'hook_key');

        foreach (['agent.routing.decide', 'agent.response.compose'] as $hookKey) {
            $definition = $registry->get($hookKey, '0.1.0');
            self::assertTrue($definition->settingsVisible);
            self::assertSame('agent', $definition->category);
            self::assertEmpty($definition->inputSchema->fields);
            self::assertSame('json', $definition->outputSchema->type);
            $specPath = PromptHookDefinitionLoader::defaultV01Directory().DIRECTORY_SEPARATOR.$hookKey.'@0.1.0.json';
            $spec = json_decode((string) file_get_contents($specPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], $spec['side_effects'] ?? null);
            self::assertContains($hookKey, $visible);
        }
    }

    public function test_canonical_markdown_preserves_the_legacy_runtime_contracts(): void
    {
        $routing = DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('routing');
        $response = DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('response');

        self::assertStringContainsString('Return one JSON object and nothing else.', $routing);
        self::assertStringContainsString('requires_parameter_extraction', $routing);
        self::assertStringContainsString('response_template', $routing);
        self::assertStringContainsString('response_catalog', $routing);
        self::assertStringContainsString('Return exactly one valid JSON object matching AgentResponse', $response);
        self::assertStringContainsString('selected_response_template', $response);
        self::assertStringContainsString('do not infer or replace the template', $response);
        self::assertStringContainsString('NEW SUGGESTED IDEAS', $response);
    }

    public function test_installer_owns_two_defaults_is_idempotent_and_preserves_existing_bindings(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(DefaultAgentRuntimePromptInstaller::class))->getFileName());

        self::assertSame(2, substr_count($source, "'hook' => 'agent."));
        self::assertStringContainsString("'is_system_default' => true", $source);
        self::assertStringContainsString("'ownership' => 'settings_binding'", $source);
        self::assertStringContainsString("if (! isset(\$bindings[\$config['hook']]))", $source);
        self::assertStringContainsString("->where('hook_key', \$config['hook'])", $source);
        self::assertStringContainsString('managedPromptOwnerUserId', $source);
    }

    public function test_non_destructive_migration_installs_both_defaults(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_10_03_100000_install_default_agent_runtime_prompt_bindings.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('DefaultAgentRuntimePromptInstaller', $source);
        self::assertStringContainsString('->install()', $source);
        self::assertStringContainsString('public function down(): void {}', $source);
    }
}
