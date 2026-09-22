<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Where / when Prompt execution runs — independent of profile and routing policy.
 *
 * Interactive = synchronous shared AI stack (no background AI job dispatch).
 * Background = existing queued / job-driven execution (default for article workloads).
 */
enum AiExecutionTransport: string
{
    case Interactive = 'interactive';
    case Background = 'background';

    public static function tryFromMixed(mixed $value): ?self
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            self::Interactive->value, 'sync', 'synchronous', 'direct' => self::Interactive,
            self::Background->value, 'queued', 'queue', 'async' => self::Background,
            default => self::tryFrom($normalized),
        };
    }

    public function isInteractive(): bool
    {
        return $this === self::Interactive;
    }
}
