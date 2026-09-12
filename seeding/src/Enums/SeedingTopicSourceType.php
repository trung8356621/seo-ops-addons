<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Enums;

enum SeedingTopicSourceType: string
{
    case Manual = 'manual';
    case SeedingV1 = 'seeding_v1';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Thủ công',
            self::SeedingV1 => 'Seeding V1',
            self::Other => 'Khác',
        };
    }
}
