<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto;

/**
 * Truncated list envelope — total/returned/truncated are always explicit.
 *
 * @template T
 */
final class RelationshipListSlice
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $returned,
        public readonly bool $truncated,
    ) {}

    /**
     * @param  list<T>  $all
     * @return self<T>
     */
    public static function fromAll(array $all, int $limit): self
    {
        $limit = max(1, $limit);
        $total = count($all);
        $slice = array_slice($all, 0, $limit);

        return new self(
            items: array_values($slice),
            total: $total,
            returned: count($slice),
            truncated: $total > count($slice),
        );
    }

    /**
     * @return array{total: int, returned: int, truncated: bool, items: list<T>}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'returned' => $this->returned,
            'truncated' => $this->truncated,
            'items' => $this->items,
        ];
    }
}
