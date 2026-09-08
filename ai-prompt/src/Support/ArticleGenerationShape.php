<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use BackedEnum;

/**
 * Prompt/task shape for article body generation.
 *
 * Independent from routing policy (normal vs free_only) and from candidate economics.
 * Writing pass mode is derived from manual writing_split_enabled preference —
 * not from free/paid primary candidate.
 */
enum ArticleGenerationShape: string
{
    case SinglePass = 'single_pass';

    case Sectioned = 'sectioned';

    public const SOURCE_AI_CENTER_PRIMARY = 'ai_center_primary_candidate';

    public const SOURCE_WRITING_SPLIT_PREFERENCE = 'writing_split_preference';

    public static function fromWritingSplitEnabled(bool $enabled): self
    {
        return $enabled ? self::Sectioned : self::SinglePass;
    }

    /**
     * @deprecated Pass mode is manual writing_split_enabled — do not derive from free/paid.
     */
    public static function fromPrimaryIsFree(bool $isFree): self
    {
        return $isFree ? self::Sectioned : self::SinglePass;
    }

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
        if ($normalized === '') {
            return null;
        }

        // Legacy alias: sectioned_free → sectioned (shape ≠ free-only routing).
        if ($normalized === 'sectioned_free') {
            return self::Sectioned;
        }

        return self::tryFrom($normalized);
    }

    public function isSectioned(): bool
    {
        return $this === self::Sectioned;
    }

    public function toLegacyStrategy(): ArticleGenerationStrategy
    {
        return match ($this) {
            self::SinglePass => ArticleGenerationStrategy::SinglePass,
            self::Sectioned => ArticleGenerationStrategy::Sectioned,
        };
    }
}
