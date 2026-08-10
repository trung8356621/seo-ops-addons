<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

/**
 * Canonical product_cat identity for Site MCP.
 *
 * Verified rows require taxonomy + term_id + known parent_term_id (including 0).
 * Never invent parent from URL/title/missing fields.
 */
final class SiteMcpProductCatIdentity
{
    public const CAPABILITY = 'product_category_taxonomy_export';

    public const AVAILABILITY_AVAILABLE = 'available';

    public const AVAILABILITY_UNAVAILABLE = 'unavailable';

    public const AVAILABILITY_INCOMPLETE = 'incomplete';

    public const WARNING_CAPABILITY_MISSING = 'PRODUCT_CATEGORY_TAXONOMY_CAPABILITY_MISSING';

    /**
     * Normalize a WordPress/sync taxonomy payload into canonical product_cat shape.
     * Returns null when identity is incomplete or not product_cat.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function normalizeVerified(array $payload): ?array
    {
        $taxonomy = mb_strtolower(trim((string) ($payload['taxonomy'] ?? $payload['wp_taxonomy'] ?? $payload['wp_post_type'] ?? '')));
        $type = mb_strtolower(trim((string) ($payload['type'] ?? $payload['page_type'] ?? '')));
        if ($taxonomy === '' && ($type === 'product_category' || $type === 'product_cat')) {
            $taxonomy = 'product_cat';
        }
        if ($taxonomy !== 'product_cat' && $type !== 'product_category' && $type !== 'product_cat') {
            return null;
        }

        if ($type === 'product' || $type === 'products') {
            return null;
        }

        $termId = (int) ($payload['term_id'] ?? $payload['wp_id'] ?? $payload['wp_post_id'] ?? 0);
        if ($termId <= 0) {
            return null;
        }

        if (! array_key_exists('parent_term_id', $payload) && ! array_key_exists('parent_id', $payload)
            && ! array_key_exists('wp_parent_id', $payload)) {
            return null;
        }

        $rawParent = array_key_exists('parent_term_id', $payload)
            ? $payload['parent_term_id']
            : (array_key_exists('parent_id', $payload)
                ? $payload['parent_id']
                : $payload['wp_parent_id']);

        if ($rawParent === null || $rawParent === '') {
            return null;
        }

        $parentTermId = (int) $rawParent;

        return [
            'taxonomy' => 'product_cat',
            'term_id' => $termId,
            'parent_term_id' => $parentTermId,
            'name' => trim((string) ($payload['name'] ?? $payload['title'] ?? '')),
            'slug' => trim((string) ($payload['slug'] ?? '')),
            'url' => trim((string) ($payload['url'] ?? $payload['permalink'] ?? '')),
            'post_count' => (int) ($payload['post_count'] ?? $payload['count'] ?? $payload['wp_term_count'] ?? 0),
            'page_type' => 'taxonomy',
            'verified' => true,
            'source' => (string) ($payload['source'] ?? 'taxonomy_sync'),
        ];
    }

    /**
     * Deduplicate by taxonomy + term_id. Prefer verified; enrich URL from later rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function dedupeByTaxonomyTermId(array $rows): array
    {
        $byKey = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $taxonomy = (string) ($row['taxonomy'] ?? '');
            $termId = (int) ($row['term_id'] ?? 0);
            if ($taxonomy === '' || $termId <= 0) {
                continue;
            }
            $key = $taxonomy.':'.$termId;
            if (! isset($byKey[$key])) {
                $byKey[$key] = $row;
                continue;
            }

            $existing = $byKey[$key];
            $existingVerified = (bool) ($existing['verified'] ?? false);
            $incomingVerified = (bool) ($row['verified'] ?? false);

            if ($incomingVerified && ! $existingVerified) {
                $merged = $row;
                if (trim((string) ($merged['url'] ?? '')) === '' && trim((string) ($existing['url'] ?? '')) !== '') {
                    $merged['url'] = $existing['url'];
                }
                $byKey[$key] = $merged;
                continue;
            }

            if (! $incomingVerified && $existingVerified) {
                if (trim((string) ($existing['url'] ?? '')) === '' && trim((string) ($row['url'] ?? '')) !== '') {
                    $existing['url'] = $row['url'];
                    $byKey[$key] = $existing;
                }
                continue;
            }

            if (trim((string) ($existing['url'] ?? '')) === '' && trim((string) ($row['url'] ?? '')) !== '') {
                $existing['url'] = $row['url'];
                $byKey[$key] = $existing;
            }
        }

        return array_values($byKey);
    }

    /**
     * @param  list<array<string, mixed>>  $verifiedCategories
     * @return array{
     *     product_cat_total: int,
     *     root_product_cat: int,
     *     child_product_cat: int
     * }
     */
    public static function countTree(array $verifiedCategories): array
    {
        $root = 0;
        $child = 0;
        foreach ($verifiedCategories as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['taxonomy'] ?? '') !== 'product_cat') {
                continue;
            }
            if ((int) ($row['term_id'] ?? 0) <= 0) {
                continue;
            }
            if (! array_key_exists('parent_term_id', $row)) {
                continue;
            }
            if ((int) $row['parent_term_id'] === 0) {
                $root++;
            } else {
                $child++;
            }
        }

        return [
            'product_cat_total' => $root + $child,
            'root_product_cat' => $root,
            'child_product_cat' => $child,
        ];
    }

    /**
     * @return self::AVAILABILITY_*
     */
    public static function resolveAvailability(
        bool $capabilityExportAvailable,
        bool $capabilityKnown,
        int $verifiedTotal,
        int $incompleteTotal,
    ): string {
        if ($capabilityKnown && ! $capabilityExportAvailable && $verifiedTotal === 0) {
            return self::AVAILABILITY_UNAVAILABLE;
        }

        if ($verifiedTotal === 0 && $incompleteTotal > 0) {
            return self::AVAILABILITY_INCOMPLETE;
        }

        if (! $capabilityKnown && $verifiedTotal === 0 && $incompleteTotal === 0) {
            return self::AVAILABILITY_UNAVAILABLE;
        }

        return self::AVAILABILITY_AVAILABLE;
    }
}
