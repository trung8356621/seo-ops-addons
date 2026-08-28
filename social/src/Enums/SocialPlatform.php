<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Enums;

enum SocialPlatform: string
{
    case Facebook = 'facebook';
    case LinkedIn = 'linkedin';
    case X = 'x';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::LinkedIn => 'LinkedIn',
            self::X => 'X',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::YouTube => 'YouTube',
            self::Other => 'Other',
        };
    }

    public function compactLabel(): string
    {
        return match ($this) {
            self::Facebook => 'f',
            self::LinkedIn => 'in',
            self::X => 'x',
            self::Instagram => 'ig',
            self::TikTok => 'tt',
            self::YouTube => 'yt',
            self::Other => '·',
        };
    }

    /** @return list<self> */
    public static function selectable(): array
    {
        return [
            self::Facebook,
            self::LinkedIn,
            self::X,
            self::Instagram,
            self::TikTok,
            self::YouTube,
            self::Other,
        ];
    }
}
