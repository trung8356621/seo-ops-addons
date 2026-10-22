<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Deterministic job idempotency — retry/re-dispatch must not invent a second job
 * for the same task + session + trigger window.
 */
final class JobIdempotencyKey
{
    public static function forDispatch(
        string $taskUuid,
        string|int $sessionId,
        string|int|null $triggerId,
        string|int|null $accountId = null,
    ): string {
        return hash('sha256', implode('|', [
            $taskUuid,
            (string) $sessionId,
            (string) ($triggerId ?? 'manual'),
            (string) ($accountId ?? 'no-account'),
        ]));
    }
}
