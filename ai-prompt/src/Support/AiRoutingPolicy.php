<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * How free/paid candidates are attempted — independent of Execution Profile.
 *
 * Does NOT rewrite AI Center manual sortable order except as a bounded
 * selection rule (quick_free prefers one eligible free attempt, then paid).
 *
 * Distinct from {@see AiExecutionRoutingMode} (legacy cost/budget mode labels)
 * and from {@see AiCostPolicy} (global FreeOnly hard restriction).
 */
enum AiRoutingPolicy: string
{
    case Normal = 'normal';
    case QuickFree = 'quick_free';
    case FreeOnly = 'free_only';

    public static function tryFromMixed(mixed $value): ?self
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            self::Normal->value, 'default', 'auto' => self::Normal,
            self::QuickFree->value, 'quick-free', 'quickfree', 'one_free' => self::QuickFree,
            self::FreeOnly->value, 'free-only', 'freeonly', 'free' => self::FreeOnly,
            default => self::tryFrom($normalized),
        };
    }

    public function allowsPaidRoutes(): bool
    {
        return $this !== self::FreeOnly;
    }

    /**
     * Cap on actual free provider API calls for this policy.
     * null = use caller / resilience settings unchanged.
     */
    public function freeAttemptCap(): ?int
    {
        return match ($this) {
            self::QuickFree => 1,
            self::Normal, self::FreeOnly => null,
        };
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::QuickFree => 'Quick Free',
            self::FreeOnly => 'Free Only',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Normal => 'Use the normal AI Routing order.',
            self::QuickFree => 'Try one eligible free model first. If it fails, continue immediately with normal paid routing.',
            self::FreeOnly => 'Use free models only. Never fall back to paid models.',
        };
    }
}
