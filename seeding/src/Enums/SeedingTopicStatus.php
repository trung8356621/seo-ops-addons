<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Enums;

enum SeedingTopicStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Shared = 'shared';
    case Active = 'active';
    case Paused = 'paused';
    case Done = 'done';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Pending => 'Chờ bắt đầu',
            self::Shared, self::Active => 'Đang chạy',
            self::Paused => 'Tạm dừng',
            self::Done => 'Hoàn thành',
            self::Cancelled => 'Đã hủy',
            self::Archived => 'Lưu trữ',
        };
    }

    public function isFeedRunnable(): bool
    {
        return match ($this) {
            self::Shared, self::Active, self::Pending => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function feedVisibleValues(): array
    {
        return [
            self::Shared->value,
            self::Active->value,
            self::Pending->value,
        ];
    }
}
