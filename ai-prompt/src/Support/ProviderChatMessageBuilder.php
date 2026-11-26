<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Maps structured system/user instructions to provider chat messages.
 * Adapters must not invent prompt content — only choose role layout.
 *
 * If the API cannot express a system role, system text is prepended to user
 * content (never dropped).
 */
final class ProviderChatMessageBuilder
{
    /**
     * OpenAI-compatible / DeepSeek chat messages.
     *
     * @return list<array{role: string, content: string}>
     */
    public static function openAiCompatible(?string $system, string $user, bool $supportsSystemRole = true): array
    {
        $system = trim((string) $system);
        $user = trim($user);

        if ($user === '' && $system !== '') {
            return [['role' => 'user', 'content' => $system]];
        }
        if ($system === '') {
            return [['role' => 'user', 'content' => $user]];
        }
        if ($supportsSystemRole) {
            return [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ];
        }

        return [['role' => 'user', 'content' => $system."\n\n".$user]];
    }

    /**
     * Single user content for Gemini generateContent (no system_instruction in our client yet).
     * System is prepended — never omitted.
     */
    public static function geminiUserText(?string $system, string $user): string
    {
        $system = trim((string) $system);
        $user = trim($user);
        if ($user === '') {
            return $system;
        }
        if ($system === '') {
            return $user;
        }

        return $system."\n\n".$user;
    }

    /**
     * Anthropic Messages: optional top-level system + user messages.
     *
     * @return array{system: ?string, messages: list<array{role: string, content: string}>}
     */
    public static function anthropic(?string $system, string $user): array
    {
        $system = trim((string) $system);
        $user = trim($user);
        if ($user === '' && $system !== '') {
            return [
                'system' => null,
                'messages' => [['role' => 'user', 'content' => $system]],
            ];
        }

        return [
            'system' => $system !== '' ? $system : null,
            'messages' => [['role' => 'user', 'content' => $user]],
        ];
    }
}
