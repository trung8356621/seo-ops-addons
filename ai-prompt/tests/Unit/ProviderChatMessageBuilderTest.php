<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\ProviderChatMessageBuilder;
use PHPUnit\Framework\TestCase;

final class ProviderChatMessageBuilderTest extends TestCase
{
    public function test_openai_splits_system_and_user_when_supported(): void
    {
        $messages = ProviderChatMessageBuilder::openAiCompatible('SYS', 'USER', true);
        self::assertSame([
            ['role' => 'system', 'content' => 'SYS'],
            ['role' => 'user', 'content' => 'USER'],
        ], $messages);
    }

    public function test_openai_prepends_system_when_role_unsupported_never_drops(): void
    {
        $messages = ProviderChatMessageBuilder::openAiCompatible('SYS', 'USER', false);
        self::assertSame([
            ['role' => 'user', 'content' => "SYS\n\nUSER"],
        ], $messages);
    }

    public function test_gemini_prepends_system_never_drops(): void
    {
        self::assertSame("SYS\n\nUSER", ProviderChatMessageBuilder::geminiUserText('SYS', 'USER'));
        self::assertSame('SYS', ProviderChatMessageBuilder::geminiUserText('SYS', ''));
    }

    public function test_anthropic_uses_top_level_system(): void
    {
        $payload = ProviderChatMessageBuilder::anthropic('SYS', 'USER');
        self::assertSame('SYS', $payload['system']);
        self::assertSame([['role' => 'user', 'content' => 'USER']], $payload['messages']);
    }

    public function test_identical_semantics_across_layouts(): void
    {
        $system = 'Never invent facts.';
        $user = 'Write a meta description.';
        $oa = ProviderChatMessageBuilder::openAiCompatible($system, $user, true);
        $flat = ProviderChatMessageBuilder::openAiCompatible($system, $user, false);
        $gem = ProviderChatMessageBuilder::geminiUserText($system, $user);

        self::assertSame($system, $oa[0]['content']);
        self::assertSame($user, $oa[1]['content']);
        self::assertStringContainsString($system, $flat[0]['content']);
        self::assertStringContainsString($user, $flat[0]['content']);
        self::assertStringContainsString($system, $gem);
        self::assertStringContainsString($user, $gem);
    }
}
