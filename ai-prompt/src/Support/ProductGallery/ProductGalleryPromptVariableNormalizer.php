<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support\ProductGallery;

/**
 * Normalize coordinator / canary variables into Hook input_schema keys.
 * Never embeds binary/base64 — only scalar metadata.
 */
final class ProductGalleryPromptVariableNormalizer
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function forPlan(array $variables, ?string $fallbackTitle = null): array
    {
        $title = self::firstNonEmpty($variables, ['product_title', 'title'], $fallbackTitle ?? '');

        return self::stripBinaries([
            'product_title' => $title,
            'keyword' => self::stringOrEmpty($variables['keyword'] ?? null),
            'product_description' => self::firstNonEmpty($variables, ['product_description', 'description']),
            'product_attributes' => self::stringOrEmpty($variables['product_attributes'] ?? null),
            'product_identity' => self::firstNonEmpty($variables, ['product_identity'], $title),
            'negative_constraints' => self::constraintsToString($variables['negative_constraints'] ?? null),
            'requested_image_count' => max(1, (int) ($variables['requested_image_count'] ?? 6)),
            'language' => self::stringOrEmpty($variables['language'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function forParent(array $variables, ?string $fallbackTitle = null): array
    {
        $title = self::firstNonEmpty($variables, ['product_title', 'title'], $fallbackTitle ?? '');
        $features = $variables['distinctive_features'] ?? null;
        if (is_array($features)) {
            $features = implode('; ', array_map('strval', $features));
        }

        $originalIds = $variables['original_media_ids'] ?? $variables['original_media_snapshot_ids'] ?? [];
        $idsMeta = '';
        if (is_array($originalIds) && $originalIds !== []) {
            $idsMeta = implode(',', array_map(static fn ($id): string => (string) (int) $id, $originalIds));
        } elseif (is_string($originalIds)) {
            $idsMeta = $originalIds;
        }

        return self::stripBinaries([
            'product_title' => $title,
            'product_category' => self::firstNonEmpty($variables, ['product_category', 'category']),
            'product_brand' => self::firstNonEmpty($variables, ['product_brand', 'brand']),
            'primary_color' => self::stringOrEmpty($variables['primary_color'] ?? null),
            'secondary_color' => self::stringOrEmpty($variables['secondary_color'] ?? null),
            'material' => self::stringOrEmpty($variables['material'] ?? null),
            'product_shape' => self::firstNonEmpty($variables, ['product_shape', 'shape']),
            'distinctive_features' => self::stringOrEmpty($features),
            'negative_constraints' => self::constraintsToString($variables['negative_constraints'] ?? null),
            'original_media_ids' => $idsMeta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function forChild(array $variables, ?string $fallbackTitle = null): array
    {
        $title = self::firstNonEmpty($variables, ['product_title', 'title'], $fallbackTitle ?? '');
        $features = $variables['distinctive_features'] ?? null;
        if (is_array($features)) {
            $features = implode('; ', array_map('strval', $features));
        }

        return self::stripBinaries([
            'product_title' => $title,
            'product_identity' => self::firstNonEmpty($variables, ['product_identity'], $title),
            'primary_color' => self::stringOrEmpty($variables['primary_color'] ?? null),
            'secondary_color' => self::stringOrEmpty($variables['secondary_color'] ?? null),
            'material' => self::stringOrEmpty($variables['material'] ?? null),
            'product_shape' => self::firstNonEmpty($variables, ['product_shape', 'shape']),
            'distinctive_features' => self::stringOrEmpty($features),
            'negative_constraints' => self::constraintsToString($variables['negative_constraints'] ?? null),
            'shot_key' => self::stringOrEmpty($variables['shot_key'] ?? null),
            'shot_label' => self::stringOrEmpty($variables['shot_label'] ?? null),
            'aspect_ratio' => self::stringOrEmpty($variables['aspect_ratio'] ?? null),
            'shot_instruction' => self::firstNonEmpty($variables, ['shot_instruction', 'input']),
            'parent_media_id' => self::stringOrEmpty($variables['parent_media_id'] ?? null),
        ]);
    }

    /**
     * @return list<string>
     */
    public static function requiredKeysForHook(string $hookKey): array
    {
        return match ($hookKey) {
            'product.gallery.plan' => ['product_title', 'keyword', 'product_description', 'product_attributes', 'product_identity', 'negative_constraints', 'requested_image_count', 'language'],
            'product.gallery.parent.generate' => ['product_title', 'product_category', 'product_brand', 'primary_color', 'secondary_color', 'material', 'product_shape', 'distinctive_features', 'negative_constraints'],
            'product.gallery.child.generate' => ['product_title', 'product_identity', 'primary_color', 'secondary_color', 'material', 'product_shape', 'distinctive_features', 'negative_constraints', 'shot_key', 'shot_label', 'aspect_ratio', 'shot_instruction', 'parent_media_id'],
            default => [],
        };
    }

    /**
     * Safe sample values for preview / doctor compile (no binary).
     *
     * @return array<string, mixed>
     */
    public static function sampleForHook(string $hookKey): array
    {
        $base = [
            'product_title' => 'Sample Product Bag',
            'keyword' => 'leather tote',
            'product_description' => 'A compact leather tote for daily use.',
            'product_attributes' => 'color=brown; size=M',
            'product_identity' => 'brown leather tote with gold zipper',
            'negative_constraints' => 'no logo redesign; no color change',
            'requested_image_count' => 3,
            'language' => 'vi',
            'product_category' => 'Bags',
            'product_brand' => 'DemoBrand',
            'primary_color' => 'brown',
            'secondary_color' => 'gold',
            'material' => 'leather',
            'product_shape' => 'tote',
            'distinctive_features' => 'gold zipper; dual straps',
            'shot_key' => 'front',
            'shot_label' => 'Mặt trước',
            'aspect_ratio' => '1:1',
            'shot_instruction' => 'Front view, centered product, clean background.',
            'parent_media_id' => '0',
            'original_media_ids' => '1,2',
        ];

        return match ($hookKey) {
            'product.gallery.plan' => self::forPlan($base),
            'product.gallery.parent.generate' => self::forParent($base),
            'product.gallery.child.generate' => self::forChild($base),
            default => $base,
        };
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  list<string>  $keys
     */
    private static function firstNonEmpty(array $variables, array $keys, string $fallback = ''): string
    {
        foreach ($keys as $key) {
            $value = trim(self::stringOrEmpty($variables[$key] ?? null));
            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    private static function stringOrEmpty(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private static function constraintsToString(mixed $value): string
    {
        if (is_array($value)) {
            return implode('; ', array_values(array_filter(array_map(
                static fn ($row): string => trim((string) $row),
                $value,
            ), static fn (string $row): bool => $row !== '')));
        }

        return self::stringOrEmpty($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function stripBinaries(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            // Drop accidental base64 / data-uri blobs from text variables.
            if (str_starts_with($value, 'data:image/') || (strlen($value) > 4000 && preg_match('/^[A-Za-z0-9+\/=]+$/', $value) === 1)) {
                $payload[$key] = '';
            }
        }

        return $payload;
    }
}
