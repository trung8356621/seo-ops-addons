<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspaceLegacyRedirect;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use App\Filament\Pages\AgentWorkspaceRedirect;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentWorkspaceRedirectTest extends TestCase
{
    public function test_admin_slug_is_agent(): void
    {
        $reflection = new ReflectionClass(AgentWorkspaceRedirect::class);
        self::assertSame('agent', $reflection->getStaticPropertyValue('slug'));
    }

    public function test_redirect_does_not_pick_random_connection(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceRedirect::class))->getFileName(),
        );

        self::assertStringContainsString('tryUrl', $source);
        self::assertStringNotContainsString('SeoDatabaseConnection::query()->first', $source);
        self::assertStringNotContainsString('orderBy', $source);
    }

    public function test_seo_chat_page_slug_is_chat_and_legacy_agent_redirects(): void
    {
        $chat = new ReflectionClass(AgentWorkspacePage::class);
        self::assertSame('chat', $chat->getStaticPropertyValue('slug'));
        self::assertNull($chat->getStaticPropertyValue('navigationGroup'));

        $legacy = new ReflectionClass(AgentWorkspaceLegacyRedirect::class);
        self::assertSame('agent', $legacy->getStaticPropertyValue('slug'));
        self::assertFalse($legacy->getStaticPropertyValue('shouldRegisterNavigation'));
    }
}
