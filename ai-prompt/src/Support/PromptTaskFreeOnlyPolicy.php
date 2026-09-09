<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Explicit micro-task FreeOnly authority (stricter than article generation mode).
 * NORMAL generation mode must not weaken these hooks.
 */
final class PromptTaskFreeOnlyPolicy
{
    /**
     * Hook keys that always require FREE_ONLY cost policy.
     *
     * @var list<string>
     */
    private const FREE_ONLY_HOOKS = [
        'article.comment.generate',
    ];

    public static function requires(?string $hookKey): bool
    {
        $key = strtolower(trim((string) $hookKey));
        if ($key === '') {
            return false;
        }

        return in_array($key, self::FREE_ONLY_HOOKS, true);
    }
}
