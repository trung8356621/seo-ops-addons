<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\ViewDomainMcp;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentMessageOutputSanitizer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCommandCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCommandParser;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\MemberListPresenter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\SiteInfoPresenter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectSiteHealthService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentMcpSiteCliFixTest extends TestCase
{
    public function test_mcp_page_is_simple_markdown_documentation(): void
    {
        $page = (string) file_get_contents((new ReflectionClass(ViewDomainMcp::class))->getFileName());
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/domain-resource/pages/view-domain-mcp.blade.php')
        );

        self::assertStringContainsString('McpCapabilityMarkdownPresenter', $page);
        self::assertStringContainsString('SimpleMarkdownHtmlConverter', $page);
        self::assertStringContainsString('mcpCapabilityDoc', $page);
        self::assertStringContainsString('mcpHtml', $page);
        self::assertStringContainsString('{!! $mcpHtml !!}', $blade);
        self::assertStringContainsString('View raw Markdown', $blade);
        self::assertStringContainsString('Global MCP system-action catalog', $blade);
        self::assertStringNotContainsString('filtered()', $blade);
        self::assertStringNotContainsString('toggle(row.key)', $blade);
        self::assertStringNotContainsString('McpCapabilityCatalogPresenter', $page);
        // Primary view is HTML docs â€” raw <pre> only behind optional toggle.
        self::assertStringContainsString('x-show="showRaw"', $blade);
    }

    public function test_sanitizer_removes_livewire_block_markers(): void
    {
        $sanitizer = new AgentMessageOutputSanitizer();
        $raw = "Hello\n<!--[if BLOCK]><![endif]-->\nWorld\n<!--[if ENDBLOCK]><![endif]-->\nDone";
        $clean = $sanitizer->sanitize($raw);

        self::assertNotNull($clean);
        self::assertStringNotContainsString('ENDBLOCK', $clean);
        self::assertStringNotContainsString('if BLOCK', $clean);
        self::assertStringContainsString('Hello', $clean);
        self::assertStringContainsString('World', $clean);
        self::assertStringContainsString('Done', $clean);
        self::assertDoesNotMatchRegularExpression("/\n{3,}/", $clean);
    }

    public function test_message_refresh_and_append_use_sanitizer(): void
    {
        $page = (string) file_get_contents((new ReflectionClass(AgentWorkspacePage::class))->getFileName());
        $conv = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentConversationService::class))->getFileName()
        );

        self::assertStringContainsString('AgentMessageOutputSanitizer', $page);
        self::assertStringContainsString('AgentMessageOutputSanitizer', $conv);
        self::assertStringContainsString('<?php if (($message[\'role\'] ?? \'\') !== \'user\'): ?>', (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php')
        ));
    }

    public function test_member_commands_use_member_id_not_name(): void
    {
        $create = AgentCliCommandCatalog::get('/project-create');
        self::assertNotNull($create);
        self::assertStringContainsString('--member-id=', $create['example']);
        self::assertStringNotContainsString('--member=""', $create['example']);

        $flags = [];
        foreach ($create['args'] as $arg) {
            if (($arg['key'] ?? '') === 'assignee_ref') {
                $flags = $arg['flags'];
            }
        }
        self::assertContains('--member-id', $flags);

        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php')
        );
        self::assertStringContainsString('--member-id|--member', $blade);

        $presented = (new MemberListPresenter)->present([
            ['id' => 15, 'email' => 'trang@example.com', 'name' => 'Trang Nguyá»…n', 'available' => true],
        ], true);
        self::assertStringContainsString('ID: 15', (string) ($presented['summary'] ?? ''));
        self::assertStringContainsString('trang@example.com', (string) ($presented['summary'] ?? ''));

        $parser = new AgentCliCommandParser();
        $bad = $parser->parse('/project-create --name="X" --month="08/2026" --member-id="Trang Nguyá»…n"');
        self::assertFalse($bad['ok'] ?? true);
        self::assertSame('member_ref_must_be_id_or_email', $bad['error'] ?? null);

        $ok = $parser->parse('/project-create --name="X" --month="08/2026" --member-id="15"');
        self::assertTrue($ok['ok'] ?? false);
        self::assertSame('15', $ok['inputs']['assignee_ref'] ?? null);
    }

    public function test_site_health_service_no_longer_hardcodes_unknown(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectSiteHealthService::class))->getFileName()
        );
        self::assertStringContainsString('seo_site_sync_runs', $source);
        self::assertStringContainsString('deriveWpReachable', $source);
        self::assertStringContainsString('never_checked', $source);
        self::assertStringNotContainsString("'wp_reachable' => 'unknown',\n                'token_ok' => 'unknown'", $source);

        $presenter = new SiteInfoPresenter();
        $card = $presenter->present([
            'domain' => 'congtybalo.com',
            'wp_reachable' => 'yes',
            'token_ok' => 'yes',
            'checked_at' => '2026-07-29 08:22:28',
            'plugin_version' => '1.0.62',
            'sync_status' => 'completed',
            'waiting_articles' => 3,
            'publishing' => 0,
            'publish_failed' => 0,
            'capabilities_loaded' => true,
            'snapshot_received' => true,
        ]);
        $summary = (string) ($card['summary'] ?? '');
        self::assertStringContainsString('WP reachable: yes', $summary);
        self::assertStringContainsString('Token: valid', $summary);
        self::assertStringNotContainsString('unknown', $summary);
    }

    public function test_required_site_commands_in_catalog(): void
    {
        foreach ([
            '/site-list',
            '/site-switch',
            '/site-info',
            '/site-health',
            '/site-sync',
            '/site-sync-keywords',
            '/site-sync-links',
            '/site-refresh-snapshot',
            '/context',
        ] as $cmd) {
            self::assertNotNull(AgentCliCommandCatalog::get($cmd), $cmd);
        }

        self::assertSame('site.sync', AgentCliCommandCatalog::get('/site-sync')['capability_key'] ?? null);
        self::assertSame('site.refresh_snapshot', AgentCliCommandCatalog::get('/site-refresh-snapshot')['capability_key'] ?? null);
        self::assertTrue((bool) (AgentCliCommandCatalog::get('/site-list')['local_only'] ?? false));
    }

    public function test_site_health_refresh_rewrites_to_canonical_refresh(): void
    {
        $parser = new AgentCliCommandParser();
        $parsed = $parser->parse('/site-health --refresh');
        self::assertTrue($parsed['ok'] ?? false);
        self::assertSame('site.refresh_snapshot', $parsed['skill_key'] ?? null);
    }

    public function test_site_switch_and_list_handlers_exist(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AgentWorkspacePage::class))->getFileName());
        self::assertStringContainsString('handleSiteSwitch', $source);
        self::assertStringContainsString('appendSiteListMessage', $source);
        self::assertStringContainsString('setGlobalSiteId', $source);
        self::assertStringContainsString('--site-id', $source);
        self::assertStringContainsString('resolveAccessibleSiteIdByDomain', $source);
        self::assertStringContainsString('bindCliProjectRefToContext', $source);
        self::assertStringContainsString('khÃ´ng thuá»™c site hiá»‡n táº¡i', $source);
    }

    public function test_cli_project_id_binds_before_capability_gate(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AgentWorkspacePage::class))->getFileName());
        $body = $this->extractMethodBody($source, 'tryHandleCliComposer');
        $bindPos = strpos($body, 'bindCliProjectRefToContext');
        $gatePos = strpos($body, 'AgentCliCapabilityGate');
        self::assertNotFalse($bindPos);
        self::assertNotFalse($gatePos);
        self::assertLessThan($gatePos, $bindPos);
    }

    public function test_frontend_catalog_includes_site_group_commands(): void
    {
        $js = (string) file_get_contents(ProjectRoot::addonsPath().'/agent/resources/js/agent/command-catalog.js');
        foreach (['/site-list', '/site-switch', '/site-sync', '/site-health', '/site-refresh-snapshot', '--member-id'] as $needle) {
            self::assertStringContainsString($needle, $js, $needle);
        }

        $phpNames = array_column(AgentCliCommandCatalog::toFrontendCatalog(), 'name');
        sort($phpNames);
        preg_match_all("/name:\\s*'(\\/[a-z0-9-]+)'/", $js, $matches);
        $jsNames = array_values(array_unique($matches[1] ?? []));
        sort($jsNames);
        self::assertSame($phpNames, $jsNames);
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
}
