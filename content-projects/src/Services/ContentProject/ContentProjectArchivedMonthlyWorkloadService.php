<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchiveArticleHistoricalFieldResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExportReviewedAtResolver;
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
 * Cardinality = SeoProjectArchiveItem (1 article row), not live tasks.
 */
final class ContentProjectArchivedMonthlyWorkloadService
{
    public function __construct(
        private readonly ContentProjectMonthlyWorkloadService $workload,
        private readonly ContentProjectWriterMonthlyCapacityService $writerCapacity,
        private readonly WordPressPermalinkBuilder $permalinkBuilder,
        private readonly ContentProjectExportReviewedAtResolver $reviewedAtResolver = new ContentProjectExportReviewedAtResolver(),
        private ?ArchiveArticleHistoricalFieldResolver $historicalFields = null,
    ) {
        $this->historicalFields ??= new ArchiveArticleHistoricalFieldResolver(
            $this->permalinkBuilder,
            $this->reviewedAtResolver,
        );
    }

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
     * Flattened archived articles for the selected month (one row per archive item / article).
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
     *     reviewed_at: string,
     *     archived_by: string
     * }>
     */
    public function itemRows(CarbonImmutable|Carbon|string|null $month = null): array
    {
        $raw = $this->workload->archivedCanonicalItemQuery($month)
            ->select([
                'ai.id as archive_item_id',
                'ai.article_id as article_id',
                'ai.task_id as task_id',
                'ai.article_snapshot as article_snapshot',
                'ai.position as position',
                't.site_id as task_site_id',
                'art.site_id as article_site_id',
                't.post_type as post_type',
                't.type as plan_type',
                'p.name as project_name',
                'p.user_id as writer_id',
                'p.archived_by as archived_by',
                'p.id as project_id',
            ])
            ->orderBy('p.user_id')
            ->orderBy('ai.position')
            ->orderBy('ai.id')
            ->get();

        if ($raw->isEmpty()) {
            return [];
        }

        $writerIds = [];
        $archivedByIds = [];
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
            $articleId = (int) ($row->article_id ?? 0);
            if ($articleId > 0) {
                $articleIds[$articleId] = $articleId;
            }
        }

        $writerNames = $this->writerCapacity->displayNamesByUserId(array_values($writerIds));
        $archivedByNames = $this->userNames(array_values($archivedByIds));
        $indexedByArticle = $this->indexedArticleIds(array_values($articleIds));
        $articles = $this->loadArticles(array_values($articleIds));

        $rows = [];
        foreach ($raw as $row) {
            $articleId = (int) ($row->article_id ?? 0);
            if ($articleId <= 0) {
                continue;
            }

            $taskSiteId = (int) ($row->task_site_id ?? 0);
            $articleSiteId = (int) ($row->article_site_id ?? 0);
            $siteId = $taskSiteId > 0 ? $taskSiteId : $articleSiteId;
            $writerId = (int) ($row->writer_id ?? 0);
            $archivedBy = (int) ($row->archived_by ?? 0);

            $article = $articles->get($articleId);
            $snapshot = $this->decodeSnapshot($row->article_snapshot ?? null);
            $articleFields = $this->historicalFields->resolve(
                $article instanceof SeoArticle ? $article : null,
                $snapshot,
            );

            $isIndexed = isset($indexedByArticle[$articleId])
                || $this->isIndexedFromHistorical($articleFields['indexed_at'] ?? null);

            $rows[] = [
                'writer_id' => $writerId,
                'writer_name' => $writerNames[$writerId] ?? ($writerId > 0 ? '#'.$writerId : 'Unknown'),
                'project_name' => trim((string) ($row->project_name ?? '')),
                'site_id' => $siteId > 0 ? $siteId : null,
                'article_id' => $articleId,
                'title' => $articleFields['title'],
                'keyword' => $articleFields['keyword'],
                'wordpress_url' => $articleFields['wordpress_url'],
                'post_type' => $this->postTypeLabel((string) ($row->post_type ?? '')),
                'plan' => $this->planLabel((string) ($row->plan_type ?? '')),
                'index_status' => $isIndexed
                    ? (string) __('seo-content-ai::filament.projects.indexed')
                    : (string) __('seo-content-ai::filament.projects.not_indexed'),
                'reviewed_at' => $this->formatReportDate($articleFields['reviewed_at'] ?? null),
                'archived_by' => $archivedByNames[$archivedBy] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSnapshot(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isIndexedFromHistorical(mixed $indexedAt): bool
    {
        if ($indexedAt === null || $indexedAt === '') {
            return false;
        }

        if (is_string($indexedAt) && trim($indexedAt) === '') {
            return false;
        }

        return true;
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

    private function formatReportDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            if ($value instanceof Carbon) {
                return $value->format('d/m/Y');
            }

            return Carbon::parse((string) $value)->format('d/m/Y');
        } catch (\Throwable) {
            return is_scalar($value) ? (string) $value : '';
        }
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
}
