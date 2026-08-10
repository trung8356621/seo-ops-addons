<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Support\Collection;

/**
 * Read-only index over an article's already-loaded `articleMetas` relation.
 *
 * Many editor code paths did `$article->articleMetas()->where('meta_key', ...)->value(...)`
 * — a fresh query every call even when the relation was already eager loaded.
 * `ArticleMetaMap::for($article)` loads the relation once (if missing) and serves
 * every subsequent lookup from the in-memory collection.
 */
final class ArticleMetaMap
{
    /** @var Collection<int, ArticleMeta> */
    private Collection $byKey;

    private function __construct(Collection $metas)
    {
        // last-write-wins per meta_key, matching ->value() semantics (first match wins there,
        // but article metas are effectively unique per key in practice).
        $this->byKey = $metas->keyBy(static fn (ArticleMeta $meta): string => (string) $meta->meta_key);
    }

    public static function for(SeoArticle $article): self
    {
        $article->loadMissing('articleMetas');

        return new self($article->articleMetas instanceof Collection ? $article->articleMetas : collect());
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $meta = $this->byKey->get($key);
        if ($meta === null) {
            return $default;
        }

        $value = $meta->meta_value;

        return $value === null ? $default : (string) $value;
    }

    public function has(string $key): bool
    {
        return $this->byKey->has($key);
    }

    public function getJson(string $key): mixed
    {
        $raw = $this->get($key);
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return json_decode($raw, true);
    }

    /**
     * First value among a set of candidate keys (mirrors ->first(fn) patterns for aliased keys).
     */
    public function getAny(array $keys, ?string $default = null): ?string
    {
        foreach ($keys as $key) {
            if ($this->byKey->has($key)) {
                return $this->get($key, $default);
            }
        }

        return $default;
    }
}
