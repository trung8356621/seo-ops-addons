<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Projection;

use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;

/**
 * Applies view-level projection rules to a ContextSlice data payload.
 */
final class ContextProjection
{
    public const DEFAULT_LIST_LIMIT = [
        ContextView::Summary->value => 5,
        ContextView::Standard->value => 20,
        ContextView::Detail->value => 50,
    ];

    /**
     * @param  list<string>  $listKeys  Top-level data keys that are lists of items
     */
    public function project(ContextSlice $slice, ContextView $view, ?int $limit = null, array $listKeys = []): ContextSlice
    {
        $data = $this->projectData($slice->data, $view, $limit, $listKeys);

        return new ContextSlice(
            key: $slice->key,
            siteId: $slice->siteId,
            data: $data,
            sourceUpdatedAt: $slice->sourceUpdatedAt,
            generatedAt: $slice->generatedAt,
            available: $slice->available,
            stale: $slice->stale,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $listKeys
     * @return array<string, mixed>
     */
    public function projectData(array $data, ContextView $view, ?int $limit = null, array $listKeys = []): array
    {
        $effectiveLimit = $limit ?? self::DEFAULT_LIST_LIMIT[$view->value];

        if ($view === ContextView::Summary) {
            $data = $this->summaryShape($data);
        }

        foreach ($listKeys as $key) {
            if (! array_key_exists($key, $data) || ! is_array($data[$key])) {
                continue;
            }
            $items = array_is_list($data[$key]) ? $data[$key] : (is_array($data[$key]['items'] ?? null) ? $data[$key]['items'] : null);
            if (! is_array($items) || ! array_is_list($items)) {
                continue;
            }
            $data[$key] = ContextListSlice::fromAll($items, $effectiveLimit);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function summaryShape(array $data): array
    {
        // Keep counts / identity / period; drop nested prose-heavy blobs when present.
        unset($data['ai_lines'], $data['note'], $data['text'], $data['raw']);

        return $data;
    }
}
