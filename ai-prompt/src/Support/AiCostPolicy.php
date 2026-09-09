<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiCostPolicy: string
{
    case Default = 'default';
    case FreeOnly = 'free_only';

    /** Run settings + user_meta SSOT for article generation mode (NORMAL / FREE_ONLY). */
    public const SETTING_KEY = 'ai_cost_policy';

    public static function tryFromMixed(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            self::FreeOnly->value, 'free', 'free-only', 'freeonly', 'chi_free', 'chỉ free' => self::FreeOnly,
            self::Default->value, 'normal', 'binh_thuong', 'bình thường' => self::Default,
            default => self::Default,
        };
    }

    public function isFreeOnly(): bool
    {
        return $this === self::FreeOnly;
    }

    /** User-facing generation mode token for UI / snapshots. */
    public function generationModeValue(): string
    {
        return $this === self::FreeOnly ? self::FreeOnly->value : 'normal';
    }
}
