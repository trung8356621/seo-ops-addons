<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentMessageHtmlLayoutTest extends TestCase
{
    public function test_user_message_bubble_does_not_use_pre_wrap_on_outer_shell(): void
    {
        $css = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/resources/css/global-ai-chat.css'
        );

        self::assertMatchesRegularExpression(
            '/\.seo-global-chat__user-message\s*\{[^}]*white-space:\s*normal/s',
            $css,
        );
        self::assertStringContainsString(
            '.seo-global-chat__user-message > .whitespace-pre-wrap',
            $css,
        );
    }

    public function test_user_messages_skip_structured_partial_include(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php')
        );

        self::assertStringContainsString("(\$message['role'] ?? '') !== 'user'", $blade);
        self::assertStringContainsString('agent-message-structured', $blade);
        self::assertStringContainsString('<?php if', $blade);
    }

    public function test_message_component_avoids_blade_if_morph_markers_in_bubble(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/seo-agent-chat/message.blade.php')
        );

        self::assertStringContainsString('<?php if ($showContent): ?>', $blade);
        self::assertStringNotContainsString('@if (filled($content))', $blade);
        self::assertStringNotContainsString('@unless ($isUser)', $blade);
    }

    public function test_yes_no_only_on_execution_confirmation_cards(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/partials/agent-execution-card.blade.php')
        );

        self::assertStringContainsString("\$messageType === 'execution_confirmation'", $blade);
        self::assertStringContainsString("answerConversation('yes')", $blade);
        // Yes/No buttons must stay under confirmation-only gate (not preview).
        self::assertMatchesRegularExpression(
            "/messageType === 'execution_confirmation'[\s\S]*answerConversation\('yes'\)/",
            $blade,
        );
        self::assertDoesNotMatchRegularExpression(
            "/messageType === 'execution_preview'[\s\S]{0,200}answerConversation\('yes'\)/",
            $blade,
        );
    }

    public function test_submit_composer_reloads_draft_before_flow_routing(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName()
        );

        self::assertStringContainsString('loadActiveDraftFromConversation($conversation)', $source);
        self::assertStringContainsString('pendingConfirmationToken', $source);
        self::assertStringContainsString('! $requiresConfirmation', $source);
        self::assertStringNotContainsString(
            'confirmationRef = $exec?->confirmation_token_hash',
            $source,
        );
    }
}
