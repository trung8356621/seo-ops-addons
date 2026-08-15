<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums;

enum KeywordReviewStatus: string
{
    case Active = 'active';
    case Warning = 'warning';
    case Danger = 'danger';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public function isNegative(): bool
    {
        return $this->isManualError();
    }

    public function isManualError(): bool
    {
        return $this === self::Warning || $this === self::Danger;
    }
}
