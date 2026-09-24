<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use App\Addons\SeoContentAi\Providers\SeoPanelProvider;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspaceLegacyRedirect;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceDeepLink;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

/**
 * Contract: Chat Workspace Agent/Group; Support Ticket is global client header (not SEO-owned).
 */
final class ChatWorkspaceContractTest extends TestCase
{
    public function test_chat_page_slug_and_round_launcher_modes(): void
    {
        $reflection = new ReflectionClass(AgentWorkspacePage::class);
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
        self::assertStringContainsString("\$tab === 'group'", $source);
        self::assertStringContainsString('seo-group-chat-root', $source);
        self::assertStringContainsString('seo-ticket-panel-root', $source);
        self::assertStringContainsString("route('support-tickets.store')", $source);
        self::assertStringNotContainsString("tabUrl('agent')", $source);
        self::assertStringNotContainsString('seo-chat-workspace__nav', $source);
        self::assertStringNotContainsString('telegram', strtolower($source));
    }

    public function test_mode_launcher_reuses_round_global_chat_button(): void
    {
        $launcher = LegacyAddonPath::resolve('resources/views/components/chat-mode-launcher.blade.php');
        self::assertFileExists($launcher);
        $source = (string) file_get_contents($launcher);
        self::assertStringContainsString('seo-global-chat__launcher', $source);
        self::assertStringContainsString("\$modeUrl('agent')", $source);
        self::assertStringContainsString("\$modeUrl('group')", $source);
        self::assertStringContainsString("\$modeUrl('ticket')", $source);
        self::assertStringNotContainsString('ChatModeV2', $source);
    }

    public function test_legacy_agent_route_is_redirect_only(): void
    {
        $reflection = new ReflectionClass(AgentWorkspaceLegacyRedirect::class);
        self::assertSame('agent', $reflection->getStaticPropertyValue('slug'));
        self::assertFalse($reflection->getStaticPropertyValue('shouldRegisterNavigation'));

        $source = (string) file_get_contents((string) $reflection->getFileName());
        self::assertStringContainsString("'tab' => 'agent'", $source);
        self::assertStringContainsString('AgentWorkspacePage::getUrl', $source);
    }

    public function test_deep_link_targets_chat_agent_tab_and_preserves_project_ref(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(AgentWorkspaceDeepLink::class))->getFileName(),
        );
        self::assertStringContainsString("'tab' => 'agent'", $source);
        self::assertStringContainsString('project_ref', $source);
        self::assertStringContainsString('AgentWorkspacePage::getUrl', $source);
    }

    public function test_seo_panel_does_not_mount_duplicate_ticket_header(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoPanelProvider::class))->getFileName(),
        );
        self::assertStringNotContainsString("view('seo-content-ai::components.global-ai-chat')", $source);
        self::assertStringNotContainsString("view('seo-content-ai::filament.hooks.support-ticket-header')", $source);
        self::assertStringContainsString("view('seo-content-ai::components.chat-unread-badge')", $source);
        self::assertStringContainsString('api/seo/support-tickets', $source);
        self::assertStringContainsString('App\\Http\\Controllers\\SupportTicketController', $source);
        self::assertStringNotContainsString('seo.support-tickets.retry', $source);
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

    public function test_ticket_panel_supports_attach_and_paste_without_remote_retry(): void
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/chat/ticketPanel.js';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('files[]', $source);
        self::assertStringContainsString('paste', $source);
        self::assertStringContainsString('FormData', $source);
        self::assertStringContainsString('Đã gửi ticket thành công.', $source);
        self::assertStringNotContainsString('data-retry', $source);
        self::assertStringNotContainsString('retryUrlTemplate', $source);
    }

    public function test_seo_support_ticket_controller_is_compat_alias_only(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Http/Controllers/SupportTicketController.php',
        );
        self::assertStringContainsString('App\\Http\\Controllers\\SupportTicketController', $source);
        self::assertStringContainsString('@deprecated', $source);
        self::assertStringNotContainsString('TeamChatAttachmentService', $source);
        self::assertStringNotContainsString('SupportTicketDeliveryService', $source);
    }
}
