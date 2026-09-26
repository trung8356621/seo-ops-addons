<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Projection;

use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;

/**
 * Structured formatter for context slices — facts only, no AI prose.
 */
final class ContextFormatter
{
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
     * Omit null / empty-array optional padding; keep meaningful zeros.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function compact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }
                if (array_is_list($value)) {
                    $out[$key] = $value;
                    continue;
                }
                // Truncation wrappers always keep shape.
                if (isset($value['items'], $value['returned'], $value['total'], $value['truncated'])) {
                    $out[$key] = [
                        'items' => is_array($value['items']) ? $value['items'] : [],
                        'returned' => (int) $value['returned'],
                        'total' => (int) $value['total'],
                        'truncated' => (bool) $value['truncated'],
                    ];
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
}
