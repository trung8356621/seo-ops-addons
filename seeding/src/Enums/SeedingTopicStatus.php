<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Enums;

enum SeedingTopicStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'DRAFT',
            self::Active => 'ACTIVE',
            self::Done => 'DONE',
        };
    }
}
