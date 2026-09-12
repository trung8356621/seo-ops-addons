<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Enums;

enum SeedingSocialPlatform: string
{
    case Threads = 'threads';
    case Facebook = 'facebook';
    case TikTok = 'tiktok';
    case Pinterest = 'pinterest';
    case Reddit = 'reddit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Threads => 'Threads',
            self::Facebook => 'Facebook',
            self::TikTok => 'TikTok',
            self::Pinterest => 'Pinterest',
            self::Reddit => 'Reddit',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public static function tryFromLabelOrValue(?string $raw): ?self
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $normalized = strtolower(trim($raw));

        return self::tryFrom($normalized);
    }
}
