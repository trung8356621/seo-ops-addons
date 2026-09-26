<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Http\Controllers\Api\V1\ContentProjectAgentMcpController;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Final Agent Workspace retirement guards + Content Project MCP preservation.
 */
final class AgentWorkspaceRetirementCloseContractTest extends TestCase
{
    /** @var list<string> */
    private const RETIRED_SEO_AGENT_TABLES = [
        'seo_agent_conversations',
        'seo_agent_messages',
        'seo_agent_executions',
        'seo_agent_execution_plans',
        'seo_agent_planning_runs',
        'seo_agent_packs',
        'seo_agent_pack_revisions',
        'seo_agent_pack_skills',
        'seo_agent_pack_templates',
        'seo_agent_traces',
        'seo_agent_trace_spans',
        'seo_agent_reviews',
        'seo_agent_feedback',
        'seo_agent_automations',
        'seo_agent_automation_runs',
        'seo_agent_automation_states',
        'seo_agent_automation_approvals',
        'seo_agent_metric_events',
        'seo_agent_metric_aggregates',
        'seo_agent_knowledge_items',
        'seo_agent_knowledge_chunks',
        'seo_agent_memory_proposals',
        'seo_agent_evaluation_datasets',
        'seo_agent_evaluation_cases',
        'seo_agent_evaluation_runs',
        'seo_agent_evaluation_results',
    ];

    /** @var list<string> */
    private const PRESERVED_CP_AGENT_TABLES = [
        'seo_content_project_agent_sessions',
        'seo_content_project_agent_plans',
        'seo_content_project_agent_plan_steps',
        'seo_content_project_agent_approvals',
    ];

    public function test_retirement_migration_drops_seo_agent_tables_only(): void
    {
        $path = ProjectRoot::addonsPath()
            .DIRECTORY_SEPARATOR.'agent'
            .DIRECTORY_SEPARATOR.'database'
            .DIRECTORY_SEPARATOR.'migrations'
            .DIRECTORY_SEPARATOR.'2026_09_26_100000_retire_legacy_agent_workspace_seo_agent_tables.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        foreach (self::RETIRED_SEO_AGENT_TABLES as $table) {
            self::assertStringContainsString("'".$table."'", $source, $table);
        }
        foreach (self::PRESERVED_CP_AGENT_TABLES as $table) {
            self::assertStringNotContainsString("'".$table."'", $source, $table.' must not be dropped');
        }
        self::assertStringContainsString('seo_content_project_agent_', $source); // mention in comment only
    }

    public function test_db_ownership_map_marks_seo_agent_retired_and_cp_agent_active(): void
    {
        $path = ProjectRoot::addonsPath().DIRECTORY_SEPARATOR.'DB_OWNERSHIP_MAP.json';
        self::assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json);

        $owners = $json['owners'] ?? [];
        self::assertIsArray($owners);
        self::assertContains('seo_agent_* (RETIRED)', $owners['agent'] ?? []);
        $cp = $owners['content-projects'] ?? [];
        self::assertIsArray($cp);
        $joined = implode(' ', array_map('strval', $cp));
        self::assertStringContainsString('seo_content_project_agent_', $joined);
    }

    public function test_content_project_mcp_routes_remain_registered(): void
    {
        $routes = ProjectRoot::addonsPath()
            .DIRECTORY_SEPARATOR.'content-projects'
            .DIRECTORY_SEPARATOR.'routes'
            .DIRECTORY_SEPARATOR.'api-v1.php';
        self::assertFileExists($routes);
        $source = (string) file_get_contents($routes);

        self::assertStringContainsString("/agent/mcp/tools", $source);
        self::assertStringContainsString("/agent/mcp/call", $source);
        self::assertStringContainsString("/agent/execute", $source);
        self::assertStringContainsString("/agent/sessions", $source);
        self::assertStringContainsString('ContentProjectAgentMcpController', $source);
        self::assertTrue(class_exists(ContentProjectAgentMcpController::class));
        self::assertTrue(class_exists(ContentProjectAgentGateway::class));
    }

    public function test_cleanup_content_project_agent_plans_command_is_preserved(): void
    {
        $path = ProjectRoot::addonsPath()
            .DIRECTORY_SEPARATOR.'content-projects'
            .DIRECTORY_SEPARATOR.'src'
            .DIRECTORY_SEPARATOR.'Console'
            .DIRECTORY_SEPARATOR.'CleanupContentProjectAgentPlansCommand.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('seo:content-project:cleanup-agent-plans', $source);
    }

    public function test_compat_does_not_register_agent_workspace_runtime(): void
    {
        $provider = ProjectRoot::addonsPath()
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'SeoContentAiServiceProvider.php';
        $source = (string) file_get_contents($provider);

        self::assertStringNotContainsString('Services\\AgentWorkspace\\', $source);
        self::assertStringNotContainsString('DispatchDueAgentAutomationsCommand', $source);
        self::assertStringNotContainsString('agent-automations-dispatch-due', $source);
        // Extension + Business Hook remain transitional shared infra
        self::assertStringContainsString('registerExtensionSdk', $source);
        self::assertStringContainsString('BusinessHookEmitter', $source);
    }

    public function test_agent_readme_declares_legacy_reference_only(): void
    {
        $readme = ProjectRoot::addonsPath().DIRECTORY_SEPARATOR.'agent'.DIRECTORY_SEPARATOR.'README.md';
        self::assertFileExists($readme);
        $body = (string) file_get_contents($readme);
        self::assertStringContainsString('LEGACY / REFERENCE-ONLY', $body);
        self::assertStringContainsString('Extension/', $body);
        self::assertStringContainsString('transitional', strtolower($body));
    }

    public function test_peer_src_has_no_agent_workspace_product_imports(): void
    {
        $needles = [
            'Omnichannel\\Addons\\Agent\\Services\\AgentWorkspace\\',
            'Omnichannel\\Addons\\Agent\\Models\\AgentWorkspace\\',
            'AgentWorkspacePage',
            'AgentWorkspaceDeepLink',
        ];
        $scopes = [
            'content/src',
            'content-projects/src',
            'seo/src',
            'search-foundation/src',
            'search-intelligence/src',
            'publishing/src',
            'wordpress/src',
            'commerce/src',
            'ai-prompt/src',
            'seo-content-ai-compat',
        ];
        $hits = [];
        $root = ProjectRoot::addonsPath();
        foreach ($scopes as $rel) {
            $base = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (! is_dir($base) && ! is_file($base)) {
                continue;
            }
            $iterator = is_file($base)
                ? [$base]
                : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
            foreach ($iterator as $file) {
                $path = is_string($file) ? $file : (string) $file->getPathname();
                if (! str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
                    continue;
                }
                if (str_contains($path, DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                if (str_contains($path, 'agent-workspace')) {
                    continue;
                }
                $contents = (string) file_get_contents($path);
                foreach ($needles as $needle) {
                    if (str_contains($contents, $needle)) {
                        $hits[] = str_replace($root.DIRECTORY_SEPARATOR, '', $path).' → '.$needle;
                    }
                }
            }
        }
        self::assertSame([], $hits, implode("\n", $hits));
    }
}
