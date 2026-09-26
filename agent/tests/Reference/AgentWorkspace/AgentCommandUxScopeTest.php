<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCommandCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCommandParser;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\DefaultAgentExecutionOrchestrator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AgentCommandUxScopeTest extends TestCase
{
    public function test_orchestrator_passes_workspace_scopes_into_execution_context(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentExecutionOrchestrator::class))->getFileName()
        );
        $body = $this->extractMethodBody($source, 'toAgentContext');

        self::assertStringContainsString('scopes: $context->scopes', $body);
        self::assertStringNotContainsString('scopes: []', $body);
    }

    public function test_policy_fail_closed_without_required_scope(): void
    {
        $policy = (new ReflectionClass(ContentProjectAgentPolicy::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ContentProjectAgentPolicy::class, 'assertScopes');
        $method->setAccessible(true);

        $fail = $method->invoke($policy, [], 'content_project.list_projects');
        self::assertNotNull($fail);
        self::assertSame('Missing required scope: content-project:read', $fail->message);

        $ok = $method->invoke($policy, ['content-project:read'], 'content_project.list_projects');
        self::assertNull($ok);
    }

    public function test_frontend_catalog_js_exists_and_lists_project_commands(): void
    {
        $path = ProjectRoot::addonsPath().'/agent/resources/js/agent/command-catalog.js';
        self::assertFileExists($path);
        $js = (string) file_get_contents($path);

        self::assertStringContainsString('AgentCommandCatalog', $js);
        self::assertStringContainsString('agent.command-catalog.v1', $js);
        self::assertStringContainsString('/project-list', $js);
        self::assertStringContainsString('/project-view', $js);
        self::assertStringContainsString('/project-run', $js);
        self::assertStringContainsString('filterCommands', $js);
        self::assertMatchesRegularExpression('/global\.AgentCommandCatalog\s*=/', $js);
    }

    public function test_frontend_and_php_catalog_command_names_match(): void
    {
        $phpNames = array_column(AgentCliCommandCatalog::toFrontendCatalog(), 'name');
        sort($phpNames);

        $js = (string) file_get_contents(ProjectRoot::addonsPath().'/agent/resources/js/agent/command-catalog.js');
        preg_match_all("/name:\\s*'(\\/[a-z0-9-]+)'/", $js, $matches);
        $jsNames = array_values(array_unique($matches[1] ?? []));
        sort($jsNames);

        self::assertSame($phpNames, $jsNames);
    }

    public function test_blade_filters_palette_locally_without_composer_sync_on_slash(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php')
        );

        self::assertStringContainsString('resources/js/agent/command-catalog.js', $blade);
        self::assertStringContainsString('refreshLocalPalette', $blade);
        self::assertStringContainsString('paletteOpen', $blade);
        self::assertStringContainsString('_argCache', $blade);

        $inputBody = $this->extractJsMethod($blade, 'onComposerInput');
        self::assertStringContainsString('refreshLocalPalette', $inputBody);
        self::assertStringNotContainsString("\$wire.set('composerText'", $inputBody);

        $suggestBody = $this->extractJsMethod($blade, 'scheduleArgSuggest');
        self::assertStringContainsString('_argCache', $suggestBody);
        self::assertStringContainsString('getCliArgumentSuggestions', $suggestBody);
    }

    public function test_project_run_missing_project_id_does_not_parse_ok(): void
    {
        $parser = new AgentCliCommandParser();
        $parsed = $parser->parse('/project-run');
        self::assertFalse($parsed['ok']);
        self::assertSame('missing_required:project_ref', $parsed['error']);
    }

    public function test_project_view_template_contains_project_placeholder(): void
    {
        $def = AgentCliCommandCatalog::get('/project-view');
        self::assertNotNull($def);
        $template = AgentCliCommandCatalog::buildTemplate($def);
        self::assertSame('/project-view --project-id=""', $template);
    }

    public function test_catalog_search_proj_returns_project_commands_only(): void
    {
        $rows = AgentCliCommandCatalog::search('/proj');
        $commands = array_column($rows, 'command');
        self::assertContains('/project-list', $commands);
        self::assertContains('/project-view', $commands);
        self::assertNotContains('/member-list', $commands);
        self::assertNotContains('/help', $commands);
    }

    public function test_keyword_free_text_works_without_context(): void
    {
        $parser = new AgentCliCommandParser();
        $parsed = $parser->parse('/keyword-add-to-project --project-id=31 "keyword A","keyword B"', []);
        self::assertTrue($parsed['ok']);
        self::assertSame("keyword A\nkeyword B", $parsed['inputs']['items_text']);
    }

    private function extractMethodBody(string $source, string $methodName): string
    {
        if (! preg_match('/function\s+'.$methodName.'\s*\([^)]*\)[^{]*\{/', $source, $match, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $length = strlen($source);
        for ($i = $start; $i < $length; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return '';
    }

    private function extractJsMethod(string $source, string $methodName): string
    {
        if (! preg_match('/'.$methodName.':\s*function\s*\([^)]*\)\s*\{/', $source, $match, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $length = strlen($source);
        for ($i = $start; $i < $length; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return '';
    }
}
