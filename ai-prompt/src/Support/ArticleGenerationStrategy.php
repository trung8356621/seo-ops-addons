<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use BackedEnum;

/**
 * Article body generation strategy (legacy enum + compat).
 *
 * Runtime shape for article.content.generate is derived from the AI Center primary
 * candidate via {@see ArticleGenerationShape} — not from user override.
 *
 * {@see self::SectionedFree} remains a legacy alias accepted from DB/history.
 */
enum ArticleGenerationStrategy: string
{
    case SinglePass = 'single_pass';

    /** Canonical sectioned generation (any primary free candidate). */
    case Sectioned = 'sectioned';

    /**
     * @deprecated Use {@see self::Sectioned}. Accepted as alias for sectioned shape.
     */
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
        $parsed = self::tryFromMixed($value);
        if ($parsed === null) {
            return self::SinglePass;
        }

        return $parsed->canonical();
    }

    /**
     * Normalize legacy sectioned_free → sectioned.
     */
    public function canonical(): self
    {
        return $this === self::SectionedFree ? self::Sectioned : $this;
    }

    public function toShape(): ArticleGenerationShape
    {
        return match ($this->canonical()) {
            self::SinglePass => ArticleGenerationShape::SinglePass,
            self::Sectioned, self::SectionedFree => ArticleGenerationShape::Sectioned,
        };
    }

    public function isSectioned(): bool
    {
        return $this === self::Sectioned || $this === self::SectionedFree;
    }

    /**
     * @deprecated Prefer {@see self::isSectioned()}.
     */
    public function isSectionedFree(): bool
    {
        return $this->isSectioned();
    }
}
