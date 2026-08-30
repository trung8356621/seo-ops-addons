<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for Article classification.
 *
 * - content_type (meta): post | page | product
 * - wp_is_term (meta): WordPress post/CPT vs taxonomy term
 * - articles.parent_id: term hierarchy (NULL non-term; 0 root; else local parent article id)
 * - wp_post_type (meta): raw platform native slug (integration only)
 */
final class ArticleContentClassification
{
    public const META_CONTENT_TYPE = 'content_type';

    public const META_WP_IS_TERM = 'wp_is_term';

    public const META_WP_POST_TYPE = 'wp_post_type';

    /** @deprecated Stop writing; removed after zero readers. */
    public const META_WP_ENTITY = 'wp_entity';

    private function __construct(
        private readonly ContentType $contentType,
        private readonly bool $isTerm,
        private readonly ?string $wpPostType,
    ) {}

    public static function for(SeoArticle $article): self
    {
        $map = ArticleMetaMap::for($article);

        $contentType = ContentType::tryFromString($map->get(self::META_CONTENT_TYPE));
        if ($contentType === null) {
            $contentType = self::inferContentTypeFromLegacy($article, $map);
        }

        $isTermMeta = $map->get(self::META_WP_IS_TERM);
        if ($isTermMeta !== null && $isTermMeta !== '') {
            $isTerm = in_array(strtolower(trim($isTermMeta)), ['1', 'true', 'yes'], true);
        } else {
            $isTerm = self::inferIsTermFromLegacy($article, $map);
        }

        $wpPostType = strtolower(trim((string) ($map->get(self::META_WP_POST_TYPE) ?? '')));
        if ($wpPostType === '') {
            $wpPostType = null;
        }

        return new self($contentType, $isTerm, $wpPostType);
    }

    public function contentType(): ContentType
    {
        return $this->contentType;
    }

    public function isTerm(): bool
    {
        return $this->isTerm;
    }

    public function wpPostType(): ?string
    {
        return $this->wpPostType;
    }

    public function equals(ContentType $type): bool
    {
        return $this->contentType === $type;
    }

    /**
     * Persist canonical classification. Does not write articles.type or wp_entity.
     *
     * @param  array{
     *     content_type?: ContentType|string,
     *     wp_is_term?: bool,
     *     wp_post_type?: string|null,
     *     parent_id?: int|null,
     *     site?: Site|null
     * }  $input
     */
    public static function persist(SeoArticle $article, array $input): self
    {
        $existing = self::for($article);

        $contentType = isset($input['content_type'])
            ? (is_string($input['content_type'])
                ? ContentType::fromString($input['content_type'])
                : $input['content_type'])
            : $existing->contentType();

        if (! $contentType instanceof ContentType) {
            throw new \InvalidArgumentException('content_type must be ContentType.');
        }

        $isTerm = array_key_exists('wp_is_term', $input)
            ? (bool) $input['wp_is_term']
            : $existing->isTerm();

        $wpPostType = array_key_exists('wp_post_type', $input)
            ? self::normalizeNative((string) ($input['wp_post_type'] ?? ''))
            : $existing->wpPostType();

        if ($wpPostType === '') {
            $wpPostType = null;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_CONTENT_TYPE],
            ['meta_value' => $contentType->value],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_WP_IS_TERM],
            ['meta_value' => $isTerm ? '1' : '0'],
        );

        if ($wpPostType !== null) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => self::META_WP_POST_TYPE],
                ['meta_value' => $wpPostType],
            );
        }

        if (array_key_exists('parent_id', $input)) {
            self::assertAndSetParentId($article, $isTerm, $input['parent_id']);
        } elseif (! $isTerm) {
            $article->forceFill(['parent_id' => null])->saveQuietly();
        } elseif ($article->parent_id === null) {
            // Term without explicit parent → root.
            $article->forceFill(['parent_id' => 0])->saveQuietly();
        }

        $article->unsetRelation('articleMetas');

        return new self($contentType, $isTerm, $wpPostType);
    }

    /**
     * Resolve classification from a sync/import payload (+ optional site map).
     *
     * @param  array<string, mixed>  $item
     * @return array{content_type: ContentType, wp_is_term: bool, wp_post_type: string, parent_id: int|null}
     */
    public static function fromSyncItem(array $item, ?Site $site = null): array
    {
        $wpPostType = self::normalizeNative((string) (
            $item['wp_post_type']
            ?? $item['taxonomy']
            ?? ''
        ));

        $isTerm = self::resolveIsTermFromPayload($item);

        if ($wpPostType === '') {
            $legacyType = self::normalizeNative((string) ($item['type'] ?? ''));
            $wpPostType = match ($legacyType) {
                'product' => 'product',
                'page' => 'page',
                'category' => 'category',
                'product_category', 'product_cat' => 'product_cat',
                'article', 'post' => 'post',
                default => $legacyType !== '' ? $legacyType : 'post',
            };
        }

        $explicit = ContentType::tryFromString((string) ($item['content_type'] ?? ''));
        $contentType = $explicit ?? NativeContentTypeMapper::mapForSite($wpPostType, $site);

        $parentId = null;
        if ($isTerm) {
            if (array_key_exists('parent_article_id', $item)) {
                $parentId = (int) $item['parent_article_id'];
            } elseif (array_key_exists('parent_id', $item) || array_key_exists('parent_term_id', $item)) {
                // WP native parent term id is stored separately as wp_parent_id;
                // articles.parent_id is resolved later to local article id (0 = root).
                $raw = array_key_exists('parent_term_id', $item)
                    ? $item['parent_term_id']
                    : $item['parent_id'];
                $parentId = ($raw === null || $raw === '') ? 0 : ((int) $raw === 0 ? 0 : null);
            } else {
                $parentId = 0;
            }
        }

        return [
            'content_type' => $contentType,
            'wp_is_term' => $isTerm,
            'wp_post_type' => $wpPostType !== '' ? $wpPostType : ($isTerm ? 'category' : 'post'),
            'parent_id' => $parentId,
        ];
    }

    /**
     * Map Content Project / editor task vocabulary → canonical classification.
     *
     * @return array{content_type: ContentType, wp_is_term: bool, wp_post_type: string, parent_id: int|null}
     */
    public static function fromTaskPostType(string $taskPostType): array
    {
        $normalized = strtolower(trim($taskPostType));

        return match ($normalized) {
            'product' => [
                'content_type' => ContentType::Product,
                'wp_is_term' => false,
                'wp_post_type' => 'product',
                'parent_id' => null,
            ],
            'page' => [
                'content_type' => ContentType::Page,
                'wp_is_term' => false,
                'wp_post_type' => 'page',
                'parent_id' => null,
            ],
            'category' => [
                'content_type' => ContentType::Post,
                'wp_is_term' => true,
                'wp_post_type' => 'category',
                'parent_id' => 0,
            ],
            'product_category', 'product_cat' => [
                'content_type' => ContentType::Product,
                'wp_is_term' => true,
                'wp_post_type' => 'product_cat',
                'parent_id' => 0,
            ],
            default => [
                'content_type' => ContentType::Post,
                'wp_is_term' => false,
                'wp_post_type' => 'post',
                'parent_id' => null,
            ],
        };
    }

    /**
     * Infer classification from pre-migration rows (no network).
     */
    public static function fromLegacyRow(
        ?string $legacyType,
        ?string $wpPostType,
        ?string $wpEntity,
        ?string $wpTaxonomy = null,
    ): array {
        $legacyType = self::normalizeNative((string) ($legacyType ?? ''));
        $wpPostType = self::normalizeNative((string) ($wpPostType ?? ''));
        $wpEntity = self::normalizeNative((string) ($wpEntity ?? ''));
        $wpTaxonomy = self::normalizeNative((string) ($wpTaxonomy ?? ''));

        $isTerm = $wpEntity === 'term'
            || in_array($legacyType, ['category', 'product_category', 'product_cat'], true);

        if ($wpPostType === '') {
            $wpPostType = match (true) {
                $wpTaxonomy !== '' => $wpTaxonomy,
                $legacyType === 'product' => 'product',
                $legacyType === 'page' => 'page',
                $legacyType === 'category' => 'category',
                in_array($legacyType, ['product_category', 'product_cat'], true) => 'product_cat',
                default => 'post',
            };
        }

        $contentType = match (true) {
            $legacyType === 'page' || $wpPostType === 'page' => ContentType::Page,
            $legacyType === 'product' || $wpPostType === 'product' => ContentType::Product,
            in_array($legacyType, ['product_category', 'product_cat'], true)
                || in_array($wpPostType, ['product_cat', 'product_tag'], true)
                || $wpTaxonomy === 'product_cat' => ContentType::Product,
            $legacyType === 'category'
                || in_array($wpPostType, ['category', 'post_tag'], true)
                || $wpTaxonomy === 'category' => ContentType::Post,
            default => NativeContentTypeMapper::map($wpPostType !== '' ? $wpPostType : 'post'),
        };

        return [
            'content_type' => $contentType,
            'wp_is_term' => $isTerm,
            'wp_post_type' => $wpPostType !== '' ? $wpPostType : ($isTerm ? 'category' : 'post'),
        ];
    }

    /**
     * @param  Builder<\Omnichannel\Addons\Content\Models\SeoArticle>  $query
     * @return Builder<\Omnichannel\Addons\Content\Models\SeoArticle>
     */
    public static function scopeContentType(Builder $query, ContentType|string $type): Builder
    {
        $value = $type instanceof ContentType ? $type->value : ContentType::fromString($type)->value;

        return $query->whereHas('articleMetas', static function (Builder $meta) use ($value): void {
            $meta->where('meta_key', self::META_CONTENT_TYPE)->where('meta_value', $value);
        });
    }

    /**
     * @param  Builder<\Omnichannel\Addons\Content\Models\SeoArticle>  $query
     * @return Builder<\Omnichannel\Addons\Content\Models\SeoArticle>
     */
    public static function scopeIsTerm(Builder $query, bool $isTerm = true): Builder
    {
        $value = $isTerm ? '1' : '0';

        return $query->whereHas('articleMetas', static function (Builder $meta) use ($value): void {
            $meta->where('meta_key', self::META_WP_IS_TERM)->where('meta_value', $value);
        });
    }

    /**
     * @param  Builder<\Omnichannel\Addons\Content\Models\SeoArticle>  $query
     * @return Builder<\Omnichannel\Addons\Content\Models\SeoArticle>
     */
    public static function scopeNonTerm(Builder $query): Builder
    {
        return $query->where(static function (Builder $outer): void {
            $outer
                ->whereHas('articleMetas', static function (Builder $meta): void {
                    $meta->where('meta_key', self::META_WP_IS_TERM)->where('meta_value', '0');
                })
                ->orWhereDoesntHave('articleMetas', static function (Builder $meta): void {
                    $meta->where('meta_key', self::META_WP_IS_TERM);
                });
        });
    }

    private static function inferContentTypeFromLegacy(SeoArticle $article, ArticleMetaMap $map): ContentType
    {
        $inferred = self::fromLegacyRow(
            (string) ($article->type ?? ''),
            $map->get(self::META_WP_POST_TYPE),
            $map->get(self::META_WP_ENTITY),
            $map->get('wp_taxonomy'),
        );

        return $inferred['content_type'];
    }

    private static function inferIsTermFromLegacy(SeoArticle $article, ArticleMetaMap $map): bool
    {
        $inferred = self::fromLegacyRow(
            (string) ($article->type ?? ''),
            $map->get(self::META_WP_POST_TYPE),
            $map->get(self::META_WP_ENTITY),
            $map->get('wp_taxonomy'),
        );

        return $inferred['wp_is_term'];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function resolveIsTermFromPayload(array $item): bool
    {
        if (array_key_exists('wp_is_term', $item)) {
            $raw = $item['wp_is_term'];
            if (is_bool($raw)) {
                return $raw;
            }

            return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes'], true);
        }

        $entity = self::normalizeNative((string) ($item['wp_entity'] ?? ''));
        if ($entity === 'term') {
            return true;
        }
        if ($entity === 'post') {
            return false;
        }

        $legacyType = self::normalizeNative((string) ($item['type'] ?? ''));

        return in_array($legacyType, ['category', 'product_category', 'product_cat'], true);
    }

    private static function assertAndSetParentId(SeoArticle $article, bool $isTerm, mixed $parentId): void
    {
        if (! $isTerm) {
            if ($parentId !== null && $parentId !== '') {
                throw new \InvalidArgumentException('Non-term articles must have parent_id = NULL.');
            }
            $article->forceFill(['parent_id' => null])->saveQuietly();

            return;
        }

        if ($parentId === null || $parentId === '') {
            throw new \InvalidArgumentException('Term articles must have parent_id (0 for root).');
        }

        $article->forceFill(['parent_id' => (int) $parentId])->saveQuietly();
    }

    private static function normalizeNative(string $value): string
    {
        return strtolower(trim($value));
    }
}
