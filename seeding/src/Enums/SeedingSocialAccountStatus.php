<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Enums;

enum SeedingSocialAccountStatus: string
{
    case Active = 'active';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Locked => 'Locked',
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

        return self::tryFrom(strtolower(trim($raw)));
    }
}
