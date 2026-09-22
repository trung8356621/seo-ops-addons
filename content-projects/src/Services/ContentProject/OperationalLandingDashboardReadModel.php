<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectAuditSearchService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsDashboardService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\OperationalLandingDashboardActivityPresenter;
use Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight /seo landing Dashboard read model — role-aware, bounded, no SEO analytics.
 */
final class OperationalLandingDashboardReadModel
{
    public const PREVIEW_LIMIT = 8;

    public const QUEUE_LIMIT = 5;

    public const ACTIVITY_LIMIT = 8;

    public function __construct(
        private readonly ContentProjectOpsDashboardService $opsDashboard,
        private readonly ContentProjectQueueHealthService $queueHealth,
        private readonly ContentProjectAuditSearchService $auditSearch,
    ) {}

    /**
     * @return array{variant: string, payload: array<string, mixed>}
     */
    public function forEffectiveRole(?User $user = null): array
    {
        $role = SeoAccessControl::effectiveRole();
        if ($role === SeoAccessControl::ROLE_CONTENT_MANAGER) {
            return [
                'variant' => 'content_manager',
                'payload' => $this->contentManager($user),
            ];
        }

        return [
            'variant' => 'manager_planner',
            'payload' => $this->managerPlanner($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contentManager(?User $user = null): array
    {
        $user ??= auth()->user();
        $userId = $user instanceof User ? (int) $user->getKey() : (int) auth()->id();
        $monthStart = now()->startOfMonth()->toDateString();
        $projectIds = $this->contentManagerProjectIds($userId);

        $pendingQuery = $this->pendingReviewBaseQuery($projectIds, null);
        $reviewedQuery = $this->inReviewBaseQuery($projectIds, null);

        $pendingCount = (int) (clone $pendingQuery)->count();
        $reviewedCount = (int) (clone $reviewedQuery)->count();
        $dueToday = (int) (clone $pendingQuery)->whereDate('seo_project_tasks.updated_at', Carbon::today())->count();
        $doneToday = 0;
        if ($this->hasCmReviewedColumn()) {
            $doneToday = (int) $this->scopedTasks($projectIds, null)
                ->whereNotNull('seo_project_tasks.content_manager_reviewed_at')
                ->whereDate('seo_project_tasks.content_manager_reviewed_at', Carbon::today())
                ->count();
        }

        $monthTotals = $this->monthProgressCounts($projectIds, null, $monthStart);

        return [
            'subtitle_key' => 'dashboard.ops_cm_subtitle',
            'kpis' => [
                'pending_review' => $pendingCount,
                'reviewed' => $reviewedCount,
                'due_today' => $dueToday,
                'done_today' => $doneToday,
            ],
            'pending_rows' => $this->mapPendingRows(
                (clone $pendingQuery)
                    ->with(['article:id,title,user_id', 'project:id,name,month,site_id,user_id'])
                    ->orderByDesc('seo_project_tasks.updated_at')
                    ->limit(self::PREVIEW_LIMIT)
                    ->get()
            ),
            'reviewed_rows' => $this->mapReviewedRows(
                (clone $reviewedQuery)
                    ->with(['article:id,title,user_id', 'project:id,name,month,site_id,user_id', 'contentManagerReviewer'])
                    ->orderByDesc('seo_project_tasks.content_manager_reviewed_at')
                    ->limit(self::PREVIEW_LIMIT)
                    ->get()
            ),
            'month_progress' => $monthTotals,
            'month_label' => now()->format('m/Y'),
            'month_has_projects' => (bool) ($monthTotals['has_month_projects'] ?? false),
            'view_all_pending_url' => $this->safeUrl(static fn (): string => SeoProjectResource::getUrl('index')),
            'view_all_reviewed_url' => $this->safeUrl(static fn (): string => SeoProjectResource::getUrl('index')),
            'statistics_available' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function managerPlanner(?User $user = null): array
    {
        $siteIds = $this->scopedSiteIds();
        $projectIds = $this->projectIdsForSites($siteIds);
        $monthStart = now()->startOfMonth()->toDateString();

        $ops = $this->opsDashboard->snapshot($siteIds === [] ? null : $siteIds);
        $queue = $this->queueHealth->snapshot($siteIds === [] ? null : $siteIds);

        $pendingReview = (int) $this->pendingReviewBaseQuery($projectIds, $siteIds)->count();
        $approved = (int) $this->approvedBaseQuery($projectIds, $siteIds)->count();
        $scheduledToday = (int) $this->scopedTasks($projectIds, $siteIds)
            ->whereNotNull('seo_project_tasks.scheduled_publish_at')
            ->whereDate('seo_project_tasks.scheduled_publish_at', Carbon::today())
            ->count();
        $publishErrors = (int) ($queue['failed'] ?? 0);
        $aiRunning = (int) ($ops['ai']['running'] ?? 0);

        $stalePending = (int) $this->pendingReviewBaseQuery($projectIds, $siteIds)
            ->where('seo_project_tasks.updated_at', '<', now()->subDay())
            ->count();

        $attention = $this->buildAttentionItems(
            publishErrors: $publishErrors,
            stalePending: $stalePending,
            aiFailed: (int) ($ops['ai']['failed'] ?? 0),
        );

        $activity = $this->todayActivity();

        $monthProgress = $this->monthProgressCounts($projectIds, $siteIds, $monthStart);

        return [
            'subtitle_key' => 'dashboard.ops_manager_subtitle',
            'today_label' => now()->format('d/m/Y'),
            'kpis' => [
                'needs_review' => $pendingReview,
                'approved' => $approved,
                'scheduled_today' => $scheduledToday,
                'publish_errors' => $publishErrors,
                'ai_running' => $aiRunning,
            ],
            'kpi_scope_hint' => __('seo-content-ai::filament.dashboard.ops_kpi_scope_hint'),
            'attention' => $attention,
            'activity' => $activity,
            'month_progress' => $monthProgress,
            'month_label' => now()->format('m/Y'),
            'month_has_projects' => (bool) ($monthProgress['has_month_projects'] ?? false),
            'publish_queue' => $this->publishQueuePreview($projectIds, $siteIds),
            'publish_queue_url' => $this->safeUrl(static fn (): string => PublishingQueueHub::getUrl()),
            'view_all_projects_url' => $this->safeUrl(static fn (): string => SeoProjectResource::getUrl('index')),
            'statistics_available' => false,
        ];
    }

    /**
     * @param  list<int>  $projectIds
     * @param  list<int>|null  $siteIds
     * @return Builder<SeoProjectTask>
     */
    private function pendingReviewBaseQuery(array $projectIds, ?array $siteIds): Builder
    {
        $query = $this->scopedTasks($projectIds, $siteIds)
            ->inContentProjectWorkingSet()
            ->where('seo_project_tasks.status', SeoProjectTask::STATUS_COMPLETED);

        if ($this->hasCmReviewedColumn()) {
            $query->whereNull('seo_project_tasks.content_manager_reviewed_at');
        }

        $query->whereNull('seo_project_tasks.scheduled_publish_at');
        if ($this->hasPublishPublishedAtColumn()) {
            $query->whereNull('seo_project_tasks.publish_published_at');
        }
        if ($this->hasQueueStatusColumn()) {
            $query->where(static function (Builder $inner): void {
                $inner->whereNull('seo_project_tasks.publish_queue_status')
                    ->orWhereNotIn('seo_project_tasks.publish_queue_status', [
                        ContentProjectPublishQueueStatus::Waiting->value,
                        ContentProjectPublishQueueStatus::Processing->value,
                        ContentProjectPublishQueueStatus::Retrying->value,
                        ContentProjectPublishQueueStatus::Published->value,
                        ContentProjectPublishQueueStatus::Failed->value,
                    ]);
            });
        }

        $query->where(static function (Builder $inner): void {
            $inner->whereDoesntHave('article')
                ->orWhereHas('article', static function (Builder $article): void {
                    $article->where(static function (Builder $status): void {
                        $status->whereNull('review_status')
                            ->orWhereNotIn('review_status', ['approved', 'pending_review', 'archived']);
                    });
                });
        });

        return $query;
    }

    /**
     * @param  list<int>  $projectIds
     * @param  list<int>|null  $siteIds
     * @return Builder<SeoProjectTask>
     */
    private function inReviewBaseQuery(array $projectIds, ?array $siteIds): Builder
    {
        $query = $this->scopedTasks($projectIds, $siteIds)->inContentProjectWorkingSet();

        if ($this->hasCmReviewedColumn()) {
            $query->whereNotNull('seo_project_tasks.content_manager_reviewed_at');
        } else {
            $query->whereRaw('0 = 1');
        }

        $query->whereNull('seo_project_tasks.scheduled_publish_at');
        if ($this->hasPublishPublishedAtColumn()) {
            $query->whereNull('seo_project_tasks.publish_published_at');
        }

        $query->where(static function (Builder $inner): void {
            $inner->whereDoesntHave('article')
                ->orWhereHas('article', static function (Builder $article): void {
                    $article->where(static function (Builder $status): void {
                        $status->whereNull('review_status')
                            ->orWhere('review_status', '!=', 'approved');
                    });
                });
        });

        return $query;
    }

    /**
     * @param  list<int>  $projectIds
     * @param  list<int>|null  $siteIds
     * @return Builder<SeoProjectTask>
     */
    private function approvedBaseQuery(array $projectIds, ?array $siteIds): Builder
    {
        return $this->scopedTasks($projectIds, $siteIds)
            ->inContentProjectWorkingSet()
            ->whereNull('seo_project_tasks.scheduled_publish_at')
            ->whereHas('article', static function (Builder $article): void {
                $article->where('review_status', 'approved');
            });
    }

    /**
     * @param  list<int>  $projectIds
     * @param  list<int>|null  $siteIds
     * @return Builder<SeoProjectTask>
     */
    private function scopedTasks(array $projectIds, ?array $siteIds): Builder
    {
        $query = SeoProjectTask::query()->active();

        if ($projectIds !== []) {
            $query->whereIn('seo_project_tasks.project_id', $projectIds);
        } elseif ($siteIds !== null) {
            if ($siteIds === []) {
                $query->whereRaw('0 = 1');
            } else {
                $query->whereHas('project', static function (Builder $project) use ($siteIds): void {
                    $project->whereNull('archived_at')->whereIn('site_id', $siteIds);
                });
            }
        } else {
            $query->whereRaw('0 = 1');
        }

        return $query;
    }

    /**
     * @return list<int>
     */
    private function contentManagerProjectIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return SeoProject::query()
            ->whereNull('archived_at')
            ->where('user_id', $userId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Landing Dashboard always aggregates accessible sites (overview).
     * Domain picker is hidden on this page — do not honor a sticky global site.
     *
     * @return list<int>
     */
    private function scopedSiteIds(): array
    {
        return SeoAccessControl::accessibleSiteIds();
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    private function projectIdsForSites(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return SeoProject::query()
            ->whereNull('archived_at')
            ->whereIn('site_id', $siteIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $projectIds
     * @param  list<int>|null  $siteIds
     * @return array{
     *     total: int,
     *     pending_review: int,
     *     reviewed: int,
     *     approved: int,
     *     published: int,
     *     written: int,
     *     has_month_projects: bool,
     *     rows: list<array{key: string, label: string, value: int, total: int, pct: int, tone: string}>
     * }
     */
    private function monthProgressCounts(array $projectIds, ?array $siteIds, string $monthStart): array
    {
        $month = Carbon::parse($monthStart)->startOfMonth();
        $monthProjectQuery = SeoProject::query()
            ->whereNull('archived_at')
            ->whereYear('month', (int) $month->year)
            ->whereMonth('month', (int) $month->month);

        if ($projectIds !== []) {
            $monthProjectQuery->whereIn('id', $projectIds);
        } elseif (is_array($siteIds) && $siteIds !== []) {
            $monthProjectQuery->whereIn('site_id', $siteIds);
        } else {
            $monthProjectQuery->whereRaw('0 = 1');
        }

        $monthProjectIds = $monthProjectQuery
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($monthProjectIds === []) {
            return [
                'total' => 0,
                'pending_review' => 0,
                'reviewed' => 0,
                'approved' => 0,
                'published' => 0,
                'written' => 0,
                'has_month_projects' => false,
                'rows' => [],
            ];
        }

        $base = SeoProjectTask::query()->active()->whereIn('project_id', $monthProjectIds);
        $total = (int) (clone $base)->count();
        $written = (int) SeoProjectTask::query()
            ->active()
            ->whereIn('project_id', $monthProjectIds)
            ->whereIn('status', [
                SeoProjectTask::STATUS_COMPLETED,
                SeoProjectTask::STATUS_REVIEWING,
            ])
            ->count();

        $pending = (int) $this->pendingReviewBaseQuery($monthProjectIds, null)->count();
        $reviewed = (int) $this->inReviewBaseQuery($monthProjectIds, null)->count();
        $approved = (int) $this->approvedBaseQuery($monthProjectIds, null)->count();

        $publishedQuery = SeoProjectTask::query()->active()->whereIn('project_id', $monthProjectIds);
        if ($this->hasPublishPublishedAtColumn()) {
            $publishedQuery->where(static function (Builder $inner): void {
                $inner->whereNotNull('publish_published_at');
                if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
                    $inner->orWhere('publish_queue_status', ContentProjectPublishQueueStatus::Published->value);
                }
            });
        } elseif ($this->hasQueueStatusColumn()) {
            $publishedQuery->where('publish_queue_status', ContentProjectPublishQueueStatus::Published->value);
        } else {
            $publishedQuery->whereRaw('0 = 1');
        }
        $published = (int) $publishedQuery->count();

        $denom = max(0, $total);
        $row = static function (string $key, string $label, int $value, string $tone) use ($denom): array {
            $pct = $denom > 0 ? (int) min(100, max(0, round(($value / $denom) * 100))) : 0;

            return [
                'key' => $key,
                'label' => $label,
                'value' => $value,
                'total' => $denom,
                'pct' => $pct,
                'tone' => $tone,
            ];
        };

        return [
            'total' => $total,
            'pending_review' => $pending,
            'reviewed' => $reviewed,
            'approved' => $approved,
            'published' => $published,
            'written' => $written,
            'has_month_projects' => true,
            'rows' => [
                $row('written', (string) __('seo-content-ai::filament.dashboard.ops_progress_written'), $written, 'green'),
                $row('pending_review', (string) __('seo-content-ai::filament.dashboard.ops_kpi_pending_review'), $pending, 'amber'),
                $row('approved', (string) __('seo-content-ai::filament.dashboard.ops_kpi_approved'), $approved, 'blue'),
                $row('published', (string) __('seo-content-ai::filament.dashboard.ops_progress_published'), $published, 'purple'),
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoProjectTask>  $tasks
     * @return list<array<string, mixed>>
     */
    private function mapPendingRows($tasks): array
    {
        $rows = [];
        $i = 1;
        foreach ($tasks as $task) {
            $article = $task->article;
            $project = $task->project;
            $articleId = (int) ($task->article_id ?? 0);
            $url = null;
            if ($articleId > 0) {
                $url = $this->safeUrl(static fn (): string => ArticleResource::getUrl('edit', ['record' => $articleId]));
            } elseif ($project instanceof SeoProject) {
                $url = $this->safeUrl(static fn () => SeoProjectResource::projectRecordUrl($project));
            }

            $rows[] = [
                'index' => $i++,
                'title' => (string) ($article?->title ?? $task->keyword ?? ('#'.$task->getKey())),
                'project' => trim((string) ($project?->name ?: ($project ? SeoProject::defaultNameFromMonth($project->month) : ''))),
                'updated_at' => $task->updated_at?->diffForHumans() ?? '—',
                'status' => 'pending_review',
                'url' => $url,
            ];
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoProjectTask>  $tasks
     * @return list<array<string, mixed>>
     */
    private function mapReviewedRows($tasks): array
    {
        $rows = [];
        $i = 1;
        foreach ($tasks as $task) {
            $article = $task->article;
            $articleId = (int) ($task->article_id ?? 0);
            $project = $task->project;
            $url = null;
            if ($articleId > 0) {
                $url = $this->safeUrl(static fn (): string => ArticleResource::getUrl('edit', ['record' => $articleId]));
            } elseif ($project instanceof SeoProject) {
                $url = $this->safeUrl(static fn () => SeoProjectResource::projectRecordUrl($project));
            }

            $reviewerName = '—';
            if ($task->relationLoaded('contentManagerReviewer') && $task->contentManagerReviewer) {
                $reviewerName = (string) ($task->contentManagerReviewer->name ?? '—');
            } elseif ($task->content_manager_reviewed_by) {
                $reviewerName = '#'.(int) $task->content_manager_reviewed_by;
            }

            $rows[] = [
                'index' => $i++,
                'title' => (string) ($article?->title ?? $task->keyword ?? ('#'.$task->getKey())),
                'reviewer' => $reviewerName,
                'reviewed_at' => $task->content_manager_reviewed_at?->diffForHumans() ?? '—',
                'status' => 'reviewed',
                'url' => $url,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{
     *     tone: string,
     *     icon: string,
     *     title: string,
     *     detail: string|null,
     *     age: string|null,
     *     url: string|null
     * }>
     */
    private function buildAttentionItems(int $publishErrors, int $stalePending, int $aiFailed): array
    {
        $items = [];
        if ($publishErrors > 0) {
            $items[] = [
                'tone' => 'danger',
                'icon' => 'heroicon-o-exclamation-triangle',
                'title' => (string) __('seo-content-ai::filament.dashboard.ops_attention_publish_errors', ['count' => $publishErrors]),
                'detail' => (string) __('seo-content-ai::filament.dashboard.ops_attention_publish_errors_detail'),
                'age' => null,
                'url' => $this->safeUrl(static fn (): string => PublishingQueueHub::getUrl()),
            ];
        }
        if ($stalePending > 0) {
            $items[] = [
                'tone' => 'warning',
                'icon' => 'heroicon-o-clock',
                'title' => (string) __('seo-content-ai::filament.dashboard.ops_attention_stale_review', ['count' => $stalePending]),
                'detail' => (string) __('seo-content-ai::filament.dashboard.ops_attention_stale_review_detail'),
                'age' => null,
                'url' => $this->safeUrl(static fn (): string => SeoProjectResource::getUrl('index')),
            ];
        }
        if ($aiFailed > 0) {
            $items[] = [
                'tone' => 'danger',
                'icon' => 'heroicon-o-cpu-chip',
                'title' => (string) __('seo-content-ai::filament.dashboard.ops_attention_ai_failed', ['count' => $aiFailed]),
                'detail' => (string) __('seo-content-ai::filament.dashboard.ops_attention_ai_failed_detail'),
                'age' => null,
                'url' => $this->safeUrl(static fn (): string => SeoProjectResource::getUrl('index')),
            ];
        }

        return array_slice($items, 0, 5);
    }

    /**
     * @return list<array{time: string, message: string, context: string|null, tone: string}>
     */
    private function todayActivity(): array
    {
        $rows = $this->auditSearch->search([
            'from' => now()->startOfDay()->toDateTimeString(),
            'to' => now()->endOfDay()->toDateTimeString(),
            'limit' => self::ACTIVITY_LIMIT,
        ]);

        $out = [];
        foreach ($rows as $row) {
            $presented = OperationalLandingDashboardActivityPresenter::present($row);
            // Guard: never leak raw action keys.
            if (
                str_contains($presented['message'], 'content_project.')
                || str_contains($presented['message'], 'content_projects.')
                || str_contains($presented['message'], ' · success')
                || str_contains($presented['message'], ' · failed')
            ) {
                $presented['message'] = OperationalLandingDashboardActivityPresenter::labelFor(
                    (string) ($row['action'] ?? ''),
                    strtolower((string) ($row['result'] ?? '')) === 'failed',
                );
            }
            $out[] = $presented;
        }

        return $out;
    }

    /**
     * @param  list<int>  $projectIds
     * @param  list<int>|null  $siteIds
     * @return list<array<string, mixed>>
     */
    private function publishQueuePreview(array $projectIds, ?array $siteIds): array
    {
        if (! $this->hasPublishingQueuedAtColumn()) {
            return [];
        }

        $query = $this->scopedTasks($projectIds, $siteIds)
            ->inPublishingQueue()
            ->with(['article:id,title', 'project.site:id,domain'])
            ->orderBy('seo_project_tasks.scheduled_publish_at')
            ->orderBy('seo_project_tasks.id')
            ->limit(self::QUEUE_LIMIT);

        $rows = [];
        foreach ($query->get() as $task) {
            $queue = (string) ($task->publish_queue_status ?? 'none');
            $statusKey = in_array($queue, [
                ContentProjectPublishQueueStatus::Waiting->value,
                ContentProjectPublishQueueStatus::Processing->value,
                ContentProjectPublishQueueStatus::Retrying->value,
            ], true) ? 'scheduled' : 'waiting';

            $rows[] = [
                'title' => (string) ($task->article?->title ?? $task->keyword ?? ('#'.$task->getKey())),
                'domain' => (string) ($task->project?->site?->domain ?? '—'),
                'scheduled_at' => $task->scheduled_publish_at?->format('d/m/Y H:i') ?? '—',
                'status' => $statusKey,
                'url' => $this->safeUrl(static fn (): string => PublishingQueueHub::getUrl()),
            ];
        }

        return $rows;
    }

    /**
     * @param  callable(): string  $resolver
     */
    private function safeUrl(callable $resolver): ?string
    {
        try {
            $url = $resolver();

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasCmReviewedColumn(): bool
    {
        return Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'content_manager_reviewed_at');
    }

    private function hasPublishPublishedAtColumn(): bool
    {
        return Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_published_at');
    }

    private function hasQueueStatusColumn(): bool
    {
        return Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status');
    }

    private function hasPublishingQueuedAtColumn(): bool
    {
        return Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_queued_at');
    }
}
