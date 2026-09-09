<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpProductCatIdentity;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpProductCatLiveSource;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Full verified product_cat taxonomy for Internal Link suggestions.
 *
 * Intentionally NOT Site MCP root-only projection (parent_term_id = 0).
 * Includes root + child + grandchild at every depth.
 * Excludes individual WooCommerce products (wp_is_term = 0).
 */
final class ArticleInternalLinkProductCatCatalog
{
    public const SOURCE = 'product_cat';

    /** @var array<string, mixed> */
    private array $lastDebug = [];

    public function __construct(
        private readonly ?SiteMcpProductCatLiveSource $liveSource = null,
    ) {}

    /**
     * @return list<array{
     *     taxonomy: string,
     *     term_id: int,
     *     parent_term_id: int,
     *     name: string,
     *     slug: string,
     *     url: string,
     *     seo_title: string,
     *     depth: int,
     *     ancestors: list<int>,
     *     article_id: int,
     *     verified: bool,
     *     source: string,
     *     health: string,
     *     name_norm: string,
     *     core_phrase_norm: string,
     *     seo_title_norm: string,
     *     slug_norm: string
     * }>
     */
    public function forSite(int $siteId): array
    {
        $this->resetDebug();

        if ($siteId <= 0) {
            return [];
        }

        $cacheKey = 'article_link_suggest.product_cat_catalog.v2.'.$siteId;
        /** @var array{rows: list<array<string, mixed>>, incomplete: int, source: string} $cached */
        $cached = Cache::remember($cacheKey, 300, function () use ($siteId): array {
            $local = $this->loadFromLocalArticles($siteId);
            if ($local['rows'] !== []) {
                return [
                    'rows' => $local['rows'],
                    'incomplete' => $local['incomplete'],
                    'source' => 'local_articles_taxonomy_sync',
                ];
            }

            // Sites without synced term articles — reuse cached full taxonomy export
            // (identity normalization only; never Site MCP root-only projection).
            $live = $this->loadFromLiveExport($siteId);

            return [
                'rows' => $live['rows'],
                'incomplete' => $live['incomplete'],
                'source' => $live['rows'] === []
                    ? 'empty'
                    : 'taxonomy_export_cached',
            ];
        });

        $eligible = $this->filterEligible(
            is_array($cached['rows'] ?? null) ? $cached['rows'] : [],
            (int) ($cached['incomplete'] ?? 0),
        );
        $this->lastDebug['source'] = (string) ($cached['source'] ?? 'local_articles_taxonomy_sync');

        return $eligible;
    }

    /**
     * Build catalog from fixture/raw rows (tests + sync-shaped payloads).
     * Does NOT apply parent_term_id = 0 exclusion.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function fromRows(array $rows): array
    {
        $this->resetDebug();
        $built = $this->buildNormalizedRows($rows);

        return $this->filterEligible($built['rows'], $built['incomplete']);
    }

    /**
     * @return array<string, mixed>
     */
    public function lastDebug(): array
    {
        return $this->lastDebug;
    }

    /**
     * Strip manufacturer/business prefixes for core phrase matching.
     * Does not mutate the display name — only matching needles.
     */
    public function corePhraseNorm(string $name): string
    {
        $norm = KeywordPhraseMatcher::normalize($name);
        if ($norm === '') {
            return '';
        }

        $prefixes = [
            'cong ty may',
            'công ty may',
            'xuong may',
            'xưởng may',
            'san xuat',
            'sản xuất',
            'may',
        ];

        foreach ($prefixes as $prefix) {
            $prefixNorm = KeywordPhraseMatcher::normalize($prefix);
            if ($prefixNorm === '') {
                continue;
            }
            if (str_starts_with($norm, $prefixNorm.' ')) {
                $stripped = trim(mb_substr($norm, mb_strlen($prefixNorm)));
                if ($stripped !== '' && KeywordPhraseMatcher::countWords($stripped) >= 2) {
                    return $stripped;
                }
            }
        }

        return $norm;
    }

    private function resetDebug(): void
    {
        $this->lastDebug = [
            'product_cat_total' => 0,
            'product_cat_root' => 0,
            'product_cat_child' => 0,
            'product_cat_grandchild_or_deep' => 0,
            'product_cat_with_url' => 0,
            'product_cat_health_excluded' => 0,
            'product_cat_missing_url' => 0,
            'product_cat_incomplete_identity' => 0,
            'product_cat_after_filter' => 0,
            'source' => 'local_articles_taxonomy_sync',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterEligible(array $rows, int $incomplete): array
    {
        $eligible = [];
        $healthExcluded = 0;
        $missingUrl = 0;
        $withUrl = 0;
        $root = 0;
        $child = 0;
        $deep = 0;

        foreach ($rows as $row) {
            $depth = (int) ($row['depth'] ?? 0);
            $parent = (int) ($row['parent_term_id'] ?? 0);
            if ($parent === 0) {
                $root++;
            } elseif ($depth >= 2) {
                $deep++;
            } else {
                $child++;
            }

            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || ! SeoSuggestionUrlNormalizer::isParsableTarget($url, allowRelative: true)) {
                $missingUrl++;
                continue;
            }
            $withUrl++;

            $health = (string) ($row['health'] ?? 'unknown');
            if (in_array($health, ['404', '410', 'dead'], true)) {
                $healthExcluded++;
                continue;
            }

            $eligible[] = $row;
        }

        $this->lastDebug = [
            'product_cat_total' => count($rows),
            'product_cat_root' => $root,
            'product_cat_child' => $child,
            'product_cat_grandchild_or_deep' => $deep,
            'product_cat_with_url' => $withUrl,
            'product_cat_health_excluded' => $healthExcluded,
            'product_cat_missing_url' => $missingUrl,
            'product_cat_incomplete_identity' => $incomplete,
            'product_cat_after_filter' => count($eligible),
            'source' => (string) ($this->lastDebug['source'] ?? 'local_articles_taxonomy_sync'),
        ];

        return $eligible;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, incomplete: int}
     */
    private function loadFromLiveExport(int $siteId): array
    {
        $live = $this->liveSource;
        if ($live === null) {
            try {
                $live = app(SiteMcpProductCatLiveSource::class);
            } catch (Throwable) {
                return ['rows' => [], 'incomplete' => 0];
            }
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return ['rows' => [], 'incomplete' => 0];
        }

        $verified = $live->fetchVerifiedProductCats($site);
        $raw = [];
        foreach ($verified as $row) {
            if (! is_array($row)) {
                continue;
            }
            $raw[] = [
                'taxonomy' => 'product_cat',
                'term_id' => (int) ($row['term_id'] ?? 0),
                'parent_term_id' => (int) ($row['parent_term_id'] ?? 0),
                'name' => trim((string) ($row['name'] ?? $row['title'] ?? '')),
                'title' => trim((string) ($row['title'] ?? $row['name'] ?? '')),
                'slug' => trim((string) ($row['slug'] ?? '')),
                'url' => trim((string) ($row['url'] ?? '')),
                'permalink' => trim((string) ($row['url'] ?? '')),
                'seo_title' => trim((string) ($row['seo_title'] ?? $row['name'] ?? '')),
                'article_id' => (int) ($row['article_id'] ?? 0),
                'source' => (string) ($row['source'] ?? 'taxonomy_export'),
                'health' => 'ok',
                'wp_taxonomy' => 'product_cat',
                'type' => 'product_category',
                'page_type' => 'taxonomy',
            ];
        }

        return $this->buildNormalizedRows($raw);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, incomplete: int}
     */
    private function loadFromLocalArticles(int $siteId): array
    {
        $query = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereNotIn('status', ['trash', 'trashed', 'deleted'])
            ->notContentArchived()
            ->with([
                'wordpressLink:article_id,wp_post_id',
                'articleMetas' => static fn ($q) => $q->whereIn('meta_key', [
                    'wp_permalink',
                    'wp_parent_id',
                    'wp_taxonomy',
                    ArticleContentClassification::META_WP_POST_TYPE,
                    'seo_meta_title',
                    '_yoast_wpseo_title',
                    'rank_math_title',
                    'seo_focus_keyword',
                    'last_http_status',
                    'link_http_status',
                ]),
            ])
            ->orderBy('id');

        ArticleContentClassification::scopeContentType($query, ContentType::Product);
        ArticleContentClassification::scopeIsTerm($query, true);

        $articles = $query->get(['id', 'title', 'slug', 'status', 'site_id']);
        $raw = [];
        $incomplete = 0;

        foreach ($articles as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $metas = [];
            foreach ($article->articleMetas ?? [] as $meta) {
                $metas[(string) $meta->meta_key] = (string) $meta->meta_value;
            }

            $termId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            $name = trim((string) ($article->title ?? ''));
            $slug = trim((string) ($article->slug ?? ''), '/');
            $url = trim((string) ($metas['wp_permalink'] ?? ''));
            if ($url === '' && $slug !== '') {
                $url = '/'.ltrim($slug, '/').'/';
            }

            if (! array_key_exists('wp_parent_id', $metas) || $termId <= 0 || $name === '') {
                $incomplete++;
                continue;
            }

            $seoTitle = trim((string) (
                $metas['seo_meta_title']
                ?? $metas['_yoast_wpseo_title']
                ?? $metas['rank_math_title']
                ?? $name
            ));

            $raw[] = [
                'taxonomy' => 'product_cat',
                'term_id' => $termId,
                'parent_term_id' => (int) $metas['wp_parent_id'],
                'name' => $name,
                'title' => $name,
                'slug' => $slug,
                'url' => $url,
                'permalink' => $url,
                'seo_title' => $seoTitle,
                'article_id' => (int) $article->id,
                'source' => 'taxonomy_sync',
                'health' => $this->resolveHealth($metas),
                'wp_taxonomy' => 'product_cat',
                'type' => 'product_category',
                'page_type' => 'product_category',
            ];
        }

        $built = $this->buildNormalizedRows($raw);

        return [
            'rows' => $built['rows'],
            'incomplete' => $incomplete + $built['incomplete'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, incomplete: int}
     */
    private function buildNormalizedRows(array $rows): array
    {
        $normalized = [];
        $incomplete = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $payload = $row;
            $payload['taxonomy'] = 'product_cat';
            $payload['wp_taxonomy'] = 'product_cat';
            $payload['type'] = $payload['type'] ?? 'product_category';
            $payload['page_type'] = $payload['page_type'] ?? 'product_category';

            // Individual products never enter this catalog.
            $pageType = mb_strtolower(trim((string) ($payload['page_type'] ?? '')));
            if ($pageType === 'product' || $pageType === 'products') {
                continue;
            }

            $verified = SiteMcpProductCatIdentity::normalizeVerified($payload);
            if ($verified === null) {
                $termId = (int) ($payload['term_id'] ?? $payload['wp_id'] ?? 0);
                $name = trim((string) ($payload['name'] ?? $payload['title'] ?? ''));
                $url = trim((string) ($payload['url'] ?? $payload['permalink'] ?? ''));
                if ($termId <= 0 || $name === '' || ! array_key_exists('parent_term_id', $payload)) {
                    $incomplete++;
                    continue;
                }
                $verified = [
                    'taxonomy' => 'product_cat',
                    'term_id' => $termId,
                    'parent_term_id' => (int) $payload['parent_term_id'],
                    'name' => $name,
                    'slug' => trim((string) ($payload['slug'] ?? '')),
                    'url' => $url,
                    'post_count' => (int) ($payload['post_count'] ?? 0),
                    'page_type' => 'taxonomy',
                    'verified' => false,
                    'source' => (string) ($payload['source'] ?? 'fixture'),
                ];
            }

            $name = trim((string) ($verified['name'] ?? ''));
            $slug = trim((string) ($verified['slug'] ?? ''));
            $seoTitle = trim((string) ($payload['seo_title'] ?? $payload['title'] ?? $name));
            $url = trim((string) ($verified['url'] ?? ''));

            $normalized[] = [
                'taxonomy' => 'product_cat',
                'term_id' => (int) $verified['term_id'],
                'parent_term_id' => (int) $verified['parent_term_id'],
                'name' => $name,
                'slug' => $slug,
                'url' => $url,
                'seo_title' => $seoTitle,
                'article_id' => (int) ($payload['article_id'] ?? 0),
                'verified' => (bool) ($verified['verified'] ?? true),
                'source' => (string) ($verified['source'] ?? 'taxonomy_sync'),
                'health' => (string) ($payload['health'] ?? 'ok'),
                'name_norm' => KeywordPhraseMatcher::normalize($name),
                'core_phrase_norm' => $this->corePhraseNorm($name),
                'seo_title_norm' => KeywordPhraseMatcher::normalize($seoTitle),
                'slug_norm' => KeywordPhraseMatcher::normalize(str_replace(['-', '_'], ' ', $slug)),
            ];
        }

        return [
            'rows' => $this->attachDepthAndAncestors($normalized),
            'incomplete' => $incomplete,
        ];
    }

    /**
     * @param  array<string, string>  $metas
     */
    private function resolveHealth(array $metas): string
    {
        foreach (['last_http_status', 'link_http_status'] as $key) {
            if (! array_key_exists($key, $metas)) {
                continue;
            }
            $code = (int) $metas[$key];
            if ($code === 404 || $code === 410) {
                return (string) $code;
            }
            if ($code >= 200 && $code < 400) {
                return 'ok';
            }
        }

        return 'ok';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function attachDepthAndAncestors(array $rows): array
    {
        $byTerm = [];
        foreach ($rows as $row) {
            $termId = (int) ($row['term_id'] ?? 0);
            if ($termId > 0) {
                $byTerm[$termId] = $row;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $ancestors = [];
            $parent = (int) ($row['parent_term_id'] ?? 0);
            $guard = 0;
            while ($parent > 0 && $guard < 32) {
                $ancestors[] = $parent;
                $parentRow = $byTerm[$parent] ?? null;
                if (! is_array($parentRow)) {
                    break;
                }
                $parent = (int) ($parentRow['parent_term_id'] ?? 0);
                $guard++;
            }

            $row['ancestors'] = $ancestors;
            $row['depth'] = count($ancestors);
            $out[] = $row;
        }

        return $out;
    }
}
