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

        foreach (['agent.routing.decide' => '0.2.0', 'agent.response.compose' => '0.2.0'] as $hookKey => $version) {
            $definition = $registry->get($hookKey, $version);
            self::assertTrue($definition->settingsVisible);
            self::assertSame('agent', $definition->category);
            self::assertEmpty($definition->inputSchema->fields);
            self::assertSame('json', $definition->outputSchema->type);
            $specPath = PromptHookDefinitionLoader::defaultV01Directory().DIRECTORY_SEPARATOR.$hookKey.'@'.$version.'.json';
            $spec = json_decode((string) file_get_contents($specPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], $spec['side_effects'] ?? null);
            self::assertContains($hookKey, $visible);
        }
    }

    public function test_canonical_markdown_preserves_the_legacy_runtime_contracts(): void
    {
        $routing = DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('routing');
        $response = DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('response');

        self::assertStringContainsString('Return one routing JSON object and nothing else.', $routing);
        self::assertStringContainsString('First decide is_in_scope', $routing);
        self::assertStringContainsString('Do not force them into a capability', $routing);
        self::assertStringContainsString('plain lookup or detail request for one specific article', $routing);
        self::assertStringContainsString('Do not select articles.inventory merely because article_ref exists', $routing);
        self::assertStringContainsString('primary_capability', $routing);
        self::assertStringContainsString('capability_catalog', $routing);
        self::assertStringContainsString('requires_parameter_extraction', $routing);
        self::assertStringContainsString('response_template', $routing);
        self::assertStringContainsString('response_catalog', $routing);
        self::assertStringContainsString('Return exactly one valid JSON object matching AgentResponse', $response);
        self::assertStringContainsString('selected_response_template', $response);
        self::assertStringContainsString('do not infer or replace the template', $response);
        self::assertStringContainsString('headings, explanations, recommendations, warning text, table titles and column labels, and chart titles and series labels', $response);
        self::assertStringContainsString('Translate generic presentation and SEO terms', $response);
        self::assertStringContainsString('Preserve exact retrieved entity, product, brand, and proper names', $response);
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

    public function test_forward_reconciliation_migration_uses_current_installer_without_silent_failure(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_10_05_100000_reconcile_default_agent_runtime_prompt_bindings.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('DefaultAgentRuntimePromptInstaller', $source);
        self::assertStringContainsString('->install()', $source);
        self::assertStringNotContainsString('catch (Throwable', $source);
        self::assertStringNotContainsString('catch (\\Throwable', $source);
    }

    public function test_routing_contract_upgrade_migration_uses_routing_installer_only(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_10_05_110000_upgrade_agent_routing_contract_to_v020.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('DefaultAgentRuntimePromptInstaller', $source);
        self::assertStringContainsString("->installType('routing')", $source);
        self::assertStringNotContainsString('catch (Throwable', $source);
        self::assertStringNotContainsString('catch (\\Throwable', $source);
    }

    public function test_response_contract_upgrade_migration_uses_response_installer_only(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_10_05_120000_upgrade_agent_response_contract_to_v020.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('DefaultAgentRuntimePromptInstaller', $source);
        self::assertStringContainsString("->installType('response')", $source);
        self::assertStringNotContainsString("->installType('routing')", $source);
        self::assertStringNotContainsString('catch (Throwable', $source);
        self::assertStringNotContainsString('catch (\\Throwable', $source);
    }
}
