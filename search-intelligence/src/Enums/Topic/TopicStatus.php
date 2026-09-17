<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Topic;

final class TopicStatus
{
    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::ACTIVE, self::ARCHIVED];
    }
}
