<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Contracts;

final class PublishingTaxonomyCatalogResult
{
    /**
     * @param  list<array{id: int, name: string, parent: int}>  $items
     */
    public function __construct(
        public readonly string $taxonomy,
        public readonly array $items,
        public readonly bool $ok,
        public readonly string $code = 'ok',
        public readonly string $message = '',
    ) {}

    /**
     * @param  list<array{id: int, name: string, parent: int}>  $items
     */
    public static function ok(string $taxonomy, array $items): self
    {
        return new self($taxonomy, $items, true, 'ok', '');
    }

    public static function unavailable(string $taxonomy, string $code, string $message): self
    {
        return new self($taxonomy, [], false, $code, $message);
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        $ids = [];
        foreach ($this->items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
