<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionCapabilityRegistry;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\GeneralDomain;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectOperationsCenter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentErrorCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\McpCapabilityMarkdownPresenter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CapabilityContextGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CapabilityKind;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class McpCapabilityMarkdownPresenterTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname((new ReflectionClass(McpCapabilityMarkdownPresenter::class))->getFileName(), 5);
    }

    private function addonView(string $relativePath): string
    {
        return $this->addonRoot().'/resources/views/'.$relativePath;
    }

    public function test_domain_general_does_not_render_mcp_markdown(): void
    {
        $generalSource = (string) file_get_contents(
            (new ReflectionClass(GeneralDomain::class))->getFileName(),
        );
        $domainGeneralView = (string) file_get_contents($this->addonView(
            'filament/resources/domain-resource/pages/general-domain.blade.php',
        ));

        self::assertStringNotContainsString('McpCapabilityMarkdownPresenter', $generalSource);
        self::assertStringNotContainsString('mcpCapabilityDoc', $generalSource);
        self::assertStringNotContainsString('loadMcpCapabilityDoc', $generalSource);
        self::assertStringNotContainsString('MCP Markdown', $domainGeneralView);
        self::assertStringNotContainsString('mcpCapabilityDoc', $domainGeneralView);

        // Button on General → /domains/{id}/mcp
        self::assertStringContainsString("getUrl('mcp'", $generalSource);
        self::assertStringContainsString('view_mcp', $generalSource);

        // Site-specific Domain General surface still present.
        self::assertStringContainsString('seo-domain-overview', $domainGeneralView);
        self::assertStringContainsString('domain-sync-actions', $domainGeneralView);
        self::assertStringContainsString('Chấm điểm SEO', $domainGeneralView);
        self::assertStringContainsString('Thống kê đồng bộ', $domainGeneralView);
    }

    public function test_domain_mcp_page_renders_mcp_markdown(): void
    {
        $pageSource = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\ViewDomainMcp::class))->getFileName(),
        );
        $resourceSource = (string) file_get_contents(
            (new ReflectionClass(DomainResource::class))->getFileName(),
        );
        $view = (string) file_get_contents($this->addonView(
            'filament/resources/domain-resource/pages/view-domain-mcp.blade.php',
        ));

        self::assertStringContainsString("route('/{record}/mcp')", $resourceSource);
        self::assertStringContainsString('McpCapabilityMarkdownPresenter', $pageSource);
        self::assertStringContainsString('SimpleMarkdownHtmlConverter', $pageSource);
        self::assertStringContainsString('mcpCapabilityDoc', $pageSource);
        self::assertStringContainsString('mcpHtml', $pageSource);
        self::assertStringContainsString('Developer MCP Reference', $pageSource);
        self::assertStringContainsString('Global MCP system-action catalog', $view);
        self::assertStringContainsString('{!! $mcpHtml !!}', $view);
        self::assertStringContainsString('View raw Markdown', $view);
        self::assertStringContainsString('seo-mcp-doc', $view);
        self::assertStringNotContainsString('filtered()', $view);
        self::assertStringNotContainsString('toggle(row.key)', $view);
        self::assertStringContainsString('canAccessManagerFeatures', $pageSource);
        self::assertFileExists($this->addonView(
            'filament/resources/domain-resource/pages/view-domain-mcp.blade.php',
        ));
    }

    public function test_ops_runtime_hosts_global_mcp_reference(): void
    {
        $opsSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectOperationsCenter::class))->getFileName(),
        );
        $opsView = (string) file_get_contents($this->addonView(
            'filament/pages/content-project-operations-center.blade.php',
        ));

        self::assertStringContainsString('McpCapabilityMarkdownPresenter', $opsSource);
        self::assertStringContainsString('loadMcpCapabilityDoc', $opsSource);
        self::assertStringContainsString('mcpCapabilityDoc', $opsSource);
        self::assertStringContainsString('MCP Reference', $opsView);
        self::assertStringContainsString('CanonicalCapabilityRegistry', $opsView);
        self::assertStringContainsString("tab === 'runtime'", $opsView);
    }

    public function test_present_reads_from_registry_not_hardcoded_site_list(): void
    {
        $presenterSource = (string) file_get_contents(
            (new ReflectionClass(McpCapabilityMarkdownPresenter::class))->getFileName(),
        );

        self::assertStringContainsString('registry->all()', $presenterSource);
        self::assertStringNotContainsString("'site.discover', 'site.sync'", $presenterSource);
        self::assertStringContainsString('not bound to a Domain General', $presenterSource);
        self::assertStringContainsString('Not bound to any Domain General page', $presenterSource);
    }

    public function test_mcp_contract_independent_of_frontend_route(): void
    {
        $presenterSource = (string) file_get_contents(
            (new ReflectionClass(McpCapabilityMarkdownPresenter::class))->getFileName(),
        );
        $registrySource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectCapabilityRegistry::class))->getFileName(),
        );

        self::assertStringNotContainsString('/domains/', $presenterSource);
        self::assertStringNotContainsString('/general', $presenterSource);
        self::assertStringNotContainsString('request()->', $registrySource);
        self::assertStringNotContainsString('DomainResource', $registrySource);
    }

    public function test_registered_capabilities_appear_in_markdown(): void
    {
        $presenter = $this->presenter();
        $doc = $presenter->present(includeInternal: true, filter: McpCapabilityMarkdownPresenter::FILTER_SITE_SYNC);
        $names = array_map(
            static fn (array $row): string => (string) $row['name'],
            array_merge($doc['items'], $doc['internal_items']),
        );

        self::assertContains('site.discover', $names);
        self::assertContains('site.sync', $names);
        self::assertStringContainsString('### site.discover', $doc['markdown']);
        self::assertStringContainsString('### site.sync', $doc['markdown']);
        self::assertStringContainsString('Kind: system_action', $doc['markdown']);
        self::assertStringContainsString('Required context: site_ref', $doc['markdown']);
        self::assertStringContainsString('site_feature', $doc['markdown']);
    }

    public function test_content_project_capabilities_from_registry_only(): void
    {
        $doc = $this->presenter()->present(
            includeInternal: false,
            filter: McpCapabilityMarkdownPresenter::FILTER_CONTENT_PROJECT,
        );
        $names = array_column($doc['items'], 'name');

        self::assertContains('content_project.create', $names);
        self::assertNotContains('content_project.list', $names);
        self::assertNotContains('content_project.get', $names);
    }

    public function test_content_project_create_is_system_action_with_site_context(): void
    {
        $cap = (new ContentProjectCapabilityRegistry)->get('content_project.create');
        self::assertNotNull($cap);
        self::assertSame(CapabilityKind::SYSTEM_ACTION, $cap['capability_kind'] ?? null);
        self::assertSame('content_project', $cap['action_domain'] ?? null);
        self::assertContains('site_ref', $cap['required_context'] ?? []);
        self::assertSame('write', $cap['side_effect_level'] ?? null);
        self::assertStringContainsString('explicitly supplied site context', (string) ($cap['description'] ?? ''));
        self::assertStringContainsString('does not create, discover, or switch sites', (string) ($cap['description'] ?? ''));
    }

    public function test_site_feature_keys_classified_separately_from_mcp_actions(): void
    {
        self::assertSame(CapabilityKind::SITE_FEATURE, CapabilityKind::classify('seo_score'));
        self::assertSame(CapabilityKind::SITE_FEATURE, CapabilityKind::classify('focus_keyword'));
        self::assertSame(CapabilityKind::SYSTEM_ACTION, CapabilityKind::classify('site.sync'));
        self::assertSame(CapabilityKind::SYSTEM_ACTION, CapabilityKind::classify('content_project.create'));

        foreach (SiteSyncSchema::CAPABILITY_KEYS as $key) {
            self::assertTrue(CapabilityKind::isSiteFeatureKey($key));
            self::assertNull((new ContentProjectCapabilityRegistry)->get($key));
        }

        $create = (new ContentProjectCapabilityRegistry)->get('content_project.create');
        self::assertNotNull($create);
        self::assertNotSame(CapabilityKind::SITE_FEATURE, $create['capability_kind'] ?? null);
    }

    public function test_site_sync_missing_site_ref_fail_closed(): void
    {
        $cap = (new ContentProjectCapabilityRegistry)->get('site.sync');
        self::assertNotNull($cap);

        $result = (new CapabilityContextGuard)->assert($cap, [
            'site_ref' => '',
            'tenant_ref' => 'tenant:demo',
        ], []);

        self::assertNotNull($result);
        self::assertFalse($result->success);
        self::assertSame(AgentErrorCodes::MISSING_REQUIRED_CONTEXT, $result->code);
        self::assertSame(['site_ref'], $result->data['required'] ?? null);
    }

    public function test_content_project_action_missing_project_ref_fail_closed(): void
    {
        $cap = (new ContentProjectCapabilityRegistry)->get('content_project.generate');
        self::assertNotNull($cap);
        self::assertContains('project_ref', $cap['required_context'] ?? []);

        $result = (new CapabilityContextGuard)->assert($cap, [
            'site_ref' => 'cps_demo',
            'tenant_ref' => 'tenant:cps_demo',
        ], []);

        self::assertNotNull($result);
        self::assertSame(AgentErrorCodes::MISSING_REQUIRED_CONTEXT, $result->code);
        self::assertContains('project_ref', $result->data['required'] ?? []);
    }

    public function test_agent_does_not_default_site_when_context_missing(): void
    {
        $guardSource = (string) file_get_contents(
            (new ReflectionClass(CapabilityContextGuard::class))->getFileName(),
        );
        $gatewaySource = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway::class))->getFileName(),
        );

        self::assertStringContainsString('MISSING_REQUIRED_CONTEXT', $gatewaySource);
        self::assertStringContainsString('Never infers site/project', $guardSource);
        self::assertStringNotContainsString('accessibleSiteIds()[0]', $gatewaySource);
        self::assertStringNotContainsString('first()', $guardSource);
        self::assertStringNotContainsString('default site', strtolower($guardSource));
    }

    public function test_context_mismatch_error_code_exists_for_project_site_relation(): void
    {
        $policySource = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentPolicy::class))->getFileName(),
        );

        self::assertStringContainsString('CONTEXT_MISMATCH', $policySource);
        self::assertStringContainsString('Project does not belong to site context.', $policySource);
        self::assertSame('context_mismatch', AgentErrorCodes::CONTEXT_MISMATCH);
        self::assertSame('missing_required_context', AgentErrorCodes::MISSING_REQUIRED_CONTEXT);
    }

    public function test_disabled_capability_shows_disabled_status(): void
    {
        $doc = $this->presenter()->presentFromDefinitions(
            [[
                'name' => 'demo.disabled',
                'description' => 'Disabled demo',
                'risk_level' => 'read',
                'read_only' => true,
                'enabled' => false,
                'capability_kind' => CapabilityKind::SYSTEM_ACTION,
                'action_domain' => 'demo',
                'required_context' => ['site_ref'],
                'side_effect_level' => 'none',
                'scopes' => ['demo:read'],
                'input_summary' => ['site_id'],
                'output_summary' => ['ok'],
                'examples' => ['Demo'],
                'confirmation_requirement' => false,
            ]],
            mcpToolNames: [],
            includeInternal: false,
            filter: McpCapabilityMarkdownPresenter::FILTER_ALL,
        );

        self::assertSame(['disabled'], $doc['items'][0]['status']);
        self::assertNotContains('exposed-to-agent', $doc['items'][0]['status']);
        self::assertNotContains('exposed-to-mcp', $doc['items'][0]['status']);
        self::assertStringContainsString('disabled', $doc['markdown']);
        self::assertStringContainsString('Kind: system_action', $doc['markdown']);
    }

    public function test_internal_hidden_from_regular_user_visible_to_manager(): void
    {
        $caps = [[
            'name' => 'demo.public',
            'description' => 'Public',
            'risk_level' => 'read',
            'read_only' => true,
            'internal' => false,
            'enabled' => true,
            'scopes' => ['demo:read'],
            'confirmation_requirement' => false,
        ], [
            'name' => 'demo.internal',
            'description' => 'Internal only',
            'risk_level' => 'write',
            'read_only' => false,
            'internal' => true,
            'visibility' => 'internal',
            'enabled' => true,
            'scopes' => ['demo:admin'],
            'confirmation_requirement' => true,
            'confirmation_note' => 'Có',
        ]];

        $regular = $this->presenter()->presentFromDefinitions($caps, [], includeInternal: false);
        $manager = $this->presenter()->presentFromDefinitions($caps, [], includeInternal: true);

        self::assertCount(1, $regular['items']);
        self::assertSame('demo.public', $regular['items'][0]['name']);
        self::assertSame([], $regular['internal_items']);
        self::assertStringNotContainsString('demo.internal', $regular['markdown']);

        self::assertCount(1, $manager['items']);
        self::assertCount(1, $manager['internal_items']);
        self::assertSame('demo.internal', $manager['internal_items'][0]['name']);
        self::assertStringContainsString('## Internal capabilities', $manager['markdown']);
        // Heading level follows presenter markdown (### name).
        self::assertMatchesRegularExpression('/^###\s+demo\.internal\s*$/m', $manager['markdown']);
    }

    public function test_read_write_confirmation_metadata(): void
    {
        $doc = $this->presenter()->presentFromDefinitions(
            [[
                'name' => 'demo.write',
                'description' => 'Write with confirm',
                'risk_level' => 'write',
                'read_only' => false,
                'enabled' => true,
                'scopes' => ['demo:write'],
                'confirmation_requirement' => true,
                'confirmation_modes' => ['confirm'],
                'confirmation_note' => 'Có',
                'input_summary' => ['project_ref'],
                'output_summary' => ['operation_id'],
                'examples' => ['Chạy demo'],
                'agent_exposed' => true,
                'mcp_exposed' => true,
            ], [
                'name' => 'demo.read',
                'description' => 'Read only',
                'risk_level' => 'read',
                'read_only' => true,
                'enabled' => true,
                'scopes' => ['demo:read'],
                'confirmation_requirement' => false,
                'input_summary' => ['project_ref'],
                'agent_exposed' => true,
                'mcp_exposed' => false,
            ]],
            mcpToolNames: ['demo.write' => true],
            includeInternal: false,
        );

        $byName = [];
        foreach ($doc['items'] as $item) {
            $byName[$item['name']] = $item;
        }

        self::assertSame('Write', $byName['demo.write']['type']);
        self::assertTrue($byName['demo.write']['confirmation']);
        self::assertSame('Có', $byName['demo.write']['confirmation_policy']);
        self::assertContains('exposed-to-mcp', $byName['demo.write']['status']);
        self::assertContains('exposed-to-agent', $byName['demo.write']['status']);

        self::assertSame('Read', $byName['demo.read']['type']);
        self::assertFalse($byName['demo.read']['confirmation']);
        self::assertNotContains('exposed-to-mcp', $byName['demo.read']['status']);
    }

    public function test_copy_markdown_contains_registered_names_and_omits_secrets(): void
    {
        $doc = $this->presenter()->present(includeInternal: false, filter: McpCapabilityMarkdownPresenter::FILTER_ALL);

        self::assertStringContainsString('# MCP Capabilities', $doc['markdown']);
        self::assertStringContainsString('content_project.create', $doc['markdown']);
        self::assertStringContainsString('Kind: system_action', $doc['markdown']);
        self::assertStringNotContainsString("'handler'", $doc['markdown']);
        self::assertStringNotContainsString('api_key', $doc['markdown']);
        self::assertStringNotContainsString('password', $doc['markdown']);

        foreach ($doc['items'] as $item) {
            self::assertArrayNotHasKey('handler', $item);
            self::assertArrayNotHasKey('input_schema', $item);
        }
    }

    public function test_new_capability_appears_without_page_change(): void
    {
        $base = $this->presenter()->presentFromDefinitions([], [], includeInternal: false);
        self::assertSame(0, $base['count']);

        $withNew = $this->presenter()->presentFromDefinitions(
            [[
                'name' => 'future.brand_new',
                'description' => 'Appears automatically',
                'risk_level' => 'read',
                'read_only' => true,
                'enabled' => true,
                'scopes' => ['future:read'],
                'confirmation_requirement' => false,
                'examples' => ['New cap'],
            ]],
            mcpToolNames: ['future.brand_new' => true],
            includeInternal: false,
        );

        self::assertSame(1, $withNew['count']);
        self::assertSame('future.brand_new', $withNew['items'][0]['name']);
        self::assertStringContainsString('### future.brand_new', $withNew['markdown']);
    }

    public function test_removed_capability_disappears(): void
    {
        $caps = [[
            'name' => 'temp.one',
            'description' => 'Temp',
            'risk_level' => 'read',
            'read_only' => true,
            'enabled' => true,
            'confirmation_requirement' => false,
        ]];

        $before = $this->presenter()->presentFromDefinitions($caps, [], includeInternal: false);
        $after = $this->presenter()->presentFromDefinitions([], [], includeInternal: false);

        self::assertStringContainsString('temp.one', $before['markdown']);
        self::assertStringNotContainsString('temp.one', $after['markdown']);
    }

    public function test_site_sync_presentation_metadata_on_registry(): void
    {
        $cap = (new ContentProjectCapabilityRegistry)->get('site.discover');
        self::assertNotNull($cap);
        self::assertTrue((bool) ($cap['read_only'] ?? false));
        self::assertSame(['site:read'], $cap['scopes'] ?? null);
        self::assertContains('site_ref', $cap['required_context'] ?? []);
        self::assertContains('site profile', $cap['output_summary'] ?? []);

        $sync = (new ContentProjectCapabilityRegistry)->get('site.sync');
        self::assertNotNull($sync);
        self::assertFalse((bool) ($sync['confirmation_requirement'] ?? true));
        self::assertContains('force_full', $sync['confirmation_modes'] ?? []);
        self::assertSame('Có khi dùng `force_full`', $sync['confirmation_note'] ?? null);
        self::assertSame(['site_ref'], $sync['required_context'] ?? null);
        self::assertStringContainsString('explicitly supplied WordPress site', (string) ($sync['description'] ?? ''));
    }

    public function test_global_registry_still_exists_and_gateway_unchanged_for_command_bus(): void
    {
        $registry = new ContentProjectCapabilityRegistry;
        self::assertNotNull($registry->get('content_project.create'));
        self::assertNotNull($registry->get('site.sync'));

        $gatewaySource = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway::class))->getFileName(),
        );
        self::assertStringContainsString('commandBus->dispatch', $gatewaySource);
        self::assertStringContainsString('CapabilityContextGuard', $gatewaySource);
    }

    public function test_agent_general_block_guards_and_presenter_wiring(): void
    {
        $agentPageSource = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );
        $generalSource = (string) file_get_contents(
            (new ReflectionClass(GeneralDomain::class))->getFileName(),
        );
        $resourceSource = (string) file_get_contents(
            (new ReflectionClass(DomainResource::class))->getFileName(),
        );

        self::assertStringNotContainsString('McpCapabilityMarkdownPresenter', $agentPageSource);
        self::assertStringNotContainsString('mcpCapabilityDoc', $agentPageSource);

        self::assertStringNotContainsString('viewMcpCapabilitiesAction', $generalSource);
        self::assertStringContainsString("'mcp'", $resourceSource);
        self::assertStringNotContainsString('McpCapabilityMarkdownPresenter', $generalSource);
        self::assertStringNotContainsString('mcpCapabilityDoc', $generalSource);
        self::assertStringContainsString('view_mcp', $generalSource);

        self::assertFileExists($this->addonView(
            'filament/resources/domain-resource/pages/view-domain-mcp.blade.php',
        ));

        $domainGeneralView = (string) file_get_contents($this->addonView(
            'filament/resources/domain-resource/pages/general-domain.blade.php',
        ));
        self::assertStringNotContainsString('MCP Markdown', $domainGeneralView);

        self::assertFileDoesNotExist($this->addonView(
            'filament/pages/partials/agent-workspace/general-panel.blade.php',
        ));
    }

    private function presenter(): McpCapabilityMarkdownPresenter
    {
        $registry = new CanonicalCapabilityRegistry(
            new ContentProjectCapabilityRegistry,
            new ExtensionCapabilityRegistry,
            new ExtensionStateStore,
        );

        return new McpCapabilityMarkdownPresenter(
            $registry,
            new ContentProjectMcpToolCatalog($registry),
        );
    }
}
