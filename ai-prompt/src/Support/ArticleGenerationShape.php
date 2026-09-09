<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use BackedEnum;

/**
 * Prompt/task shape for article body / outline generation.
 *
 * Runtime authority: first usable AI Center route cost_class
 * (FREE → sectioned/SPLIT, PAID → single_pass/SINGLE) via
 * {@see \Omnichannel\Addons\AiPrompt\Services\GenerationShapeResolver}.
 *
 * Independent from free_only routing policy. Legacy preference helpers below
 * are deprecated compatibility only — they must not control new runs.
 */
enum ArticleGenerationShape: string
{
    case SinglePass = 'single_pass';

    case Sectioned = 'sectioned';

    /** @deprecated Prefer SOURCE_ROUTE_COST_AUTO for new runs. */
    public const SOURCE_AI_CENTER_PRIMARY = 'ai_center_primary_candidate';

    /** @deprecated Manual checkbox — no longer runtime authority. */
    public const SOURCE_WRITING_SPLIT_PREFERENCE = 'writing_split_preference';

    public const SOURCE_ROUTE_COST_AUTO = 'route_cost_auto';

    /**
     * @deprecated Use GenerationShapeResolver / fromRouteCostClass.
     */
    public static function fromWritingSplitEnabled(bool $enabled): self
    {
        return $enabled ? self::Sectioned : self::SinglePass;
    }

    /**
     * FREE → SPLIT (sectioned), PAID → SINGLE.
     */
    public static function fromRouteCostClass(string $costClass): self
    {
        return strtolower(trim($costClass)) === 'free'
            ? self::Sectioned
            : self::SinglePass;
    }

    /**
     * @deprecated Alias of fromRouteCostClass — kept for BC callers.
     */
    public static function fromPrimaryIsFree(bool $isFree): self
    {
        return self::fromRouteCostClass($isFree ? 'free' : 'paid');
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
