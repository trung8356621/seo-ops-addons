<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Free-model language gate — not a quality score.
 * PENDING = discovered, not yet manually evaluated (non-English).
 */
enum OpenRouterFreeLanguageState: string
{
    case Pending = 'PENDING';
    case Supported = 'SUPPORTED';
    case Unsupported = 'UNSUPPORTED';

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }
        $raw = strtoupper(trim((string) $value));
        if ($raw === 'UNKNOWN' || $raw === '') {
            return self::Pending;
        }

        return self::tryFrom($raw);
    }

    public function isRuntimeEligible(): bool
    {
        return $this === self::Supported;
    }
}
