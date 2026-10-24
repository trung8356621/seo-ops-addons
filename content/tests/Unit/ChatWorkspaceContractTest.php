<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use App\Addons\SeoContentAi\Providers\SeoPanelProvider;
use Omnichannel\Addons\Content\Filament\Pages\ChatWorkspacePage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

/**
 * Contract: Chat Workspace Group Chat; Agent tab isolated (reference-only).
 * Legacy local Support Ticket UI/routes are removed (OPS-SERVER future).
 */
final class ChatWorkspaceContractTest extends TestCase
{
    public function test_chat_page_slug_and_round_launcher_modes(): void
    {
        $reflection = new ReflectionClass(ChatWorkspacePage::class);
        self::assertSame('chat', $reflection->getStaticPropertyValue('slug'));
        self::assertSame(
            'seo-content-ai::filament.pages.chat-workspace',
            $reflection->getStaticPropertyValue('view'),
        );

        $viewPath = LegacyAddonPath::resolve('resources/views/filament/pages/chat-workspace.blade.php');
        self::assertFileExists($viewPath);
        $source = (string) file_get_contents($viewPath);
        self::assertStringContainsString('chat-mode-launcher', $source);
        self::assertStringContainsString("\$tab === 'agent'", $source);
        self::assertStringContainsString('seo-group-chat-root', $source);
        self::assertStringNotContainsString('seo-ticket-panel-root', $source);
        self::assertStringNotContainsString('support-tickets', $source);
        self::assertStringNotContainsString("tabUrl('agent')", $source);
        self::assertStringNotContainsString('seo-chat-workspace__nav', $source);
        self::assertStringNotContainsString('telegram', strtolower($source));
        self::assertStringNotContainsString('agent-workspace', $source);
    }

    public function test_mode_launcher_reuses_round_global_chat_button(): void
    {
        $launcher = LegacyAddonPath::resolve('resources/views/components/chat-mode-launcher.blade.php');
        self::assertFileExists($launcher);
        $source = (string) file_get_contents($launcher);
        self::assertStringContainsString('seo-global-chat__launcher', $source);
        self::assertStringContainsString('ChatWorkspacePage::getUrl', $source);
        self::assertStringContainsString("\$modeUrl('group')", $source);
        self::assertStringNotContainsString("\$modeUrl('ticket')", $source);
        self::assertStringContainsString('openAgentMode', $source);
        self::assertStringNotContainsString('AgentWorkspacePage', $source);
        self::assertStringNotContainsString('AgentWorkspaceDeepLink', $source);
        self::assertStringNotContainsString('ChatModeV2', $source);
    }

    public function test_chat_page_does_not_land_on_agent_tab(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ChatWorkspacePage::class))->getFileName(),
        );
        self::assertStringContainsString("\$tab === 'agent'", $source);
        self::assertStringContainsString("\$tab = 'group'", $source);
    }

    public function test_seo_panel_does_not_discover_agent_filament(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoPanelProvider::class))->getFileName(),
        );
        self::assertStringNotContainsString("'agent' => 'Agent'", $source);
        self::assertStringNotContainsString("view('seo-content-ai::components.global-ai-chat')", $source);
        self::assertStringNotContainsString("view('seo-content-ai::filament.hooks.support-ticket-header')", $source);
        self::assertStringContainsString("view('seo-content-ai::components.chat-unread-badge')", $source);
        self::assertStringNotContainsString('api/seo/support-tickets', $source);
        self::assertStringNotContainsString('SupportTicketController', $source);
    }

    public function test_outside_chat_does_not_mount_floating_launcher(): void
    {
        $path = LegacyAddonPath::resolve('resources/views/components/chat-unread-badge.blade.php');
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString('chat-mode-launcher', $source);
        self::assertStringContainsString('unreadBadge.js', $source);
    }

    public function test_team_message_controller_is_json_only_no_sse_loop(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Http/Controllers/TeamMessageController.php',
        );
        self::assertStringContainsString('pollJson', $source);
        self::assertStringContainsString('unreadCount', $source);
        self::assertStringContainsString('markRead', $source);
        self::assertStringNotContainsString('StreamedResponse', $source);
        self::assertStringNotContainsString('text/event-stream', $source);
    }

    public function test_group_chat_js_uses_single_inflight_scheduler(): void
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/chat/groupChatApp.js';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('inflight', $source);
        self::assertStringContainsString('setTimeout', $source);
        self::assertStringNotContainsString('setInterval', $source);
        self::assertStringContainsString('poll=1&after_id=', $source);
        self::assertStringContainsString('_status', $source);
        self::assertStringContainsString('seo-chat-img-placeholder', $source);
    }

    public function test_legacy_local_support_ticket_assets_are_removed(): void
    {
        self::assertFileDoesNotExist(ProjectRoot::addonsPath().'/content/resources/js/chat/ticketPanel.js');
        self::assertFileDoesNotExist(ProjectRoot::addonsPath().'/seo/src/Http/Controllers/SupportTicketController.php');
        self::assertFileDoesNotExist(ProjectRoot::addonsPath().'/seo/src/Services/SupportTicketDeliveryService.php');
        self::assertFileDoesNotExist(ProjectRoot::path().'/app/Http/Controllers/SupportTicketController.php');
        self::assertFileDoesNotExist(ProjectRoot::path().'/app/Models/SupportTicket.php');
    }
}
