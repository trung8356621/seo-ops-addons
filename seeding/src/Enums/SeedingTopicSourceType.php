<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Enums;

enum SeedingTopicSourceType: string
{
    case Manual = 'manual';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Thủ công',
            self::Other => 'Khác',
        };
    }
};
