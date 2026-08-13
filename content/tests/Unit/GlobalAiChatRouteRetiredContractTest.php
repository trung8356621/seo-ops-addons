<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;
use App\Addons\SeoContentAi\Providers\SeoPanelProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract: Global AI Chat HTTP API + floating mount remain retired after Chat Workspace cutover.
 */
final class GlobalAiChatRouteRetiredContractTest extends TestCase
{
    public function test_seo_panel_provider_does_not_register_global_ai_chat_routes(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoPanelProvider::class))->getFileName(),
        );

        self::assertStringNotContainsString("prefix('api/ai')", $source);
        self::assertStringNotContainsString('seo.global-ai-chat.models', $source);
        self::assertStringNotContainsString('seo.global-ai-chat.store', $source);
        self::assertStringNotContainsString('GlobalAiChatController', $source);
        self::assertStringNotContainsString("view('seo-content-ai::components.global-ai-chat')", $source);
    }

    public function test_popup_blade_does_not_bind_retired_chat_routes(): void
    {
        $bladePath = LegacyAddonPath::resolve('resources/views/components/global-ai-chat.blade.php');
        self::assertFileExists($bladePath);

        $source = (string) file_get_contents($bladePath);

        self::assertStringNotContainsString("route('seo.global-ai-chat.models')", $source);
        self::assertStringNotContainsString("route('seo.global-ai-chat.store')", $source);
        self::assertStringContainsString("\$modelsUrl = ''", $source);
        self::assertStringContainsString("\$chatUrl = ''", $source);
    }

    public function test_team_message_controller_is_json_poll_only(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Http/Controllers/TeamMessageController.php',
        );
        self::assertStringContainsString('pollJson', $source);
        self::assertStringNotContainsString('text/event-stream', $source);
        self::assertStringContainsString('unreadCount', $source);
    }
}
