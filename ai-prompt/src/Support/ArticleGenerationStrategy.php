<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use BackedEnum;

/**
 * Article body generation strategy.
 *
 * Default remains {@see self::SinglePass}. {@see self::SectionedFree} is an
 * explicit free-model test path and must never become the implicit default.
 */
enum ArticleGenerationStrategy: string
{
    case SinglePass = 'single_pass';

    /** Reserved — not implemented in phase 1. */
    case Sectioned = 'sectioned';

    /** Free-only sectioned generation + deterministic assemble. */
    case SectionedFree = 'sectioned_free';

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : self::tryFrom($normalized);
    }

    public static function resolve(mixed $value): self
    {
        return self::tryFromMixed($value) ?? self::SinglePass;
    }

    public function isSectionedFree(): bool
    {
        return $this === self::SectionedFree;
    }
}
