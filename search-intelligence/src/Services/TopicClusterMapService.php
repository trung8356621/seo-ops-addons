<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;

final class TopicClusterMapService
{
    public function __construct(
        private readonly WordPressArticleContentService $wpContent,
    ) {}

    /**
     * @return list<array{
     *     id: int,
     *     phrase: string,
     *     volume: int|null,
     *     difficulty: int|null,
     *     active_links_count: int,
     *     children: list<array{
     *         id: int,
     *         phrase: string,
     *         volume: int|null,
     *         difficulty: int|null,
     *         active_links_count: int,
     *     }>,
     * }>
     */
    public function buildKeywordTree(?int $scopedSiteId = null): array
    {
        $scopedSiteId ??= SeoAccessControl::globalSiteId();

        $linkMapsCount = static fn (int $siteId): \Closure => static fn ($query) => $query
            ->when(
                $siteId > 0,
                static fn ($mapQuery) => $mapQuery->whereHas(
                    'sourceArticle',
                    static fn ($articleQuery) => $articleQuery->where('site_id', $siteId),
                ),
            );

        $roots = Keyword::query()
            ->whereNull('parent_id')
            ->when(
                $scopedSiteId !== null && $scopedSiteId > 0,
                static fn ($query) => $query->forSite($scopedSiteId),
            )
            ->with([
                'children' => function ($query) use ($scopedSiteId, $linkMapsCount): void {
                    if ($scopedSiteId !== null && $scopedSiteId > 0) {
                        $query->forSite($scopedSiteId);
                    }

                    $query
                        ->withCount(['linkMaps as active_links_count' => $linkMapsCount((int) ($scopedSiteId ?? 0))])
                        ->orderBy('phrase');
                },
            ])
            ->withCount(['linkMaps as active_links_count' => $linkMapsCount((int) ($scopedSiteId ?? 0))])
            ->orderBy('phrase')
            ->limit(150)
            ->get();

        return $roots
            ->map(fn (Keyword $keyword): array => $this->mapTreeNode($keyword, includeChildren: true))
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *     id: int,
     *     phrase: string,
     *     volume: int|null,
     *     difficulty: int|null,
     *     active_links_count: int,
     *     children_count: int,
     * }>
     */
    public function buildPillarList(?int $scopedSiteId = null): array
    {
        $scopedSiteId ??= SeoAccessControl::globalSiteId();

        $linkMapsCount = static fn (int $siteId): \Closure => static fn ($query) => $query
            ->when(
                $siteId > 0,
                static fn ($mapQuery) => $mapQuery->whereHas(
                    'sourceArticle',
                    static fn ($articleQuery) => $articleQuery->where('site_id', $siteId),
                ),
            );

        $pillars = Keyword::query()
            ->whereNull('parent_id')
            ->whereHas('children', function ($query) use ($scopedSiteId): void {
                if ($scopedSiteId !== null && $scopedSiteId > 0) {
                    $query->forSite($scopedSiteId);
                }
            })
            ->when(
                $scopedSiteId !== null && $scopedSiteId > 0,
                static fn ($query) => $query->forSite($scopedSiteId),
            )
            ->withCount(['children', 'linkMaps as active_links_count' => $linkMapsCount((int) ($scopedSiteId ?? 0))])
            ->orderBy('phrase')
            ->limit(200)
            ->get();

        return $pillars
            ->map(function (Keyword $keyword): array {
                $node = $this->mapTreeNode($keyword, includeChildren: false);
                $node['children_count'] = (int) ($keyword->children_count ?? 0);

                return $node;
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *     site_id: int,
     *     domain: string,
     *     domain_initials: string,
     *     pillar: array{
     *         has_article: bool,
     *         article_id: int|null,
     *         title: string|null,
     *         url: string|null,
     *         edit_url: string|null,
     *     },
     *     children: list<array{
     *         keyword_id: int,
     *         phrase: string,
     *         linked: bool,
     *         source_article_id: int|null,
     *         source_title: string|null,
     *         status_label: string,
     *     }>,
     * }>
     */
    public function buildDomainCluster(int $keywordId): array
    {
        if ($keywordId <= 0) {
            return [];
        }

        /** @var Keyword|null $keyword */
        $keyword = Keyword::query()
            ->with(['children' => static fn ($query) => $query->orderBy('phrase'), 'parent'])
            ->find($keywordId);

        if (! $keyword instanceof Keyword) {
            return [];
        }

        $pillarKeyword = $this->resolvePillarKeyword($keyword);

        $siteIds = $this->resolveClusterSiteIds($pillarKeyword);
        if ($siteIds === []) {
            return [];
        }

        $sites = Site::query()
            ->whereIn('id', $siteIds)
            ->orderBy('domain')
            ->get(['id', 'domain', 'ssl']);

        $cards = [];

        foreach ($sites as $site) {
            if (! $site instanceof Site) {
                continue;
            }

            $siteId = (int) $site->getKey();
            $cards[] = [
                'site_id' => $siteId,
                'domain' => $this->normalizeDomainLabel((string) $site->domain),
                'domain_initials' => $this->resolveDomainInitials((string) $site->domain),
                'pillar' => $this->resolvePillarState($pillarKeyword, $siteId),
                'children' => $this->resolveChildLinkStatuses($pillarKeyword, $siteId),
            ];
        }

        return $cards;
    }

    private function resolvePillarKeyword(Keyword $keyword): Keyword
    {
        if ($keyword->parent_id !== null && $keyword->relationLoaded('parent') && $keyword->parent instanceof Keyword) {
            return $keyword->parent;
        }

        if ($keyword->parent_id !== null) {
            $parent = Keyword::query()->find((int) $keyword->parent_id);
            if ($parent instanceof Keyword) {
                return $parent;
            }
        }

        return $keyword;
    }

    /**
     * @return list<int>
     */
    private function resolveClusterSiteIds(Keyword $keyword): array
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null && $globalSiteId > 0) {
            return [(int) $globalSiteId];
        }

        $accessible = SeoAccessControl::accessibleSiteIds();
        $keywordIds = collect([(int) $keyword->id])
            ->merge($keyword->children->pluck('id'))
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $fromLinkMaps = SeoLinkMap::query()
            ->whereIn('keyword_id', $keywordIds)
            ->join('articles', 'articles.id', '=', 'seo_link_maps.source_article_id')
            ->distinct()
            ->pluck('articles.site_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0);

        $fromMainArticles = SeoArticle::query()
            ->whereIn('id', function ($query) use ($keywordIds): void {
                $query->selectRaw('CAST(meta_value AS UNSIGNED)')
                    ->from('keyword_meta')
                    ->whereIn('keyword_id', $keywordIds)
                    ->where('meta_key', KeywordMetaKey::MainArticleId->value);
            })
            ->distinct()
            ->pluck('site_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0);

        return $fromLinkMaps
            ->merge($fromMainArticles)
            ->unique()
            ->filter(static fn (int $siteId): bool => $accessible === [] || in_array($siteId, $accessible, true))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     has_article: bool,
     *     article_id: int|null,
     *     title: string|null,
     *     url: string|null,
     *     edit_url: string|null,
     * }
     */
    private function resolvePillarState(Keyword $keyword, int $siteId): array
    {
        $article = $keyword->mainArticles()
            ->where('articles.site_id', $siteId)
            ->first(['articles.id', 'articles.site_id', 'articles.title', 'articles.slug']);

        if (! $article instanceof SeoArticle) {
            return [
                'has_article' => false,
                'article_id' => null,
                'title' => null,
                'url' => null,
                'edit_url' => null,
            ];
        }

        $permalink = trim($this->wpContent->resolvePermalink($article));

        return [
            'has_article' => true,
            'article_id' => (int) $article->id,
            'title' => trim((string) ($article->title ?? '')) ?: null,
            'url' => $permalink !== '' ? $permalink : null,
            'edit_url' => ArticleResource::getUrl('edit', ['record' => $article->id], panel: ArticleResource::panelId()),
        ];
    }

    /**
     * @return list<array{
     *     keyword_id: int,
     *     phrase: string,
     *     linked: bool,
     *     source_article_id: int|null,
     *     source_title: string|null,
     *     status_label: string,
     * }>
     */
    private function resolveChildLinkStatuses(Keyword $keyword, int $siteId): array
    {
        $pillar = $this->resolvePillarState($keyword, $siteId);
        $pillarArticleId = (int) ($pillar['article_id'] ?? 0);
        $pillarUrl = is_string($pillar['url'] ?? null) ? trim($pillar['url']) : '';
        $normalizedPillarUrl = $this->normalizeUrlForCompare($pillarUrl);

        return $keyword->children
            ->map(function (Keyword $child) use ($siteId, $pillarArticleId, $normalizedPillarUrl): array {
                $sourceArticle = $this->resolveSourceArticleForKeywordOnSite($child, $siteId);

                $linked = false;
                if (
                    $sourceArticle instanceof SeoArticle
                    && $pillarArticleId > 0
                ) {
                    $linked = $this->childLinksToPillar((int) $sourceArticle->id, $pillarArticleId, $normalizedPillarUrl);
                }

                return [
                    'keyword_id' => (int) $child->id,
                    'phrase' => (string) $child->phrase,
                    'linked' => $linked,
                    'source_article_id' => $sourceArticle instanceof SeoArticle ? (int) $sourceArticle->id : null,
                    'source_title' => $sourceArticle instanceof SeoArticle
                        ? trim((string) ($sourceArticle->title ?? ''))
                        : null,
                    'status_label' => $linked
                        ? __('seo-content-ai::filament.keyword.cluster_link_detected')
                        : __('seo-content-ai::filament.keyword.cluster_unlinked'),
                ];
            })
            ->values()
            ->all();
    }

    private function resolveSourceArticleForKeywordOnSite(Keyword $keyword, int $siteId): ?SeoArticle
    {
        $article = $keyword->mainArticles()
            ->where('articles.site_id', $siteId)
            ->first(['articles.id', 'articles.site_id', 'articles.title', 'articles.slug']);

        if ($article instanceof SeoArticle) {
            return $article;
        }

        $sourceArticleId = SeoLinkMap::query()
            ->where('keyword_id', $keyword->id)
            ->whereNotNull('source_article_id')
            ->whereHas(
                'sourceArticle',
                static fn ($query) => $query->where('site_id', $siteId),
            )
            ->orderBy('id')
            ->value('source_article_id');

        if (! is_numeric($sourceArticleId)) {
            return null;
        }

        $resolved = SeoArticle::query()->find((int) $sourceArticleId, ['id', 'site_id', 'title', 'slug']);

        return $resolved instanceof SeoArticle ? $resolved : null;
    }

    private function childLinksToPillar(int $sourceArticleId, int $pillarArticleId, string $normalizedPillarUrl): bool
    {
        return SeoLinkMap::query()
            ->where('source_article_id', $sourceArticleId)
            ->where('status', '!=', SeoLinkMapStatus::Ignored)
            ->where(function ($query) use ($pillarArticleId, $normalizedPillarUrl): void {
                $query->where('target_article_id', $pillarArticleId);

                if ($normalizedPillarUrl !== '') {
                    $query->orWhereRaw('LOWER(TRIM(COALESCE(target_external_url, ""))) = ?', [$normalizedPillarUrl]);
                }
            })
            ->exists();
    }

    /**
     * @return array{
     *     id: int,
     *     phrase: string,
     *     volume: int|null,
     *     difficulty: int|null,
     *     active_links_count: int,
     *     children?: list<array<string, mixed>>,
     * }
     */
    private function mapTreeNode(Keyword $keyword, bool $includeChildren = false): array
    {
        $node = [
            'id' => (int) $keyword->id,
            'phrase' => (string) $keyword->phrase,
            'volume' => $this->resolveVolume($keyword),
            'difficulty' => $this->resolveDifficulty($keyword),
            'active_links_count' => (int) ($keyword->active_links_count ?? 0),
        ];

        if ($includeChildren) {
            $node['children'] = $keyword->children
                ->map(fn (Keyword $child): array => $this->mapTreeNode($child, includeChildren: false))
                ->values()
                ->all();
        }

        return $node;
    }

    private function resolveVolume(Keyword $keyword): ?int
    {
        $metrics = $keyword->metrics;
        if (is_array($metrics) && isset($metrics['search_volume']) && is_numeric($metrics['search_volume'])) {
            return (int) $metrics['search_volume'];
        }

        $volume = $keyword->volume;

        return $volume !== null ? (int) $volume : null;
    }

    private function resolveDifficulty(Keyword $keyword): ?int
    {
        $metrics = $keyword->metrics;
        if (is_array($metrics) && isset($metrics['difficulty']) && is_numeric($metrics['difficulty'])) {
            return (int) round((float) $metrics['difficulty']);
        }

        $siteId = (int) (SeoAccessControl::globalSiteId() ?? 0);
        if ($siteId <= 0) {
            return null;
        }

        $difficulty = app(KeywordMetaRepository::class)->getSiteDifficulty((int) $keyword->id, $siteId);

        return $difficulty !== null ? (int) round($difficulty) : null;
    }

    private function normalizeDomainLabel(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', trim(strtolower($domain))) ?? '';

        return rtrim(str_replace('www.', '', $domain), '/');
    }

    private function resolveDomainInitials(string $domain): string
    {
        $label = $this->normalizeDomainLabel($domain);
        if ($label === '') {
            return '?';
        }

        $parts = array_values(array_filter(explode('.', $label)));

        return strtoupper(substr($parts[0] ?? $label, 0, 2));
    }

    private function normalizeUrlForCompare(string $url): string
    {
        $url = trim(strtolower($url));

        return $url !== '' ? rtrim($url, '/') : '';
    }
}
