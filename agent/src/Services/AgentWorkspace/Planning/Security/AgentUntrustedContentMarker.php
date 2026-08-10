<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security;

/**
 * Marks imported/external content as untrusted data for the planner.
 */
final class AgentUntrustedContentMarker
{
    public const OPEN = '<<<UNTRUSTED_DATA>>>';

    public const CLOSE = '<<<END_UNTRUSTED_DATA>>>';

    public function wrap(string $content, string $source = 'external'): string
    {
        $safe = str_replace([self::OPEN, self::CLOSE], ['', ''], $content);

        return self::OPEN."\nsource=".$source."\n".$safe."\n".self::CLOSE;
    }

    public function containsInjectionAttempt(string $text): bool
    {
        $normalized = mb_strtolower($text);
        $patterns = [
            'ignore previous',
            'bỏ qua mọi luật',
            'bo qua moi luat',
            'bypass confirmation',
            'auto_confirm',
            'auto_execute',
            'run all',
            'disable confirmation',
            'internal capability',
            'commandbus',
            'api_key',
            'system prompt',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
