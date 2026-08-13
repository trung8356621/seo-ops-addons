<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Post-type-aware category taxonomy for Publishing readiness.
 *
 * Does NOT blindly require WordPress `category` for every post type.
 * Laravel-only articles are evaluated from type + staged category IDs — no wp_post_id required.
 */
final class PublishingTaxonomyResolver
{
    public const TAXONOMY_CATEGORY = 'category';

    public const TAXONOMY_PRODUCT_CATEGORY = 'product_category';

    /**
     * @return array{
     *     taxonomy: string|null,
     *     wp_taxonomy: string|null,
     *     required: bool,
     *     reason: string
     * }
     */
    public static function resolve(mixed $postType, mixed $recordType = null): array
    {
        $candidates = array_values(array_filter([
            self::normalizeRawType($postType),
            self::normalizeRawType($recordType),
        ], static fn (string $value): bool => $value !== ''));

        if ($candidates === []) {
            $candidates = [''];
        }

        // Prefer the most specific identity when Livewire normalizes page → article.
        foreach (['page', 'product', 'e-commerce', 'product_category', 'product_cat', 'category'] as $specific) {
            if (in_array($specific, $candidates, true)) {
                return self::resolveRaw($specific);
            }
        }

        return self::resolveRaw($candidates[0]);
    }

    /**
     * @return array{
     *     taxonomy: string|null,
     *     wp_taxonomy: string|null,
     *     required: bool,
     *     reason: string
     * }
     */
    private static function resolveRaw(string $raw): array
    {
        if (in_array($raw, ['category', 'product_category', 'product_cat'], true)) {
            return [
                'taxonomy' => null,
                'wp_taxonomy' => null,
                'required' => false,
                'reason' => 'taxonomy_entity',
            ];
        }

        if ($raw === 'page') {
            return [
                'taxonomy' => null,
                'wp_taxonomy' => null,
                'required' => false,
                'reason' => 'page',
            ];
        }

        if (in_array($raw, ['product', 'e-commerce'], true)) {
            return [
                'taxonomy' => self::TAXONOMY_PRODUCT_CATEGORY,
                'wp_taxonomy' => 'product_cat',
                'required' => true,
                'reason' => 'product',
            ];
        }

        if (in_array($raw, ['article', 'post', ''], true)) {
            return [
                'taxonomy' => self::TAXONOMY_CATEGORY,
                'wp_taxonomy' => 'category',
                'required' => true,
                'reason' => 'post',
            ];
        }

        return [
            'taxonomy' => null,
            'wp_taxonomy' => null,
            'required' => false,
            'reason' => 'custom_or_unknown',
        ];
    }

    public static function requiresCategory(mixed $postType, mixed $recordType = null): bool
    {
        return self::resolve($postType, $recordType)['required'] === true;
    }

    public static function categoryTaxonomyKey(mixed $postType, mixed $recordType = null): ?string
    {
        return self::resolve($postType, $recordType)['taxonomy'];
    }

    private static function normalizeRawType(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
