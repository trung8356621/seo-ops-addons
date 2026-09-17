<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteLink;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpProductCatIdentity;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpProductCatLiveSource;
use App\Models\Site;
use Throwable;

/**
 * Load verified product_cat link rows for Site Link Policy.
 *
 * Uses {@see SiteMcpProductCatIdentity::normalizeVerified()} — never invents root from missing parent.
 * Local article taxonomy sync first; optional live export when local yields nothing.
 */
final class VerifiedProductCatLinkSource
{
    public function __construct(
        private readonly ?SiteMcpProductCatLiveSource $liveSource = null,
    ) {}

    /**
     * All verified product_cat terms at every hierarchy depth.
     *
     * @return list<array{
     *     keyword: string,
     *     url: string,
     *     taxonomy: string,
     *     term_id: int,
     *     parent_term_id: int
     * }>
     */
    public function forSite(Site $site): array
    {
        $siteId = (int) $site->getKey();
        if ($siteId <= 0) {
            return [];
        }

        $local = $this->fromLocalArticles($siteId);
        if ($local !== []) {
            return $local;
        }

        return $this->fromLiveExport($site);
    }

    /**
     * Normalize raw rows through identity SSOT (tests + fixtures).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{
     *     keyword: string,
     *     url: string,
     *     taxonomy: string,
     *     term_id: int,
     *     parent_term_id: int
     * }>
     */
    public function fromRawRows(array $rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $payload = $row;
            $payload['taxonomy'] = 'product_cat';
            $payload['wp_taxonomy'] = 'product_cat';
            if (! isset($payload['type']) && ! isset($payload['page_type'])) {
                $payload['type'] = 'product_category';
                $payload['page_type'] = 'product_category';
            }

            $verified = SiteMcpProductCatIdentity::normalizeVerified($payload);
            if ($verified === null) {
                continue;
            }

            $keyword = trim((string) ($verified['name'] ?? ''));
            $url = trim((string) ($verified['url'] ?? ''));
            $termId = (int) ($verified['term_id'] ?? 0);
            if ($keyword === '' || $url === '' || $termId <= 0) {
                continue;
            }
            if (filter_var($url, FILTER_VALIDATE_URL) === false && ! str_starts_with($url, '/')) {
                continue;
            }

            $key = 'product_cat:'.$termId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = [
                'keyword' => $keyword,
                'url' => $url,
                'taxonomy' => 'product_cat',
                'term_id' => $termId,
                'parent_term_id' => (int) $verified['parent_term_id'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{
     *     keyword: string,
     *     url: string,
     *     taxonomy: string,
     *     term_id: int,
     *     parent_term_id: int
     * }>
     */
    private function fromLocalArticles(int $siteId): array
    {
        try {
            $query = SeoArticle::query()
                ->where('site_id', $siteId)
                ->whereNotIn('status', ['trash', 'trashed', 'deleted'])
                ->with([
                    'wordpressLink:article_id,wp_post_id',
                    'articleMetas' => static fn ($q) => $q->whereIn('meta_key', [
                        'wp_permalink',
                        'wp_parent_id',
                        'wp_taxonomy',
                        ArticleContentClassification::META_WP_POST_TYPE,
                    ]),
                ])
                ->orderBy('id');

            ArticleContentClassification::scopeContentType($query, ContentType::Product);
            ArticleContentClassification::scopeIsTerm($query, true);

            $articles = $query->get(['id', 'title', 'slug', 'status', 'site_id']);
        } catch (Throwable) {
            return [];
        }

        $raw = [];
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
            $url = trim((string) ($metas['wp_permalink'] ?? ''));
            if ($url === '') {
                $slug = trim((string) ($article->slug ?? ''), '/');
                if ($slug !== '') {
                    $url = '/'.ltrim($slug, '/').'/';
                }
            }

            $payload = [
                'taxonomy' => 'product_cat',
                'wp_taxonomy' => 'product_cat',
                'type' => 'product_category',
                'page_type' => 'product_category',
                'term_id' => $termId,
                'name' => $name,
                'title' => $name,
                'url' => $url,
                'permalink' => $url,
                'slug' => trim((string) ($article->slug ?? '')),
            ];

            // Keep missing parent distinguishable from root 0.
            if (array_key_exists('wp_parent_id', $metas)) {
                $payload['parent_term_id'] = (int) $metas['wp_parent_id'];
                $payload['wp_parent_id'] = (int) $metas['wp_parent_id'];
            }

            $raw[] = $payload;
        }

        return $this->fromRawRows($raw);
    }

    /**
     * @return list<array{
     *     keyword: string,
     *     url: string,
     *     taxonomy: string,
     *     term_id: int,
     *     parent_term_id: int
     * }>
     */
    private function fromLiveExport(Site $site): array
    {
        $live = $this->liveSource;
        if ($live === null) {
            try {
                $live = app(SiteMcpProductCatLiveSource::class);
            } catch (Throwable) {
                return [];
            }
        }

        try {
            $verified = $live->fetchVerifiedProductCats($site);
        } catch (Throwable) {
            return [];
        }

        $raw = [];
        foreach ($verified as $row) {
            if (! is_array($row)) {
                continue;
            }
            $raw[] = array_merge($row, [
                'taxonomy' => 'product_cat',
                'type' => 'product_category',
                'page_type' => 'taxonomy',
            ]);
        }

        return $this->fromRawRows($raw);
    }
}
