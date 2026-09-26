<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Projection;

/**
 * Deterministic list truncation metadata for large slice lists.
 *
 * @phpstan-type TruncatedList array{items: list<mixed>, returned: int, total: int, truncated: bool}
 */
final class ContextListSlice
{
    /**
     * @param  list<mixed>  $items
     * @return TruncatedList
     */
    public static function fromAll(array $items, int $limit): array
    {
        $limit = max(1, $limit);
        $total = count($items);
        $slice = array_slice($items, 0, $limit);

        return [
            'items' => array_values($slice),
            'returned' => count($slice),
            'total' => $total,
            'truncated' => $total > $limit,
        ];
    }
}
