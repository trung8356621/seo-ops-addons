<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Enums;

enum SeedingTopicStatus: string
{
    case Draft = 'draft';
    case Shared = 'shared';
    case Active = 'active';
    case Done = 'done';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Shared, self::Active => 'Đã chia sẻ',
            self::Done => 'Hoàn tất',
            self::Archived => 'Lưu trữ',
        };
    }
}
