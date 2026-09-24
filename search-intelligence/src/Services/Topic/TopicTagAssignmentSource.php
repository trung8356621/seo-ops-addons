<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

/**
 * Provenance for seo_topic_tag_assignments.source.
 */
final class TopicTagAssignmentSource
{
    public const MANUAL = 'manual';

    public const AI = 'ai';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::MANUAL, self::AI];
    }

    public static function normalize(?string $raw): string
    {
        $value = strtolower(trim((string) $raw));

        return $value === self::AI ? self::AI : self::MANUAL;
    }

    public static function isAi(?string $raw): bool
    {
        return self::normalize($raw) === self::AI;
    }

    public static function isManual(?string $raw): bool
    {
        return self::normalize($raw) === self::MANUAL;
    }
}
