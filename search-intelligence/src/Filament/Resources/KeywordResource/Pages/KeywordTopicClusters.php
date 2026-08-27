<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\DissolvesTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ReclustersTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicIdeaCoverageService;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;

final class KeywordTopicClusters extends Page
{
    use DissolvesTopicClusters;
    use HasKeywordWorkspaceNavigation;
    use ReclustersTopicClusters;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.topic-cluster-index';

    protected static bool $shouldRegisterNavigation = false;

    public string $clusterSearch = '';

    public string $coverageFilter = '';

    public bool $hasArticles = false;

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->redirectToFirstAccessibleDomainIfNeeded();
    }

    /**
     * Topic Cluster / DNA / recluster require one concrete domain — never All.
     */
    private function redirectToFirstAccessibleDomainIfNeeded(): bool
    {
        if ($this->resolveKeywordWorkspaceSiteId() !== null) {
            return false;
        }

        $first = SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->first();
        if (! $first instanceof Site) {
            return false;
        }

        $this->redirect(
            app(DomainContextResolver::class)->appendSiteToUrl(
                request()->fullUrl(),
                (int) $first->getKey(),
            ),
            navigate: false,
        );

        return true;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.topic_cluster_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'workspace-2';
    }

    /**
     * @return array<string, int>
     */
    public function getSummary(): array
    {
        return app(KeywordClusterQuery::class)->summary($this->resolveKeywordWorkspaceSiteId());
    }

    public function getClusters()
    {
        $paginator = app(KeywordClusterQuery::class)->paginateClusters(
            $this->resolveKeywordWorkspaceSiteId(),
            [
                'search' => $this->clusterSearch,
                'coverage' => $this->coverageFilter,
                'has_articles' => $this->hasArticles,
            ],
        );

        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null || $siteId <= 0 || ! app()->bound(TopicIdeaCoverageService::class)) {
            return $paginator;
        }

        $keys = [];
        foreach ($paginator->items() as $item) {
            if (is_array($item) && isset($item['cluster_key'])) {
                $keys[] = (string) $item['cluster_key'];
            }
        }

        $summaries = app(TopicIdeaCoverageService::class)->summariesForKeys($siteId, $keys);
        $items = [];
        foreach ($paginator->items() as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = (string) ($item['cluster_key'] ?? '');
            $summary = $summaries[$key] ?? [
                'dna_branch_count' => 0,
                'covered_branch_count' => 0,
                'uncovered_branch_count' => 0,
            ];
            $items[] = [
                ...$item,
                'dna_branch_count' => $summary['dna_branch_count'],
                'covered_branch_count' => $summary['covered_branch_count'],
                'uncovered_branch_count' => $summary['uncovered_branch_count'],
            ];
        }

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => $paginator->path(), 'query' => request()->query()],
        );
    }

    public function clusterUrl(string $clusterKey): string
    {
        return app(DomainContextResolver::class)->appendSiteToUrl(
            KeywordResource::getUrl('cluster', ['clusterKey' => $clusterKey]),
            $this->resolveKeywordWorkspaceSiteId(),
        );
    }

    public function unclusteredUrl(): string
    {
        return app(KeywordClusterQuery::class)->unclusteredListUrl($this->resolveKeywordWorkspaceSiteId());
    }
}
