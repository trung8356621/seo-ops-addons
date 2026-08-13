<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AllDomainsDashboardService
{
    public function __construct(
        private readonly DomainOverviewService $domainOverview,
    ) {}

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     edit_url: string,
     *     domain: string,
     *     domain_url: ?string,
     *     total_tasks: int,
     *     synced_tasks: int,
     *     percent: int,
     * }>
     */
    public function contentProjectsProgress(int $limit = 15): array
    {
        $siteIds = $this->visibleSiteIds();
        if ($siteIds === []) {
            return [];
        }

        $projects = SeoProject::query()
            ->with('site')
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('month')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($projects->isEmpty()) {
            return [];
        }

        $projectIds = $projects->pluck('id')->all();

        $totals = SeoProjectTask::query()
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, COUNT(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');

        $synced = SeoProjectTask::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', SeoProjectTask::STATUS_COMPLETED)
            ->whereNotNull('article_id')
            ->whereIn(
                'article_id',
                SeoArticle::query()
                    ->select('id')
                    ->hasWpPostId(),
            )
            ->selectRaw('project_id, COUNT(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');

        $rows = [];

        foreach ($projects as $project) {
            $projectId = (int) $project->getKey();
            $totalTasks = (int) ($totals[$projectId] ?? 0);
            $syncedTasks = (int) ($synced[$projectId] ?? 0);
            $percent = $totalTasks > 0
                ? (int) round(($syncedTasks / $totalTasks) * 100)
                : 0;

            $rows[] = [
                'id' => $projectId,
                'name' => trim((string) ($project->name ?: SeoProject::defaultNameFromMonth($project->month))),
                'edit_url' => SeoProjectResource::projectRecordUrl($project),
                'domain' => trim((string) ($project->site?->domain ?? '')),
                'domain_url' => $project->site_id !== null
                    ? DomainResource::getUrl('general', ['record' => (int) $project->site_id])
                    : null,
                'total_tasks' => $totalTasks,
                'synced_tasks' => $syncedTasks,
                'percent' => min(100, max(0, $percent)),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     optimized_articles: int,
     * }>
     */
    public function teamProductivity(): array
    {
        $siteIds = $this->visibleSiteIds();
        $members = $this->teamMembersQuery()->orderBy('name')->get();

        if ($members->isEmpty()) {
            return [];
        }

        $articleCounts = [];

        if ($siteIds !== []) {
            $articleCounts = SeoArticle::query()
                ->whereIn('articles.site_id', $siteIds)
                ->whereIn('articles.user_id', $members->pluck('id')->all())
                ->countsTowardSeoScore()
                // Replace scope's articles.* select — incompatible with ONLY_FULL_GROUP_BY.
                ->select('articles.user_id')
                ->selectRaw('COUNT(*) as aggregate')
                ->groupBy('articles.user_id')
                ->pluck('aggregate', 'user_id')
                ->all();
        }

        $rows = [];

        foreach ($members as $member) {
            $rows[] = [
                'id' => (int) $member->getKey(),
                'name' => (string) $member->name,
                'email' => (string) $member->email,
                'optimized_articles' => (int) ($articleCounts[$member->getKey()] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{
     *     id: int,
     *     domain: string,
     *     overview_url: string,
     *     segments: list<array{label: string, key: string, count: int, color: string, filter_url?: string}>,
     *     worst_article: array{
     *         id: int,
     *         title: string,
     *         score: float,
     *         edit_url: string,
     *     }|null,
     *     all_excellent: bool,
     * }>
     */
    public function domainsHealthOverview(): array
    {
        $sites = $this->visibleSitesQuery()->get();
        $linkScoreSegments = ! SeoAccessControl::isContentManager();
        $rows = [];

        foreach ($sites as $site) {
            $siteId = (int) $site->getKey();
            $distribution = $this->domainOverview->getScoreDistribution($siteId);
            $segments = array_values(array_filter(
                $distribution['segments'],
                static fn (array $segment): bool => ($segment['count'] ?? 0) > 0,
            ));

            if ($linkScoreSegments) {
                $segments = array_map(function (array $segment) use ($siteId): array {
                    $segment['filter_url'] = $this->domainOverview->buildArticlesFilterUrl(
                        $siteId,
                        (string) ($segment['key'] ?? ''),
                    );

                    return $segment;
                }, $segments);
            }

            $worstArticle = SeoArticle::query()
                ->where('articles.site_id', $siteId)
                ->countsTowardSeoScore()
                ->leftJoin('seo_article_profiles as sap_worst', 'sap_worst.article_id', '=', 'articles.id')
                ->whereNotNull('sap_worst.seo_score')
                ->where('sap_worst.seo_score', '>', 0)
                ->orderBy('sap_worst.seo_score')
                ->orderBy('articles.id')
                ->first(['articles.id', 'articles.title', 'sap_worst.seo_score as seo_score']);

            $worst = null;
            $allExcellent = $worstArticle === null;

            if ($worstArticle instanceof SeoArticle) {
                $worst = [
                    'id' => (int) $worstArticle->getKey(),
                    'title' => trim((string) ($worstArticle->title ?: __('seo-content-ai::filament.dashboard.all_domains_untitled_article'))),
                    'score' => (float) $worstArticle->seoProfile?->seo_score,
                    'edit_url' => ArticleResource::getUrl('edit', ['record' => (int) $worstArticle->getKey()]),
                ];
            }

            $rows[] = [
                'id' => $siteId,
                'domain' => (string) $site->domain,
                'overview_url' => DomainResource::getUrl('general', ['record' => $siteId]),
                'segments' => $segments,
                'worst_article' => $worst,
                'all_excellent' => $allExcellent,
            ];
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function visibleSiteIds(): array
    {
        return $this->visibleSitesQuery()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return Builder<Site>
     */
    private function visibleSitesQuery(): Builder
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
            $query->where('user_id', $ownerId);
        }

        return $query;
    }

    /**
     * @return Builder<User>
     */
    private function teamMembersQuery(): Builder
    {
        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();

        return User::query()
            ->where('parent_id', $ownerId)
            ->where('role', User::ROLE_STAFF)
            ->where('seo_role', SeoAccessControl::ROLE_CONTENT_MANAGER);
    }
}
