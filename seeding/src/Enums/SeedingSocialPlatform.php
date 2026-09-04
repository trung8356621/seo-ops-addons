<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Enums;

enum SeedingSocialPlatform: string
{
    case Threads = 'threads';
    case Facebook = 'facebook';
    case TikTok = 'tiktok';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Threads => 'Threads',
            self::Facebook => 'Facebook',
            self::TikTok => 'TikTok',
            self::Other => 'Other',
        };
    }
}
