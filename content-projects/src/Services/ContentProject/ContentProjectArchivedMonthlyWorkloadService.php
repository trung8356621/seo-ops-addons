<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Archived-only monthly workload. Same item set as Archived Projects charts.
 *
 * Domain = item.site_id. Never project.site_id.
 */
final class ContentProjectArchivedMonthlyWorkloadService
{
    public function __construct(
        private readonly ContentProjectMonthlyWorkloadService $workload,
        private readonly ContentProjectWriterMonthlyCapacityService $writerCapacity,
        private readonly WordPressPermalinkBuilder $permalinkBuilder,
    ) {}

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     by_domain: list<array{site_id: int, domain: string, item_count: int}>,
     *     by_writer: list<array{user_id: int, writer_name: string, item_count: int}>
     * }
     */
    public function summary(CarbonImmutable|Carbon|string|null $month = null): array
    {
        $domain = $this->workload->articlesByDomain($month, ContentProjectMonthlyWorkloadService::SCOPE_ARCHIVED);
        $writer = $this->workload->articlesByWriter($month, ContentProjectMonthlyWorkloadService::SCOPE_ARCHIVED);

        $byDomain = [];
        foreach ($domain['rows'] as $row) {
            $byDomain[] = [
                'site_id' => (int) $row['site_id'],
                'domain' => (string) $row['domain'],
                'item_count' => (int) ($row['count'] ?? $row['total_count'] ?? 0),
            ];
        }

        $byWriter = [];
        foreach ($writer['rows'] as $row) {
            $byWriter[] = [
                'user_id' => (int) $row['user_id'],
                'writer_name' => (string) $row['name'],
                'item_count' => (int) ($row['count'] ?? $row['total_count'] ?? 0),
            ];
        }

        return [
            'month' => $domain['month'],
            'month_label' => $domain['month_label'],
            'by_domain' => $byDomain,
            'by_writer' => $byWriter,
        ];
    }

    /**
     * Chart payload used by Archived Projects UI — same aggregation as Excel Summary.
     *
     * @return array{month: string, month_label: string, rows: list<array<string, mixed>>, max: int, empty: bool}
     */
    public function articlesByDomain(CarbonImmutable|Carbon|string|null $month = null): array
    {
        return $this->workload->articlesByDomain($month, ContentProjectMonthlyWorkloadService::SCOPE_ARCHIVED);
    }

    /**
     * @return array{month: string, month_label: string, rows: list<array<string, mixed>>, max: int, empty: bool}
     */
    public function articlesByWriter(CarbonImmutable|Carbon|string|null $month = null): array
    {
        return $this->workload->articlesByWriter($month, ContentProjectMonthlyWorkloadService::SCOPE_ARCHIVED);
    }

    /**
     * Flattened archived execution items for the selected month (one row per task).
     *
     * @return list<array{
     *     writer_id: int,
     *     writer_name: string,
     *     project_name: string,
     *     site_id: int|null,
     *     article_id: int,
     *     title: string,
     *     keyword: string,
     *     wordpress_url: string,
     *     post_type: string,
     *     plan: string,
     *     index_status: string,
     *     archived_at: string,
     *     archived_by: string
     * }>
     */
    public function itemRows(CarbonImmutable|Carbon|string|null $month = null): array
    {
        $raw = $this->workload->archivedExecutionItemQuery($month)
            ->select([
                't.id as task_id',
                't.site_id as site_id',
                't.article_id as article_id',
                't.keyword as keyword',
                't.title as title',
                't.post_type as post_type',
                't.type as plan_type',
                't.project_id as project_id',
                'p.name as project_name',
                'p.user_id as writer_id',
                'p.archived_at as archived_at',
                'p.archived_by as archived_by',
            ])
            ->orderBy('p.user_id')
            ->orderBy('t.id')
            ->get();

        if ($raw->isEmpty()) {
            return [];
        }

        $writerIds = [];
        $archivedByIds = [];
        $taskIds = [];
        $articleIds = [];

        foreach ($raw as $row) {
            $writerId = (int) ($row->writer_id ?? 0);
            if ($writerId > 0) {
                $writerIds[$writerId] = $writerId;
            }
            $archivedBy = (int) ($row->archived_by ?? 0);
            if ($archivedBy > 0) {
                $archivedByIds[$archivedBy] = $archivedBy;
            }
            $taskId = (int) ($row->task_id ?? 0);
            if ($taskId > 0) {
                $taskIds[] = $taskId;
            }
            $articleId = (int) ($row->article_id ?? 0);
            if ($articleId > 0) {
                $articleIds[$articleId] = $articleId;
            }
        }

        $writerNames = $this->writerCapacity->displayNamesByUserId(array_values($writerIds));
        $archivedByNames = $this->userNames(array_values($archivedByIds));
        $resolvedArticleIds = $this->resolveArticleIdsForIndex($taskIds, $articleIds);
        $indexedByArticle = $this->indexedArticleIds($resolvedArticleIds);
        $articles = $this->loadArticles($resolvedArticleIds);

        $rows = [];
        foreach ($raw as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            $articleId = (int) ($row->article_id ?? 0);
            $writerId = (int) ($row->writer_id ?? 0);
            $archivedBy = (int) ($row->archived_by ?? 0);
            $taskId = (int) ($row->task_id ?? 0);
            $resolvedArticleId = $articleId > 0
                ? $articleId
                : (int) ($this->taskArticleMap[$taskId] ?? 0);

            $article = $resolvedArticleId > 0
                ? $articles->get($resolvedArticleId)
                : null;
            $articleFields = $this->resolveArticleExportFields($article);

            $rows[] = [
                'writer_id' => $writerId,
                'writer_name' => $writerNames[$writerId] ?? ($writerId > 0 ? '#'.$writerId : 'Unknown'),
                'project_name' => trim((string) ($row->project_name ?? '')),
                'site_id' => $siteId > 0 ? $siteId : null,
                'article_id' => $resolvedArticleId,
                'title' => $articleFields['title'],
                'keyword' => $articleFields['keyword'],
                'wordpress_url' => $articleFields['wordpress_url'],
                'post_type' => $this->postTypeLabel((string) ($row->post_type ?? '')),
                'plan' => $this->planLabel((string) ($row->plan_type ?? '')),
                'index_status' => isset($indexedByArticle[$resolvedArticleId])
                    ? (string) __('seo-content-ai::filament.projects.indexed')
                    : (string) __('seo-content-ai::filament.projects.not_indexed'),
                'archived_at' => $this->formatDateTime($row->archived_at ?? null),
                'archived_by' => $archivedByNames[$archivedBy] ?? '',
            ];
        }

        return $rows;
    }

    /** @var array<int, int> task_id => article_id from archive items */
    private array $taskArticleMap = [];

    /**
     * @param  list<int>  $taskIds
     * @param  array<int, int>  $articleIds
     * @return list<int>
     */
    private function resolveArticleIdsForIndex(array $taskIds, array $articleIds): array
    {
        $this->taskArticleMap = [];

        if ($taskIds !== []) {
            $archiveItems = SeoProjectArchiveItem::query()
                ->whereIn('task_id', $taskIds)
                ->whereNotNull('article_id')
                ->get(['task_id', 'article_id']);

            foreach ($archiveItems as $item) {
                if (! $item instanceof SeoProjectArchiveItem) {
                    continue;
                }
                $taskId = (int) ($item->task_id ?? 0);
                $articleId = (int) ($item->article_id ?? 0);
                if ($taskId > 0 && $articleId > 0) {
                    $this->taskArticleMap[$taskId] = $articleId;
                    $articleIds[$articleId] = $articleId;
                }
            }
        }

        return array_values($articleIds);
    }

    /**
     * @param  list<int>  $articleIds
     * @return Collection<int, SeoArticle>
     */
    private function loadArticles(array $articleIds): Collection
    {
        if ($articleIds === []) {
            return collect();
        }

        return SeoArticle::query()
            ->whereIn('id', $articleIds)
            ->with([
                'articleMetas',
                'wordpressLink',
                'seoProfile',
                'site',
            ])
            ->get()
            ->keyBy(static fn (SeoArticle $article): int => (int) $article->getKey());
    }

    /**
     * Final published article fields only — never task planning title/keyword.
     *
     * @return array{title: string, keyword: string, wordpress_url: string}
     */
    private function resolveArticleExportFields(?SeoArticle $article): array
    {
        if (! $article instanceof SeoArticle) {
            return [
                'title' => '',
                'keyword' => '',
                'wordpress_url' => '',
            ];
        }

        $title = trim((string) ($article->title ?? ''));
        $keyword = trim((string) ($this->articleMeta($article, 'seo_focus_keyword') ?? ''));
        $permalink = trim((string) ($this->articleMeta($article, 'wp_permalink') ?? ''));
        $wordpressUrl = trim($this->permalinkBuilder->resolve(
            $article,
            $permalink,
            trim((string) ($article->slug ?? '')) !== '' ? (string) $article->slug : null,
        ));

        return [
            'title' => $title,
            'keyword' => $keyword,
            'wordpress_url' => $wordpressUrl,
        ];
    }

    private function articleMeta(?SeoArticle $article, string $key): ?string
    {
        if (! $article instanceof SeoArticle) {
            return null;
        }

        $article->loadMissing('articleMetas');
        $value = $article->articleMetas->firstWhere('meta_key', $key)?->meta_value;
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  list<int>  $articleIds
     * @return array<int, true>
     */
    private function indexedArticleIds(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        $indexed = [];
        SeoArticle::query()
            ->whereIn('id', $articleIds)
            ->whereHas('seoProfile', static function ($query): void {
                $query->whereNotNull('indexed_at');
            })
            ->pluck('id')
            ->each(static function (mixed $id) use (&$indexed): void {
                $indexed[(int) $id] = true;
            });

        return $indexed;
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, string>
     */
    public function domainLabels(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $domains = [];
        foreach (Site::query()->whereIn('id', $siteIds)->get(['id', 'domain']) as $site) {
            $domains[(int) $site->getKey()] = trim((string) ($site->domain ?? ''));
        }

        return $domains;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function userNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $names = [];
        foreach (User::query()->whereIn('id', $userIds)->get(['id', 'name', 'email']) as $user) {
            $label = trim((string) ($user->name ?? ''));
            if ($label === '') {
                $label = trim((string) ($user->email ?? ''));
            }
            $names[(int) $user->getKey()] = $label;
        }

        return $names;
    }

    private function planLabel(string $type): string
    {
        return match (SeoProjectTask::normalizeType($type)) {
            SeoProjectTask::TYPE_REWRITE => (string) __('seo-content-ai::filament.projects.archive_export_plan_rewrite'),
            SeoProjectTask::TYPE_IMPROVE => (string) __('seo-content-ai::filament.projects.archive_export_plan_improve'),
            default => (string) __('seo-content-ai::filament.projects.archive_export_plan_create'),
        };
    }

    private function postTypeLabel(string $postType): string
    {
        $normalized = SeoProjectTask::normalizePostType($postType);

        return match ($normalized) {
            SeoProjectTask::POST_TYPE_PRODUCT => (string) __('seo-content-ai::filament.projects.post_type_product'),
            SeoProjectTask::POST_TYPE_CATEGORY => (string) __('seo-content-ai::filament.projects.post_type_category'),
            SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => (string) __('seo-content-ai::filament.projects.post_type_product_category'),
            default => (string) __('seo-content-ai::filament.projects.post_type_post'),
        };
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return is_scalar($value) ? (string) $value : '';
        }
    }
}
