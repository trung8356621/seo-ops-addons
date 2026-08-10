<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscDevice: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Tablet = 'tablet';

    public static function tryFromLoose(?string $value): ?self
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        if ($value === '') {
            return null;
        }

        return match ($value) {
            'desktop', 'pc' => self::Desktop,
            'mobile', 'smartphone' => self::Mobile,
            'tablet' => self::Tablet,
            default => self::tryFrom($value),
        };
    }
}
