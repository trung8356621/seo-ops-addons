<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Enums\ArticleReviewActionType;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleReview;
use Omnichannel\Addons\ContentProjects\Models\SeoContentArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Nguồn truy vấn tab "Legacy bài lẻ" trong kho archive:
 * chỉ bài có mirror `seo_content_archive_items`.
 * Không dùng `review_status=archived` (đó là “Hoàn tất duyệt”, không phải archive lẻ).
 */
final class ArticleCompletedArchiveQueryService
{
    public function __construct(
        private readonly WordPressPermalinkBuilder $permalinkBuilder,
    ) {
    }

    /**
     * @param  list<int>  $siteIds
     * @return Builder<SeoArticle>
     */
    public function queryForSites(array $siteIds): Builder
    {
        $siteIds = $this->normalizeSiteIds($siteIds);

        $query = SeoArticle::query()
            ->with(['site', 'user', 'latestReview.reviewer', 'contentArchiveItem'])
            // Legacy bài lẻ: mirror seo_content_archive_items.
            // review_status=archived sau khi bỏ archive-lẻ = “Hoàn tất duyệt”, không vào tab legacy.
            ->where(function (Builder $builder): void {
                $builder->whereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('seo_content_archive_items')
                        ->whereColumn('seo_content_archive_items.article_id', 'articles.id');
                });
            });

        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('site_id', $siteIds);
    }

    /**
     * Task đang active (chưa archive khỏi content project) ưu tiên; fallback bản ghi bất kỳ
     * (mới nhất) để vẫn hiển thị được project gốc khi task đã bị archive/soft-delete.
     */
    public function resolveContentProjectTask(SeoArticle $article): ?SeoProjectTask
    {
        $articleId = (int) $article->getKey();
        if ($articleId <= 0) {
            return null;
        }

        $task = SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->with('project')
            ->active()
            ->latest('id')
            ->first();

        if ($task instanceof SeoProjectTask) {
            return $task;
        }

        return SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->with('project')
            ->withTrashed()
            ->latest('id')
            ->first();
    }

    public function resolveContentProjectLabel(SeoArticle $article): ?string
    {
        $task = $this->resolveContentProjectTask($article);
        $project = $task?->project;
        if ($project === null) {
            return null;
        }

        $month = $project->month instanceof Carbon ? $project->month->format('m/Y') : null;

        return $month !== null ? sprintf('%s · %s', (string) $project->name, $month) : (string) $project->name;
    }

    public function resolveCompletedAt(SeoArticle $article): ?Carbon
    {
        $archivedAt = $article->relationLoaded('contentArchiveItem')
            ? $article->contentArchiveItem?->archived_at
            : $article->contentArchiveItem()->value('archived_at');
        if ($archivedAt instanceof Carbon) {
            return $archivedAt;
        }

        $latest = $article->relationLoaded('latestReview') ? $article->latestReview : null;

        return $latest?->created_at instanceof Carbon ? $latest->created_at : null;
    }

    public function resolveCompletedByLabel(SeoArticle $article): string
    {
        $reviewer = $article->relationLoaded('latestReview') ? $article->latestReview?->reviewer : null;
        if ($reviewer instanceof User) {
            return (string) ($reviewer->display_name ?? $reviewer->email);
        }

        $archivedBy = $article->relationLoaded('contentArchiveItem')
            ? $article->contentArchiveItem?->archived_by
            : $article->contentArchiveItem()->value('archived_by');
        $userId = (int) ($archivedBy ?? 0);
        if ($userId <= 0) {
            return '—';
        }

        $user = User::query()->find($userId);

        return $user instanceof User ? (string) ($user->display_name ?? $user->email) : '—';
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<string, string> key `YYYY-MM` => label hiển thị
     */
    public function monthOptionsForSites(array $siteIds): array
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        if ($siteIds === []) {
            return [];
        }

        $months = SeoContentArchiveItem::query()
            ->whereIn('site_id', $siteIds)
            ->whereNotNull('archived_at')
            ->selectRaw("DATE_FORMAT(archived_at, '%Y-%m') as month_key")
            ->distinct()
            ->orderByDesc('month_key')
            ->pluck('month_key')
            ->filter()
            ->values();

        $options = [];
        foreach ($months as $monthKey) {
            $options[(string) $monthKey] = Carbon::createFromFormat('Y-m', (string) $monthKey)->format('m/Y');
        }

        return $options;
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, string> reviewer_id => tên hiển thị
     */
    public function reviewerOptionsForSites(array $siteIds): array
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        if ($siteIds === []) {
            return [];
        }

        $articleIds = $this->queryForSites($siteIds)->pluck('id');

        $reviewerIds = SeoArticleReview::query()
            ->whereIn('article_id', $articleIds)
            ->where('action_type', ArticleReviewActionType::Archive->value)
            ->distinct()
            ->pluck('reviewer_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();

        if ($reviewerIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $reviewerIds)
            ->get()
            ->mapWithKeys(fn (User $user): array => [(int) $user->id => (string) ($user->display_name ?? $user->email)])
            ->all();
    }

    /**
     * Shape tương thích UI `archive-dashboard.blade.php` cũ (groups theo ngày hoàn tất),
     * nhưng nguồn là article/review — không đụng bảng archive project.
     *
     * @param  list<int>|int  $siteIds
     * @return array{
     *     groups: list<array{date: string, date_label: string, month_key: string, month_label: string, count: int, articles: list<array<string, mixed>>}>,
     *     month_options: list<array{value: string, label: string}>,
     *     domain_options: list<array{value: int, label: string}>
     * }
     */
    public function buildGroupedDashboard(array|int $siteIds): array
    {
        $normalizedSiteIds = $this->normalizeSiteIds(
            is_array($siteIds) ? $siteIds : [(int) $siteIds],
        );

        if ($normalizedSiteIds === []) {
            return [
                'groups' => [],
                'month_options' => [],
                'domain_options' => [],
            ];
        }

        $articles = $this->queryForSites($normalizedSiteIds)
            ->with([
                'site',
                'user',
                'latestReview.reviewer',
                'contentArchiveItem',
                'articleMetas',
                'reviews' => static function (HasMany $query): void {
                    $query
                        ->with('reviewer')
                        ->orderByDesc('id');
                },
            ])
            ->orderByDesc(
                SeoContentArchiveItem::query()
                    ->select('archived_at')
                    ->whereColumn('seo_content_archive_items.article_id', 'articles.id')
                    ->limit(1)
            )
            ->orderByDesc('id')
            ->get();

        $tasksByArticleId = $this->indexLatestTasksByArticleId(
            $articles->map(static fn (SeoArticle $article): int => (int) $article->getKey())->all(),
        );

        /** @var array<string, array{date: string, date_label: string, month_key: string, month_label: string, count: int, articles: list<array<string, mixed>>}> $grouped */
        $grouped = [];
        /** @var array<string, string> $monthLabels */
        $monthLabels = [];
        /** @var array<int, string> $domainLabels */
        $domainLabels = [];

        foreach ($articles as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $completedAt = $this->resolveCompletedAt($article);
            if (! $completedAt instanceof Carbon) {
                continue;
            }

            $dateKey = $completedAt->toDateString();
            $monthKey = $completedAt->format('Y-m');
            $monthLabels[$monthKey] = $completedAt->format('m/Y');

            if (! isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [
                    'date' => $dateKey,
                    'date_label' => $completedAt->translatedFormat('d/m/Y'),
                    'month_key' => $monthKey,
                    'month_label' => $monthLabels[$monthKey],
                    'count' => 0,
                    'articles' => [],
                ];
            }

            $siteId = (int) ($article->site_id ?? 0);
            $domain = trim((string) ($article->site?->domain ?? ''));
            if ($siteId > 0 && $domain !== '') {
                $domainLabels[$siteId] = $domain;
            }

            $task = $tasksByArticleId->get((int) $article->getKey());
            $grouped[$dateKey]['articles'][] = $this->mapArticleRow($article, $completedAt, $task instanceof SeoProjectTask ? $task : null);
            $grouped[$dateKey]['count']++;
        }

        krsort($monthLabels);
        $monthOptions = [];
        foreach ($monthLabels as $value => $label) {
            $monthOptions[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        ksort($domainLabels);
        $domainOptions = [];
        foreach ($domainLabels as $value => $label) {
            $domainOptions[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return [
            'groups' => array_values($grouped),
            'month_options' => $monthOptions,
            'domain_options' => $domainOptions,
        ];
    }

    /**
     * @param  list<int>  $articleIds
     * @return Collection<int, SeoProjectTask>
     */
    private function indexLatestTasksByArticleId(array $articleIds): Collection
    {
        $articleIds = array_values(array_unique(array_filter(
            $articleIds,
            static fn (int $id): bool => $id > 0,
        )));

        if ($articleIds === []) {
            return collect();
        }

        $tasks = SeoProjectTask::query()
            ->whereIn('article_id', $articleIds)
            ->with('project')
            ->withTrashed()
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('id')
            ->get();

        /** @var Collection<int, SeoProjectTask> $indexed */
        $indexed = collect();
        foreach ($tasks as $task) {
            $articleId = (int) ($task->article_id ?? 0);
            if ($articleId <= 0 || $indexed->has($articleId)) {
                continue;
            }
            $indexed->put($articleId, $task);
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapArticleRow(SeoArticle $article, Carbon $completedAt, ?SeoProjectTask $task): array
    {
        $title = trim((string) ($article->title ?? ''));
        if ($title === '') {
            $title = 'Article #'.(int) $article->getKey();
        }

        $author = trim((string) ($article->user?->display_name ?? $article->user?->name ?? $article->user?->email ?? ''));
        $connectedAt = $task?->created_at instanceof Carbon ? $task->created_at : null;
        $project = $task?->project;
        $projectMonth = $project?->month instanceof Carbon ? $project->month->format('m/Y') : null;
        $projectName = $project !== null ? trim((string) $project->name) : '';
        $projectLabel = $projectName !== ''
            ? ($projectMonth !== null ? sprintf('%s · %s', $projectName, $projectMonth) : $projectName)
            : null;

        $latestNote = trim((string) ($article->latestReview?->note ?? ''));
        $reviews = [];
        if ($article->relationLoaded('reviews')) {
            foreach ($article->reviews as $review) {
                if (! $review instanceof SeoArticleReview) {
                    continue;
                }

                $reviews[] = [
                    'action' => (string) $review->action_type,
                    'action_label' => $this->actionLabel((string) $review->action_type),
                    'from_status' => (string) ($review->from_status ?? ''),
                    'to_status' => (string) ($review->to_status ?? ''),
                    'reviewer' => (string) ($review->reviewer?->display_name
                        ?? $review->reviewer?->name
                        ?? $review->reviewer?->email
                        ?? '—'),
                    'at' => $review->created_at instanceof Carbon
                        ? $review->created_at->format('d/m/Y H:i')
                        : '—',
                    'note' => trim((string) ($review->note ?? '')),
                ];
            }
        }

        return [
            'id' => (int) $article->getKey(),
            // Giữ key Alpine cũ; giá trị = article_id (không còn archive_item).
            'archive_item_id' => (int) $article->getKey(),
            'task_id' => $task !== null ? (int) $task->id : 0,
            'site_id' => (int) ($article->site_id ?? 0),
            'domain' => trim((string) ($article->site?->domain ?? '')) ?: '—',
            'title' => $title,
            'author' => $author !== '' ? $author : '—',
            'project_label' => $projectLabel,
            'connected_at' => $connectedAt?->toDateString() ?? '',
            'connected_label' => $connectedAt?->format('d/m/Y H:i') ?? '—',
            'completed_time' => $completedAt->format('H:i'),
            'completed_at_label' => $completedAt->format('d/m/Y H:i'),
            'completed_by' => $this->resolveCompletedByLabel($article),
            'latest_note' => $latestNote !== '' ? $latestNote : null,
            'has_note' => $latestNote !== '',
            'edit_url' => ArticleResource::getUrl('edit', ['record' => $article]),
            'view_url' => $this->resolveArticleViewUrl($article),
            'reviews' => $reviews,
        ];
    }

    private function actionLabel(string $actionType): string
    {
        $key = 'seo-content-ai::filament.article_review.actions.'.$actionType.'.label';
        $label = __($key);

        return $label !== $key ? (string) $label : $actionType;
    }

    private function resolveArticleViewUrl(SeoArticle $article): ?string
    {
        $article->loadMissing('site', 'articleMetas');

        $cached = trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));
        $slug = trim((string) ($article->slug ?? ''));

        $resolved = $this->permalinkBuilder->resolve($article, $cached, $slug !== '' ? $slug : null);

        return $resolved !== '' ? $resolved : null;
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    private function normalizeSiteIds(array $siteIds): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $siteIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($normalized !== []) {
            return $normalized;
        }

        return SeoAccessControl::accessibleSiteIds();
    }
}
