<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Projection;

use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;

/**
 * Structured formatter for context slices — facts only, no AI prose.
 *
 * Final guard for all views: strips reserved presentation keys recursively.
 */
final class ContextFormatter
{
    /** @var list<string> */
    public const RESERVED_PRESENTATION_KEYS = [
        'ai_lines',
        'text',
        'note',
        'raw',
    ];

    /**
     * @return array{
     *   key: string,
     *   scope: array{site_ref: string},
     *   generated_at: string,
     *   source_updated_at: string|null,
     *   stale: bool,
     *   available: bool,
     *   data: array<string, mixed>
     * }
     */
    public function format(ContextSlice $slice): array
    {
        return [
            'key' => $slice->key,
            'scope' => $slice->scope(),
            'generated_at' => $slice->generatedAt,
            'source_updated_at' => $slice->sourceUpdatedAt,
            'stale' => $slice->stale,
            'available' => $slice->available,
            'data' => $this->compact($slice->data),
        ];
    }

    /**
     * Omit null / empty-array optional padding; keep meaningful zeros/false.
     * Strip reserved presentation keys at every nesting level.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function compact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }
            if (is_string($key) && in_array($key, self::RESERVED_PRESENTATION_KEYS, true)) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }
                if ($this->isTruncationWrapper($value)) {
                    $items = is_array($value['items'] ?? null) ? $value['items'] : [];
                    $out[$key] = [
                        'items' => $this->compactList($items),
                        'returned' => (int) $value['returned'],
                        'total' => (int) $value['total'],
                        'truncated' => (bool) $value['truncated'],
                    ];
                    continue;
                }
                if (array_is_list($value)) {
                    $compacted = $this->compactList($value);
                    if ($compacted !== []) {
                        $out[$key] = $compacted;
                    }
                    continue;
                }
                $nested = $this->compact($value);
                if ($nested !== []) {
                    $out[$key] = $nested;
                }
                continue;
            }
            if ($value === '') {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private function compactList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                if ($item === []) {
                    continue;
                }
                if (array_is_list($item)) {
                    $out[] = $this->compactList($item);
                    continue;
                }
                $out[] = $this->compact($item);
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isTruncationWrapper(array $value): bool
    {
        return array_key_exists('items', $value)
            && array_key_exists('returned', $value)
            && array_key_exists('total', $value)
            && array_key_exists('truncated', $value)
            && ! array_is_list($value);
    }
}
