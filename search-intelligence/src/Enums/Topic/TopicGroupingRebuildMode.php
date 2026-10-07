<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Topic;

/**
 * Run-level Topic structure rebuild policy for semantic grouping Apply.
 * Does not affect semantic input_hash / Python payload.
 */
final class TopicGroupingRebuildMode
{
    /** Current production behavior: reuse identity + protect manual/locks/business state. */
    public const PRESERVE_EXISTING = 'preserve_existing';

    /** Explicit destructive rebuild: proposal becomes new Topic identities; Topic-owned state reset on Apply. */
    public const FULL_RESET = 'full_reset';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::PRESERVE_EXISTING, self::FULL_RESET];
    }

    public static function normalize(?string $value): string
    {
        $mode = strtolower(trim((string) $value));

        return $mode === self::FULL_RESET
            ? self::FULL_RESET
            : self::PRESERVE_EXISTING;
    }

    public static function isFullReset(?string $value): bool
    {
        return self::normalize($value) === self::FULL_RESET;
    }

    public static function isPreserveExisting(?string $value): bool
    {
        return self::normalize($value) === self::PRESERVE_EXISTING;
    }
}
