<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCapabilityGate;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCommandCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\DefaultAgentExecutionOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\SiteInfoPresenter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression: CLI UX → canonical capability chain, one-submit, focus, helper card.
 */
final class AgentCliUxCapabilityFixTest extends TestCase
{
    public function test_one_submit_path_has_single_cli_handler_and_client_request_id(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName()
        );
        $body = $this->extractMethodBody($source, 'submitComposer');

        self::assertSame(1, substr_count($body, 'tryHandleCliComposer('));
        self::assertStringContainsString('lastHandledClientRequestId', $source);
        self::assertStringContainsString('clientRequestId', $source);

        $send = $this->extractMethodBody($source, 'sendMessage');
        self::assertStringContainsString('clientRequestId', $send);
        self::assertStringContainsString('lastHandledClientRequestId', $send);
    }

    public function test_enter_submit_uses_single_owner_and_request_id(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php')
        );
        $composer = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/seo-agent-chat/composer.blade.php')
        );

        self::assertStringContainsString('submitAgentComposer()', $composer);
        self::assertStringContainsString('x-on:submit.prevent="submitAgentComposer()"', $composer);
        self::assertStringContainsString('_pendingClientRequestId', $blade);
        self::assertStringContainsString("sendMessage(message, this._pendingClientRequestId)", $blade);
        self::assertStringContainsString('composerSubmitting', $blade);
    }

    public function test_focus_restored_after_result_and_confirmation(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName()
        );
        $submitFinally = $this->extractMethodBody($source, 'submitComposer');
        self::assertStringContainsString("dispatch('agent-focus-composer')", $submitFinally);

        $answer = $this->extractMethodBody($source, 'answerConversation');
        self::assertStringContainsString("dispatch('agent-focus-composer')", $answer);

        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php')
        );
        self::assertStringContainsString('focusComposer({ force: true', $blade);
        self::assertStringContainsString('preventScroll: true', $blade);
        self::assertStringContainsString('answerConversation', $blade);
    }

    public function test_palette_arrow_scrolls_active_row_into_view(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php')
        );
        $move = $this->extractJsMethod($blade, 'movePalette');
        self::assertStringContainsString('scrollIntoView', $move);
        self::assertStringContainsString("block: 'nearest'", $move);
        self::assertStringContainsString('paletteRoot', $move);
    }

    public function test_chat_auto_scrolls_with_stick_to_bottom(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php')
        );
        self::assertStringContainsString('x-ref="chatMessages"', $blade);
        self::assertStringContainsString('MutationObserver', $blade);
        self::assertStringContainsString('stickToBottom', $blade);
        self::assertStringContainsString('scrollChatToBottom', $blade);
        $submit = $this->extractJsMethod($blade, 'submitAgentComposer');
        self::assertStringContainsString('stickToBottom = true', $submit);
        self::assertStringContainsString('scrollChatToBottom(true)', $submit);
    }

    public function test_helper_card_only_shows_example_and_global_tab_hint(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php')
        );

        self::assertStringContainsString('cliHelp.example', $blade);
        self::assertStringContainsString('seo-agent-workspace__cli-global-keys', $blade);
        self::assertStringContainsString('Tab: biến tiếp theo · Shift+Tab: biến trước', $blade);
        self::assertStringNotContainsString('cliHelp.name', $blade);
        self::assertStringNotContainsString('cliHelp.description', $blade);
        self::assertStringNotContainsString('seo-agent-workspace__cli-help-keys', $blade);
    }

    public function test_project_create_maps_to_canonical_capability(): void
    {
        $row = AgentCliCommandCatalog::get('/project-create');
        self::assertNotNull($row);
        self::assertSame('content_project.create', $row['skill_key']);
        self::assertSame('content_project.create', $row['capability_key']);
        self::assertFalse((bool) ($row['local_only'] ?? false));
    }

    public function test_archive_confirmation_comes_from_canonical_gate_helper(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentCliCapabilityGate::class))->getFileName()
        );
        self::assertStringContainsString('confirmation_requirement', $source);
        self::assertStringContainsString('confirmationForCapability', $source);

        $orch = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentExecutionOrchestrator::class))->getFileName()
        );
        self::assertStringContainsString('capabilityGate->confirmationForCapability', $orch);
        self::assertStringContainsString('AgentCliCapabilityGate', $orch);
    }

    public function test_unavailable_capability_rejected_without_local_fallback(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName()
        );
        $body = $this->extractMethodBody($source, 'tryHandleCliComposer');
        self::assertStringContainsString('AgentCliCapabilityGate', $body);
        self::assertStringContainsString('capability_unavailable', $body);
        self::assertStringContainsString('agent_error', $body);
    }

    public function test_site_health_receives_site_ref_and_fails_closed(): void
    {
        $read = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentReadService::class))->getFileName()
        );
        $body = $this->extractMethodBody($read, 'getSiteHealth');
        self::assertStringContainsString('site_ref', $body);
        self::assertStringContainsString('Thiếu site_ref', $body);
        self::assertStringNotContainsString("return ['sites' => []];", $body);

        $row = AgentCliCommandCatalog::get('/site-health');
        self::assertSame('content_project.get_site_health', $row['capability_key'] ?? null);
    }

    public function test_local_only_commands_separated_from_mcp_backed(): void
    {
        foreach (['/help', '/new-chat', '/context', '/site-list', '/site-switch', '/site-info'] as $cmd) {
            $row = AgentCliCommandCatalog::get($cmd);
            self::assertNotNull($row, $cmd);
            self::assertTrue((bool) ($row['local_only'] ?? false), $cmd.' must be local_only');
        }

        foreach (['/project-create', '/project-archive', '/site-health'] as $cmd) {
            $row = AgentCliCommandCatalog::get($cmd);
            self::assertNotNull($row, $cmd);
            self::assertFalse((bool) ($row['local_only'] ?? false), $cmd.' must be MCP-backed');
            self::assertNotEmpty($row['capability_key'] ?? null, $cmd);
        }
    }

    public function test_site_info_presenter_handles_sites_array_not_generic_empty(): void
    {
        $presenter = new SiteInfoPresenter();
        $empty = $presenter->present(['sites' => []]);
        self::assertStringContainsString('Thiếu dữ liệu site health.', (string) ($empty['summary'] ?? ''));
        self::assertStringNotContainsString('Không có dữ liệu site.', (string) ($empty['summary'] ?? ''));

        $ok = $presenter->present([
            'sites' => [['name' => 'Demo', 'domain' => 'demo.test', 'status' => 'ok']],
        ]);
        self::assertStringContainsString('Site health — demo.test', (string) ($ok['summary'] ?? ''));
        self::assertStringContainsString('demo.test', (string) ($ok['summary'] ?? ''));
    }

    public function test_execution_message_does_not_duplicate_body_content(): void
    {
        $message = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/seo-agent-chat/message.blade.php')
        );
        self::assertStringContainsString('execution_result', $message);
        self::assertStringContainsString('$showContent', $message);

        $orch = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentExecutionOrchestrator::class))->getFileName()
        );
        self::assertStringContainsString('keep message content empty', $orch);
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
