<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Topic;

/**
 * Topic entity provenance for seo_topics.source (not membership seed source).
 */
final class TopicSource
{
    public const AUTO = 'auto';

    public const MANUAL = 'manual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::AUTO, self::MANUAL];
    }

    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return $value === self::MANUAL ? self::MANUAL : self::AUTO;
    }
}
