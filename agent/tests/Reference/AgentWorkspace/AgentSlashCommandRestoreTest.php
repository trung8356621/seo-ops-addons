<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\GeneralDomain;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\ViewDomainMcp;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCommandCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCommandParser;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\DefaultAgentExecutionOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\ProjectDetailPresenter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\ProjectListPresenter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering\ContentProjectResultRenderer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\ContentProjectSkills;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CapabilityKind;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AgentSlashCommandRestoreTest extends TestCase
{
    public function test_project_list_skill_is_read_without_confirmation(): void
    {
        $defs = ContentProjectSkills::definitions();
        $list = null;
        foreach ($defs as $def) {
            if (($def['key'] ?? '') === 'content_project.list') {
                $list = $def;
                break;
            }
        }
        self::assertNotNull($list);
        self::assertSame('content_project.list_projects', $list['capability']);
        self::assertSame('none', $list['confirmation_policy'] ?? 'none');
    }

    public function test_cli_project_list_maps_to_list_skill(): void
    {
        $parsed = (new AgentCliCommandParser)->parse('/project-list');
        self::assertTrue($parsed['ok'] ?? false);
        self::assertSame('content_project.list', $parsed['skill_key'] ?? null);
        self::assertFalse($parsed['is_meta'] ?? true);
    }

    public function test_project_view_and_run_require_project_id(): void
    {
        $parser = new AgentCliCommandParser;
        $view = $parser->parse('/project-view');
        self::assertFalse($view['ok'] ?? true);
        self::assertStringStartsWith('missing_required:', (string) ($view['error'] ?? ''));

        $run = $parser->parse('/project-run');
        self::assertFalse($run['ok'] ?? true);
        self::assertStringStartsWith('missing_required:', (string) ($run['error'] ?? ''));

        $ok = $parser->parse('/project-run --project-id=31');
        self::assertTrue($ok['ok'] ?? false);
        self::assertArrayHasKey('project_ref', $ok['inputs'] ?? []);
    }

    public function test_no_content_project_alias_in_cli_catalog(): void
    {
        $commands = array_column(AgentCliCommandCatalog::all(), 'command');
        self::assertNotContains('/content-project', $commands);
        self::assertContains('/project-list', $commands);
    }

    public function test_submit_composer_prioritizes_slash_over_pending_confirmation(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName()
        );
        $body = $this->extractMethodBody($source, 'submitComposer');

        $slashPos = strpos($body, 'tryHandleCliComposer');
        $awaitPos = strpos($body, 'STATE_AWAITING_CONFIRMATION');
        self::assertNotFalse($slashPos);
        self::assertNotFalse($awaitPos);
        self::assertLessThan($awaitPos, $slashPos);

        $answerBody = $this->extractMethodBody($source, 'answerConversation');
        self::assertStringContainsString("str_starts_with(ltrim(\$value), '/')", $answerBody);
        self::assertStringContainsString('pendingConfirmationToken', $answerBody);
    }

    public function test_select_skill_clears_pending_confirmation_token(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName()
        );
        $body = $this->extractMethodBody($source, 'selectSkill');
        self::assertStringContainsString('pendingConfirmationToken = null', $body);
    }

    public function test_orchestrator_skips_preview_card_for_read_policy(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentExecutionOrchestrator::class))->getFileName()
        );
        self::assertStringContainsString('if ($requiresConfirmation || ! $executable)', $source);
        self::assertStringContainsString('hide_envelope', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
    }

    public function test_gateway_read_message_is_not_read_successful(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentGateway::class))->getFileName()
        );
        self::assertStringNotContainsString("'Read successful.'", $source);
        self::assertStringContainsString("AgentCapabilityResult::ok('agent.read_ok', '', \$data)", $source);
    }

    public function test_project_list_presenter_omits_internal_refs(): void
    {
        $presented = (new ProjectListPresenter)->present([
            'projects' => [[
                'project_id' => 32,
                'name' => 'Sản phẩm tháng 8',
                'month' => '08/2026',
                'member_name' => 'Trần Văn B',
                'archived' => false,
                'stats' => ['total_items' => 15],
                'site_ref' => 'cps_x',
                'tenant_ref' => 'tenant:cps_x',
            ]],
        ]);

        self::assertStringContainsString('[32] Sản phẩm tháng 8', $presented['summary']);
        self::assertStringContainsString('Status: Active | Items: 15', $presented['summary']);
        self::assertStringNotContainsString('Member:', $presented['summary']);
        self::assertStringNotContainsString('site_ref', $presented['summary']);
        self::assertStringNotContainsString('tenant_ref', $presented['summary']);
        self::assertStringNotContainsString('operation_id', $presented['summary']);
        self::assertTrue($presented['hide_envelope']);
    }

    public function test_project_detail_presenter_business_only(): void
    {
        $presented = (new ProjectDetailPresenter)->present([
            'project_id' => 31,
            'name' => 'Blog tháng 8',
            'month' => '2026-08-01',
            'phase' => 'draft',
            'phase_counts' => ['draft' => 5, 'review' => 2],
            'site_ref' => 'cps_hidden',
        ]);

        self::assertStringContainsString('31 — Blog tháng 8', $presented['summary']);
        self::assertStringNotContainsString('cps_hidden', $presented['summary']);
        self::assertStringNotContainsString('site_ref', $presented['summary']);
    }

    public function test_empty_project_list_message(): void
    {
        $presented = (new ProjectListPresenter)->present(['projects' => []]);
        self::assertSame('Chưa có Content Project cho website hiện tại.', $presented['summary']);
    }

    public function test_execution_card_hides_envelope_for_user_facing(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/partials/agent-execution-card.blade.php')
        );
        self::assertStringContainsString('hide_envelope', $blade);
        self::assertStringContainsString('whitespace-pre-wrap', $blade);
    }

    public function test_mcp_page_is_developer_global_reference(): void
    {
        $page = (string) file_get_contents((new ReflectionClass(ViewDomainMcp::class))->getFileName());
        $view = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/domain-resource/pages/view-domain-mcp.blade.php')
        );
        $resource = (string) file_get_contents((new ReflectionClass(DomainResource::class))->getFileName());
        $general = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $generalView = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/domain-resource/pages/general-domain.blade.php')
        );

        self::assertStringContainsString("route('/{record}/mcp')", $resource);
        self::assertStringContainsString('canAccessManagerFeatures', $page);
        self::assertStringContainsString('Developer MCP Reference', $page);
        self::assertStringContainsString('Global MCP system-action catalog', $view);
        self::assertStringContainsString('Not WordPress site-feature', $view);
        self::assertStringNotContainsString('McpCapabilityMarkdownPresenter', $general);
        self::assertStringNotContainsString('mcpCapabilityDoc', $generalView);
    }

    public function test_mcp_list_projects_metadata_is_read_only(): void
    {
        $catalog = (new ReflectionClass(ContentProjectMcpToolCatalog::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ContentProjectMcpToolCatalog::class, 'readToolDefinitions');
        $method->setAccessible(true);
        /** @var list<array<string, mixed>> $tools */
        $tools = $method->invoke($catalog);

        $list = null;
        foreach ($tools as $tool) {
            if (($tool['name'] ?? '') === 'content_project.list_projects') {
                $list = $tool;
                break;
            }
        }
        self::assertNotNull($list);
        self::assertSame('none', $list['side_effects'] ?? null);
        self::assertSame('none', $list['confirmation_policy'] ?? null);
        self::assertSame('read', $list['mode'] ?? null);
        self::assertContains('site_ref', $list['inputSchema']['required'] ?? []);
        self::assertStringContainsString('Read-only', (string) ($list['description'] ?? ''));
        self::assertStringContainsString('Do not use this capability to create', (string) ($list['description'] ?? ''));
    }

    public function test_content_project_generate_requires_project_context(): void
    {
        $cap = (new ContentProjectCapabilityRegistry)->get('content_project.generate');
        self::assertNotNull($cap);
        self::assertContains('project_ref', $cap['required_context'] ?? []);
        self::assertContains('site_ref', $cap['required_context'] ?? []);
        self::assertSame('write', $cap['side_effect_level'] ?? null);
        self::assertStringContainsString('never select a project implicitly', (string) ($cap['description'] ?? ''));
    }

    public function test_site_features_are_not_callable_mcp_actions(): void
    {
        self::assertSame(CapabilityKind::SITE_FEATURE, CapabilityKind::classify('seo_score'));
        self::assertContains('seo_score', SiteSyncSchema::CAPABILITY_KEYS);

        foreach (SiteSyncSchema::CAPABILITY_KEYS as $key) {
            self::assertTrue(CapabilityKind::isSiteFeatureKey($key));
            self::assertSame(CapabilityKind::SITE_FEATURE, CapabilityKind::classify($key));
            self::assertNull((new ContentProjectCapabilityRegistry)->get($key));
        }
    }

    public function test_project_view_missing_id_instruction_in_page(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName()
        );
        // PHP source escapes quotes inside double-quoted strings as \"
        self::assertStringContainsString('/project-view --project-id=\"\"', $source);
        self::assertStringContainsString('Thiếu project.', $source);
        self::assertStringContainsString('/project-run --project-id=\"\"', $source);
    }

    public function test_generic_envelope_not_used_for_list_capability(): void
    {
        $result = (new \Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult(
            executionRef: 'aex_1',
            status: \Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentExecutionStatus::Succeeded,
            ok: true,
            code: 'ok',
            message: 'Read successful.',
            skillKey: 'content_project.list',
            capabilityKey: 'content_project.list_projects',
            data: ['projects' => []],
            operationReference: 'op_should_not_show',
        ));
        $rendered = (new ContentProjectResultRenderer)->render($result);
        self::assertTrue($rendered['hide_envelope'] ?? false);
        self::assertTrue(($rendered['operation_reference'] ?? null) === null);
        self::assertStringNotContainsString('op_should_not_show', $rendered['summary']);
        self::assertStringNotContainsString('Read successful', $rendered['summary']);
        self::assertStringNotContainsString('ready', strtolower($rendered['summary']));
        self::assertStringNotContainsString('succeeded', strtolower($rendered['summary']));
    }

    private function extractMethodBody(string $source, string $method): string
    {
        $pattern = '/function\s+'.preg_quote($method, '/').'\s*\([^{]*\{/';
        if (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            self::fail('Method not found: '.$method);
        }
        $start = (int) $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $len = strlen($source);
        for ($i = $start; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail('Unbalanced braces for '.$method);

        return '';
    }
}
