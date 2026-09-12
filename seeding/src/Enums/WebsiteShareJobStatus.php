<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Enums;

enum WebsiteShareJobStatus: string
{
    case Scheduled = 'scheduled';
    case Ready = 'ready';
    case HasContent = 'has_content';
    case Sharing = 'sharing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Chờ tạo nhiệm vụ',
            self::Ready => 'Chờ tạo nội dung',
            self::HasContent => 'Có nội dung',
            self::Sharing => 'Đang chia sẻ',
            self::Completed => 'Đã hoàn thành',
            self::Cancelled => 'Đã hủy',
        };
    }

    public function isVisibleInFeed(): bool
    {
        return match ($this) {
            self::Cancelled => false,
            default => true,
        };
    }
}
