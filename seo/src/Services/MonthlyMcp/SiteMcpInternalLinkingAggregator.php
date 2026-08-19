<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpEligibleContentScope;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

/**
 * Site-level internal linking from seo_link_maps (incoming edges to target articles).
 *
 * @return array{
 *   total_internal_links: ?int,
 *   linked_articles: ?int,
 *   average_links_per_linked_article: ?float,
 *   articles_without_internal_links: ?int,
 *   eligible_articles: ?int,
 *   articles_single_internal_link: ?int,
 *   top_linked_articles: list<array{title: string, internal_links: int}>,
 *   available: bool,
 *   source: string,
 *   warnings: list<string>
 * }
 */
final class SiteMcpInternalLinkingAggregator
{
    /**
     * @return array{
     *   total_internal_links: ?int,
     *   linked_articles: ?int,
     *   average_links_per_linked_article: ?float,
     *   articles_without_internal_links: ?int,
     *   eligible_articles: ?int,
     *   articles_single_internal_link: ?int,
     *   top_linked_articles: list<array{title: string, internal_links: int}>,
     *   available: bool,
     *   source: string,
     *   warnings: list<string>
     * }
     */
    public function aggregate(Site $site): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            return $this->fromLinkAnalysisSnapshotOnly($site, ['seo_link_maps table missing']);
        }

        $siteId = (int) $site->id;
        $eligibleIds = $this->eligibleArticleIds($siteId);
        if ($eligibleIds === []) {
            return $this->fromLinkAnalysisSnapshotOnly($site, ['no eligible articles']);
        }

        $maps = SeoLinkMap::query()
            ->where('link_type', SeoLinkMapType::Internal)
            ->where('status', '!=', SeoLinkMapStatus::Ignored)
            ->whereNotNull('target_article_id')
            ->whereHas('sourceArticle', static function ($query) use ($siteId): void {
                $query->where('site_id', $siteId)
                    ->where('status', '!=', 'trash')
                    ->whereNull('deleted_at');
            })
            ->whereIn('target_article_id', $eligibleIds)
            ->get(['target_article_id']);

        if ($maps->isEmpty()) {
            $fallback = $this->fromLinkAnalysisSnapshotOnly($site, []);
            if ($fallback['available']) {
                return $fallback;
            }

            return [
                'total_internal_links' => 0,
                'linked_articles' => 0,
                'average_links_per_linked_article' => 0.0,
                'articles_without_internal_links' => count($eligibleIds),
                'eligible_articles' => count($eligibleIds),
                'articles_single_internal_link' => 0,
                'top_linked_articles' => [],
                'available' => true,
                'source' => 'link_maps',
                'warnings' => ['no internal link maps found; counts confirmed zero'],
            ];
        }

        $incomingByTarget = [];
        foreach ($maps as $map) {
            $targetId = (int) $map->target_article_id;
            $incomingByTarget[$targetId] = ($incomingByTarget[$targetId] ?? 0) + 1;
        }

        $totalInternalLinks = $maps->count();
        $linkedArticles = count($incomingByTarget);
        $singleLink = 0;
        foreach ($incomingByTarget as $count) {
            if ($count === 1) {
                $singleLink++;
            }
        }
        $eligibleCount = count($eligibleIds);
        $withoutLinks = max(0, $eligibleCount - $linkedArticles);
        $average = $linkedArticles > 0 ? round($totalInternalLinks / $linkedArticles, 1) : null;

        arsort($incomingByTarget);
        $topIds = array_slice(array_keys($incomingByTarget), 0, 5);
        $titles = SeoArticle::query()->whereIn('id', $topIds)->pluck('title', 'id');
        $top = [];
        foreach ($topIds as $articleId) {
            $title = trim((string) ($titles[$articleId] ?? ''));
            if ($title === '') {
                $title = 'Article #'.$articleId;
            }
            $top[] = [
                'title' => $title,
                'internal_links' => (int) ($incomingByTarget[$articleId] ?? 0),
            ];
        }

        return [
            'total_internal_links' => $totalInternalLinks,
            'linked_articles' => $linkedArticles,
            'average_links_per_linked_article' => $average,
            'articles_without_internal_links' => $withoutLinks,
            'eligible_articles' => $eligibleCount,
            'articles_single_internal_link' => $singleLink,
            'top_linked_articles' => $top,
            'available' => true,
            'source' => 'link_maps',
            'warnings' => [],
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{
     *   total_internal_links: ?int,
     *   linked_articles: ?int,
     *   average_links_per_linked_article: ?float,
     *   articles_without_internal_links: ?int,
     *   eligible_articles: ?int,
     *   articles_single_internal_link: ?int,
     *   top_linked_articles: list<array{title: string, internal_links: int}>,
     *   available: bool,
     *   source: string,
     *   warnings: list<string>
     * }
     */
    private function fromLinkAnalysisSnapshotOnly(Site $site, array $warnings): array
    {
        $snap = SiteSyncSiteMeta::getJson($site, 'seo_link_analysis_snapshot') ?? [];
        $total = array_key_exists('internal_links', $snap) ? (int) $snap['internal_links'] : null;
        if ($total === null) {
            return [
                'total_internal_links' => null,
                'linked_articles' => null,
                'average_links_per_linked_article' => null,
                'articles_without_internal_links' => null,
                'eligible_articles' => null,
                'articles_single_internal_link' => null,
                'top_linked_articles' => [],
                'available' => false,
                'source' => 'unavailable',
                'warnings' => array_merge($warnings, ['internal linking unavailable in this snapshot']),
            ];
        }

        return [
            'total_internal_links' => $total,
            'linked_articles' => null,
            'average_links_per_linked_article' => null,
            'articles_without_internal_links' => null,
            'eligible_articles' => null,
            'articles_single_internal_link' => null,
            'top_linked_articles' => [],
            'available' => true,
            'source' => 'link_analysis_snapshot',
            'warnings' => array_merge($warnings, [
                'only total internal links available from link analysis snapshot; per-article metrics require seo_link_maps',
            ]),
        ];
    }

    /**
     * @return list<int>
     */
    private function eligibleArticleIds(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return [];
        }

        $query = SeoArticle::query()->where('site_id', $siteId);
        $query = McpEligibleContentScope::applyToSeoArticleTarget($query);

        return $query
            ->countsTowardSeoScore()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
