<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiCostPolicy: string
{
    case Default = 'default';
    case FreeOnly = 'free_only';

    public const SETTING_KEY = 'ai_cost_policy';

    public static function tryFromMixed(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            self::FreeOnly->value, 'free', 'free-only' => self::FreeOnly,
            default => self::Default,
        };
    }

    public function isFreeOnly(): bool
    {
        return $this === self::FreeOnly;
    }
}
