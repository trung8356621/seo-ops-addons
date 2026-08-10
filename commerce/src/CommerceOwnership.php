<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce;

/**
 * Commerce domain ownership skeleton.
 * Product content stays in Content; gallery stays in Media.
 */
final class CommerceOwnership
{
    public const CAPABILITY = 'commerce.product';

    /**
     * @return list<string>
     */
    public static function ownedFields(): array
    {
        return [
            'sku',
            'price',
            'sale_price',
            'stock',
            'attributes',
            'variants',
        ];
    }
}
