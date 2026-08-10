<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;

/**
 * gallery_ready / gallery_source contract for Product Gallery Mode 1.
 */
final class ProductGalleryReadyState
{
    public const VARIABLE_KEY = 'product_gallery';

    public const META_READY = 'product_gallery_ready';

    public const META_SOURCE = 'product_gallery_source';

    public const META_STATE_JSON = 'product_gallery_mode1_state';

    /** @deprecated use ProductGalleryArtifactRole::ORIGINAL */
    public const ROLE_ORIGINAL = ProductGalleryArtifactRole::ORIGINAL;

    /** @deprecated use ProductGalleryArtifactRole::GENERATED_SPRITE */
    public const ROLE_GENERATED_SPRITE = ProductGalleryArtifactRole::GENERATED_SPRITE;

    /** @deprecated use ProductGalleryArtifactRole::GENERATED_CHILD */
    public const ROLE_GENERATED_CHILD = ProductGalleryArtifactRole::GENERATED_CHILD;

    public const ARTIFACT_ROLE_KEY = ProductGalleryArtifactRole::KEY;

    public const ORIGIN_GENERATE_INPUT = 'generate_input';

    public const ORIGIN_ALBUM_BEFORE_GENERATE = 'album_before_generate';

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function readFromVariables(array $variables): array
    {
        $block = is_array($variables[self::VARIABLE_KEY] ?? null)
            ? $variables[self::VARIABLE_KEY]
            : [];

        return self::normalizeBlock($block);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public static function normalizeBlock(array $block): array
    {
        return (new ProductGalleryStateNormalizer)->normalize($block);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public static function mergeIntoVariables(array $variables, array $patch): array
    {
        $current = self::readFromVariables($variables);
        $merged = array_replace_recursive($current, $patch);
        $variables[self::VARIABLE_KEY] = self::normalizeBlock($merged);

        return $variables;
    }

    public static function tagArtifactRole(SeoMedia $media, string $role): void
    {
        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        $variables[self::ARTIFACT_ROLE_KEY] = $role;
        $media->update(['prompt_variables' => $variables]);
    }

    public static function artifactRole(SeoMedia $media): ?string
    {
        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        $role = trim((string) ($variables[self::ARTIFACT_ROLE_KEY] ?? ''));

        return $role !== '' ? $role : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function mirrorToArticle(SeoArticle $article, array $state): void
    {
        $normalized = self::normalizeBlock($state);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_READY],
            ['meta_value' => ($normalized['gallery_ready'] ?? false) ? '1' : '0'],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_SOURCE],
            ['meta_value' => (string) ($normalized['gallery_source'] ?? ProductGallerySource::Pending->value)],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_STATE_JSON],
            ['meta_value' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'],
        );

        $article->unsetRelation('articleMetas');
    }

    public static function isReadyOnArticle(SeoArticle $article): bool
    {
        $article->loadMissing('articleMetas');
        $raw = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_READY)?->meta_value ?? ''));

        if ($raw === '1' || strtolower($raw) === 'true') {
            return true;
        }

        // Legacy: infer from album without rewriting DB.
        $state = self::readFromArticle($article);

        return (bool) ($state['gallery_ready'] ?? false);
    }

    public static function sourceOnArticle(SeoArticle $article): string
    {
        $state = self::readFromArticle($article);

        return (string) ($state['gallery_source'] ?? ProductGallerySource::Pending->value);
    }

    /**
     * @return array<string, mixed>
     */
    public static function readFromArticle(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = (string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_STATE_JSON)?->meta_value ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        if (is_array($decoded)) {
            return self::normalizeBlock($decoded);
        }

        $readyRaw = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_READY)?->meta_value ?? ''));
        $sourceRaw = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_SOURCE)?->meta_value ?? ''));

        return self::normalizeBlock([
            'gallery_ready' => $readyRaw === '1' || strtolower($readyRaw) === 'true',
            'gallery_source' => $sourceRaw !== '' ? $sourceRaw : null,
        ]);
    }

    /**
     * @param  list<array{id?: int, url?: string}>  $items
     * @return array{
     *     media_ids: list<int>,
     *     urls: list<string>,
     *     captured_at: string,
     *     origin: string
     * }
     */
    public static function buildFallbackSnapshot(array $items, string $origin): array
    {
        $mediaIds = [];
        $urls = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            $url = trim((string) ($item['url'] ?? ''));
            if ($id > 0) {
                $mediaIds[] = $id;
            }
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return [
            'media_ids' => array_values(array_unique($mediaIds)),
            'urls' => array_values(array_unique($urls)),
            'captured_at' => gmdate('c'),
            'origin' => $origin,
        ];
    }
}
