<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
use App\Models\Site;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;

final class DomainOverviewService
{
    /**
     * @return array{
     *     read_token_masked: string,
     *     migration_token_masked: string,
     *     has_read_token: bool,
     *     has_migration_token: bool,
     *     platform: string,
     *     seo_plugin: string,
     *     seo_plugin_fetched_at: string,
     * }
     */
    public function getApiTokenSummary(Site $site): array
    {
        $site->loadMissing('metas');
        $platform = (string) ($site->getMeta('seo_platform') ?? 'wordpress');
        $read = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        $migration = trim((string) ($site->getMeta('seo_migration_token') ?? ''));

        return [
            'read_token_masked' => $this->maskToken($read),
            'migration_token_masked' => $this->maskToken($migration),
            'has_read_token' => $read !== '',
            'has_migration_token' => $migration !== '',
            'platform' => $platform,
            'seo_plugin' => trim((string) ($site->getMeta('seo_plugin') ?? '')),
            'seo_plugin_fetched_at' => trim((string) ($site->getMeta('seo_wp_plugin_info_fetched_at') ?? '')),
        ];
    }

    /**
     * @return array{read_token: string, migration_token: string}
     */
    public function getApiTokensPlain(Site $site): array
    {
        $site->loadMissing('metas');

        return [
            'read_token' => trim((string) ($site->getMeta('seo_read_token') ?? '')),
            'migration_token' => trim((string) ($site->getMeta('seo_migration_token') ?? '')),
        ];
    }

    /**
     * Phân bố điểm SEO theo nhóm (cho biểu đồ tròn).
     *
     * @return array{
     *     total: int,
     *     scored: int,
     *     segments: list<array{label: string, key: string, count: int, color: string}>,
     * }
     */
    public function getScoreDistribution(int $siteId): array
    {
        $base = SeoArticle::query()->where('site_id', $siteId)->countsTowardSeoScore();
        $total = (clone $base)->count();
        $scored = (clone $base)->whereNotNull('seo_score')->count();

        if ($scored === 0) {
            return [
                'total' => $total,
                'scored' => 0,
                'segments' => [],
            ];
        }

        $row = (clone $base)
            ->whereNotNull('seo_score')
            ->select(DB::raw('
                SUM(CASE WHEN seo_score < 50 THEN 1 ELSE 0 END) as poor,
                SUM(CASE WHEN seo_score >= 50 AND seo_score < 70 THEN 1 ELSE 0 END) as fair,
                SUM(CASE WHEN seo_score >= 70 AND seo_score < 90 THEN 1 ELSE 0 END) as good,
                SUM(CASE WHEN seo_score >= 90 THEN 1 ELSE 0 END) as excellent
            '))
            ->first();

        $segments = [
            ['label' => '0–49', 'key' => 'poor', 'count' => (int) ($row->poor ?? 0), 'color' => '#ef4444'],
            ['label' => '50–69', 'key' => 'fair', 'count' => (int) ($row->fair ?? 0), 'color' => '#f59e0b'],
            ['label' => '70–89', 'key' => 'good', 'count' => (int) ($row->good ?? 0), 'color' => '#3b82f6'],
            ['label' => '90–100', 'key' => 'excellent', 'count' => (int) ($row->excellent ?? 0), 'color' => '#22c55e'],
        ];

        return [
            'total' => $total,
            'scored' => $scored,
            'segments' => $segments,
        ];
    }

    public function buildArticlesFilterUrl(int $siteId, string $band): string
    {
        return $this->appendArticlesTableFilters(ArticleResource::panelUrl('index'), [
            'site_id' => ['value' => (string) $siteId],
            'seo_score_band' => ['value' => $band],
        ]);
    }

    /**
     * Posts list: SEO-eligible inventory missing effective focus keyword
     * (same set as Domain General Focus Keyword Coverage card).
     */
    public function buildArticlesFilterUrlForMissingFocusKeyword(int $siteId): string
    {
        return $this->appendArticlesTableFilters(ArticleResource::panelUrl('index'), [
            'site_id' => ['value' => (string) $siteId],
            'focus_keyword_status' => ['value' => 'missing'],
        ]);
    }

    public function buildArticlesFilterUrlForLink(int $siteId, string $url, string $type): string
    {
        return $this->appendArticlesTableFilters(ArticleResource::panelUrl('index'), [
            'site_id' => ['value' => (string) $siteId],
            'seo_link' => [
                'url' => $url,
                'type' => $type,
            ],
        ]);
    }

    /**
     * Bài viết gắn từ khóa và có ít nhất một link nội bộ trong nội dung.
     */
    public function buildArticlesFilterUrlForKeyword(int $siteId, int $keywordId): string
    {
        return $this->buildArticlesFilterUrlForInternalAnchorKeyword($siteId, $keywordId);
    }

    public function buildArticlesFilterUrlForMainKeyword(int $siteId, int $keywordId): string
    {
        return $this->appendArticlesTableFilters(ArticleResource::panelUrl('index'), [
            'site_id' => ['value' => (string) $siteId],
            'keyword' => [
                'keyword_id' => (string) $keywordId,
                'usage' => 'main',
            ],
        ]);
    }

    public function buildArticlesFilterUrlForInternalAnchorKeyword(int $siteId, int $keywordId): string
    {
        return $this->appendArticlesTableFilters(ArticleResource::panelUrl('index'), [
            'site_id' => ['value' => (string) $siteId],
            'keyword' => [
                'keyword_id' => (string) $keywordId,
                'usage' => 'internal_link',
            ],
        ]);
    }

    public function buildArticlesFilterUrlForCategory(int $categoryWpId, ?int $siteId = null): string
    {
        $filters = [
            'category_id' => ['value' => (string) $categoryWpId],
        ];

        if ($siteId !== null && $siteId > 0) {
            $filters['site_id'] = ['value' => (string) $siteId];
        }

        return ArticleResource::panelUrl('index').'?'.http_build_query([
            'tab' => 'posts',
            'tableFilters' => $filters,
        ]);
    }

    /**
     * @param  array<string, array<string, string>>  $tableFilters
     */
    private function appendArticlesTableFilters(string $base, array $tableFilters): string
    {
        $query = http_build_query(['tableFilters' => $tableFilters]);

        return $base.(str_contains($base, '?') ? '&' : '?').$query;
    }

    /**
     * @return array{scored: int, avg_score: float|null, min_score: float|null, max_score: float|null}
     */
    public function getScoringStatistics(int $siteId): array
    {
        $base = SeoArticle::query()
            ->where('site_id', $siteId)
            ->countsTowardSeoScore()
            ->whereNotNull('seo_score');
        $scored = (clone $base)->count();

        if ($scored === 0) {
            return [
                'scored' => 0,
                'avg_score' => null,
                'min_score' => null,
                'max_score' => null,
            ];
        }

        return [
            'scored' => $scored,
            'avg_score' => round((float) (clone $base)->avg('seo_score'), 1),
            'min_score' => round((float) (clone $base)->min('seo_score'), 1),
            'max_score' => round((float) (clone $base)->max('seo_score'), 1),
        ];
    }

    /**
     * @return array{
     *     articles: int,
     *     products: int,
     *     categories: int,
     *     product_categories: int,
     *     other: int,
     *     total: int,
     *     wp_posts: int,
     *     wp_pages: int,
     *     wp_articles_total: int,
     *     wp_categories: int,
     *     article_gap: int
     * }
     */
    public function getSyncStatistics(int $siteId): array
    {
        $base = SeoArticle::query()->where('site_id', $siteId);

        // "articles" keeps the legacy meaning: every non-term post + page.
        $articles = $this->countNonTerm($base, ContentType::Post)
            + $this->countNonTerm($base, ContentType::Page);
        $products = $this->countNonTerm($base, ContentType::Product);
        $categories = $this->countTerm($base, ContentType::Post);
        $productCategories = $this->countTerm($base, ContentType::Product);

        $total = (clone $base)->count();
        $other = max(0, $total - ($articles + $products + $categories + $productCategories));

        $wpManifest = $this->resolveWpManifestCounts($siteId);
        $wpPosts = (int) ($wpManifest['counts']['article'] ?? 0);
        $wpPages = (int) ($wpManifest['counts']['page'] ?? 0);
        $wpArticlesTotal = $wpPosts + $wpPages;
        $wpCategories = (int) ($wpManifest['counts']['category'] ?? 0);

        $wpPostTypeCounts = $this->getWpPostTypeCounts($siteId);

        return [
            'articles' => $articles,
            'products' => $products,
            'categories' => $categories,
            'product_categories' => $productCategories,
            'other' => $other,
            'total' => $total,
            'wp_posts' => $wpPosts,
            'wp_pages' => $wpPages,
            'wp_articles_total' => $wpArticlesTotal,
            'wp_categories' => $wpCategories,
            'article_gap' => max(0, $wpArticlesTotal - $articles),
            'wp_post_type_counts' => $wpPostTypeCounts,
        ];
    }

    /**
     * @param  EloquentBuilder<SeoArticle>  $base
     */
    private function countNonTerm(EloquentBuilder $base, ContentType $type): int
    {
        $query = ArticleContentClassification::scopeContentType(clone $base, $type);

        return ArticleContentClassification::scopeNonTerm($query)->count();
    }

    /**
     * @param  EloquentBuilder<SeoArticle>  $base
     */
    private function countTerm(EloquentBuilder $base, ContentType $type): int
    {
        $query = ArticleContentClassification::scopeContentType(clone $base, $type);

        return ArticleContentClassification::scopeIsTerm($query, true)->count();
    }

    /**
     * Count articles by raw WordPress post type from article_meta.
     *
     * @return array<string, int>  e.g. ['post' => 122, 'page' => 5, 'product' => 79, 'portfolio' => 12]
     */
    public function getWpPostTypeCounts(int $siteId): array
    {
        if (! \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('article_meta')) {
            return [];
        }

        return \Omnichannel\Addons\Content\Models\ArticleMeta::query()
            ->join('articles', 'articles.id', '=', 'article_meta.article_id')
            ->where('articles.site_id', $siteId)
            ->where('article_meta.meta_key', 'wp_post_type')
            ->whereNotNull('article_meta.meta_value')
            ->where('article_meta.meta_value', '!=', '')
            ->groupBy('article_meta.meta_value')
            ->pluck(\Illuminate\Support\Facades\DB::raw('COUNT(*)'), 'article_meta.meta_value')
            ->map(static fn (mixed $v): int => (int) $v)
            ->all();
    }

    /**
     * @return array{counts: array<string, int>, totals: array<string, int>}
     */
    private function resolveWpManifestCounts(int $siteId): array
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return ['counts' => [], 'totals' => []];
        }

        $raw = $site->getMeta('seo_wp_manifest_counts');
        if (! is_string($raw) || $raw === '') {
            return ['counts' => [], 'totals' => []];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return ['counts' => [], 'totals' => []];
        }

        return [
            'counts' => is_array($decoded['counts'] ?? null) ? $decoded['counts'] : [],
            'totals' => is_array($decoded['totals'] ?? null) ? $decoded['totals'] : [],
        ];
    }

    /**
     * @return Collection<int, object{id: int, phrase: string, articles_count: int}>
     */
    public function getTopKeywords(int $siteId, int $limit = 8): Collection
    {
        $query = Keyword::query()
            ->forSite($siteId)
            ->join('seo_link_maps', 'seo_link_maps.keyword_id', '=', 'keywords.id')
            ->join('articles', function ($join) use ($siteId): void {
                $join->on('articles.id', '=', 'seo_link_maps.source_article_id')
                    ->where('articles.site_id', '=', $siteId)
                    ->whereNull('articles.deleted_at');
            })
            ->select('keywords.id', 'keywords.phrase')
            ->selectRaw('COUNT(DISTINCT articles.id) as articles_count')
            ->groupBy('keywords.id', 'keywords.phrase');

        InternalAnchorKeywordFilter::applyExcludeLinkLikePhrases($query);

        return $query
            ->orderByDesc('articles_count')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, object{url: string, type: string, articles_count: int}>
     */
    public function getTopLinks(int $siteId, int $limit = 8): Collection
    {
        return $this->linksGroupedQuery($siteId)
            ->limit($limit)
            ->get();
    }

    public function paginateLinks(int $siteId, int $perPage = 25): LengthAwarePaginator
    {
        return $this->linksGroupedQuery($siteId)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateKeywords(int $siteId, int $perPage = 25): LengthAwarePaginator
    {
        $query = Keyword::query()
            ->forSite($siteId);

        InternalAnchorKeywordFilter::applyExcludeLinkLikePhrases($query);

        return $query
            ->withCount([
                'mainArticles as main_articles_count',
                'linkMaps as linked_articles_count' => static fn ($mapQuery) => $mapQuery
                    ->whereHas(
                        'sourceArticle',
                        static fn ($articleQuery) => $articleQuery
                            ->where('site_id', $siteId)
                            ->whereNull('deleted_at'),
                    ),
            ])
            ->orderByDesc('linked_articles_count')
            ->orderByDesc('main_articles_count')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function linksGroupedQuery(int $siteId): QueryBuilder
    {
        $urlKeySql = "COALESCE(NULLIF(seo_link_maps.target_external_url, ''), CONCAT('article:', seo_link_maps.target_article_id))";

        $inner = SeoLinkMap::query()
            ->join('articles', function ($join) use ($siteId): void {
                $join->on('articles.id', '=', 'seo_link_maps.source_article_id')
                    ->where('articles.site_id', '=', $siteId)
                    ->whereNull('articles.deleted_at');
            })
            ->select([
                'seo_link_maps.id',
                'seo_link_maps.source_article_id',
            ])
            ->selectRaw("{$urlKeySql} as url_key")
            ->selectRaw('seo_link_maps.link_type as link_type');

        return DB::connection((new SeoLinkMap)->getConnectionName())
            ->query()
            ->fromSub($inner, 'link_rows')
            ->selectRaw('MIN(link_rows.id) as id')
            ->selectRaw('link_rows.url_key as url')
            ->selectRaw('link_rows.link_type as type')
            ->selectRaw('COUNT(DISTINCT link_rows.source_article_id) as articles_count')
            ->groupBy('link_rows.url_key', 'link_rows.link_type')
            ->orderByDesc('articles_count');
    }

    /**
     * @return array{
     *     short_description_preview: string,
     *     cta_count: int,
     *     links_count: int,
     *     has_content: bool,
     * }
     */
    public function getTechnicalSeoSummary(Site $site): array
    {
        $ctx = app(SiteDomainPromptContextService::class)->getForSite($site);
        $desc = trim((string) ($ctx['short_description'] ?? ''));
        $preview = $desc === '' ? '' : mb_substr($desc, 0, 160).(mb_strlen($desc) > 160 ? '…' : '');

        return [
            'short_description_preview' => $preview,
            'cta_count' => count($ctx['cta'] ?? []),
            'links_count' => count($ctx['links'] ?? []),
            'has_content' => $desc !== '' || count($ctx['cta'] ?? []) > 0 || count($ctx['links'] ?? []) > 0,
        ];
    }

    public function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '—';
        }

        $len = mb_strlen($token);
        if ($len <= 3) {
            return str_repeat('•', max(0, $len - 3)).$token;
        }

        return str_repeat('•', min(24, $len - 3)).mb_substr($token, -3);
    }

    public function isSiteSynced(int $siteId): bool
    {
        return SeoArticle::query()->where('site_id', $siteId)->exists();
    }

    /**
     * @return array{
     *     status: string,
     *     label: string,
     *     color: string,
     *     detail: string,
     * }
     */
    public function getBridgeConnectionStatus(Site $site): array
    {
        $api = $this->getApiTokenSummary($site);
        $hasRead = (bool) ($api['has_read_token'] ?? false);
        $hasMigration = (bool) ($api['has_migration_token'] ?? false);
        $fetchedAt = trim((string) ($api['seo_plugin_fetched_at'] ?? ''));

        if (! $hasRead || ! $hasMigration) {
            return [
                'status' => 'disconnected',
                'label' => __('seo-content-ai::filament.dashboard.bridge_disconnected'),
                'color' => 'danger',
                'detail' => __('seo-content-ai::filament.dashboard.bridge_missing_tokens'),
            ];
        }

        if ($fetchedAt !== '') {
            return [
                'status' => 'connected',
                'label' => __('seo-content-ai::filament.dashboard.bridge_connected'),
                'color' => 'success',
                'detail' => __('seo-content-ai::filament.dashboard.bridge_last_check', ['time' => $fetchedAt]),
            ];
        }

        if ($this->isSiteSynced((int) $site->getKey())) {
            return [
                'status' => 'connected',
                'label' => __('seo-content-ai::filament.dashboard.bridge_connected'),
                'color' => 'success',
                'detail' => __('seo-content-ai::filament.dashboard.bridge_synced_content'),
            ];
        }

        return [
            'status' => 'warning',
            'label' => __('seo-content-ai::filament.dashboard.bridge_pending'),
            'color' => 'warning',
            'detail' => __('seo-content-ai::filament.dashboard.bridge_tokens_only'),
        ];
    }
}
