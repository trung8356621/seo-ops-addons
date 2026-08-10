<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

/**
 * Allowed mode transitions for hosting rollout. Never mutates config.
 */
final class PromptHookModeTransitionPolicy
{
    public function allows(PromptHookRuntimeMode $from, PromptHookRuntimeMode $to): bool
    {
        if ($from === $to) {
            return true;
        }

        // Rollback always allowed to legacy.
        if ($to === PromptHookRuntimeMode::Legacy) {
            return true;
        }

        return match ($from) {
            PromptHookRuntimeMode::Legacy => $to === PromptHookRuntimeMode::Shadow,
            PromptHookRuntimeMode::Shadow => $to === PromptHookRuntimeMode::Hook
                || $to === PromptHookRuntimeMode::Legacy,
            PromptHookRuntimeMode::Hook => $to === PromptHookRuntimeMode::Shadow
                || $to === PromptHookRuntimeMode::Legacy,
        };
    }

    public function assertAllowed(PromptHookRuntimeMode $from, PromptHookRuntimeMode $to): void
    {
        if (! $this->allows($from, $to)) {
            throw new \InvalidArgumentException(
                "Mode transition [{$from->value}] → [{$to->value}] is not allowed.",
            );
        }
    }

    /** Stable version bump is never automatic. */
    public function allowsAutomaticStableVersionPromotion(): bool
    {
        return false;
    }
}
