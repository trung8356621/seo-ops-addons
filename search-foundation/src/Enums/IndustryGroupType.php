<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Enums;

/**
 * V1 Industry Group taxonomy types projected from Match & Research.
 * Stable project term: Industry Group.
 */
enum IndustryGroupType: string
{
    case Products = 'products';
    case ProductFamilies = 'product_families';
    case Materials = 'materials';
    case Services = 'services';
    case Audiences = 'audiences';
    case UseCases = 'use_cases';
    case Features = 'features';
    case AdjacentProducts = 'adjacent_products';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function tryFromGroup(?string $group): ?self
    {
        if ($group === null || $group === '') {
            return null;
        }

        return self::tryFrom($group);
    }

    public function label(): string
    {
        return match ($this) {
            self::Products => 'Products',
            self::ProductFamilies => 'Product Families',
            self::Materials => 'Materials',
            self::Services => 'Services',
            self::Audiences => 'Audiences',
            self::UseCases => 'Use Cases',
            self::Features => 'Features',
            self::AdjacentProducts => 'Adjacent Products',
        };
    }
}
