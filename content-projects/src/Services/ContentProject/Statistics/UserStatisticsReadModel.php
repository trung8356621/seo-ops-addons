<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Statistics;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoAnalyticsArticleScope;
use App\Models\User;
use App\Services\Users\SeoOpsSystemUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Content-manager review Statistics (Theo người dùng).
 *
 * Measures REVIEW workload only:
 * - Needs Review (waiting) — completed AI, no CM Save stamp
 * - In Review — CM Save stamped, not yet approved/scheduled/published
 * - Completed in month — content_manager_reviewed_at in selected calendar month
 *   attributed to content_manager_reviewed_by
 *
 * Page exclusion via {@see SeoAnalyticsArticleScope} on linked article.
 * Does NOT use task.post_type, capacity, publish queue ops, or AI run counts.
 */
final class UserStatisticsReadModel
{
    public function __construct(
        private readonly SeoAnalyticsArticleScope $analyticsScope,
        private readonly ContentProjectStaffAvailabilityService $staff,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $month, ?int $userId = null): array
    {
        $monthKey = ContentProjectMonthContext::normalize($month);
        $monthDate = ContentProjectMonthContext::toDateString($monthKey);
        $monthStart = CarbonImmutable::createFromFormat('Y-m', $monthKey)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $siteIds = SeoAccessControl::accessibleSiteIds();
        $cmIds = $this->contentManagerIds();
        $filterUserId = ($userId !== null && $userId > 0 && in_array($userId, $cmIds, true))
            ? $userId
            : null;

        if ($siteIds === [] || $cmIds === []) {
            return $this->emptyPayload($monthKey, $filterUserId);
        }

        $waiting = $this->countWaiting($siteIds, $monthDate, $filterUserId);
        $inReview = $this->countInReview($siteIds, $monthDate, $filterUserId);
        $completedByUser = $this->completedByReviewer($siteIds, $monthStart, $monthEnd, $filterUserId);
        $scoreByUser = $this->avgScoreByReviewer($siteIds, $monthStart, $monthEnd, $filterUserId);

        $names = $this->namesById($cmIds);
        $completedRows = [];
        $scoreRows = [];
        $maxCompleted = 1;
        $cmsWithWork = [];

        foreach ($completedByUser as $uid => $count) {
            if ($count <= 0 || ! in_array($uid, $cmIds, true)) {
                continue;
            }
            $cmsWithWork[$uid] = true;
            $maxCompleted = max($maxCompleted, $count);
            $completedRows[] = [
                'user_id' => $uid,
                'name' => $names[$uid] ?? ('#'.$uid),
                'completed' => $count,
            ];
        }

        usort(
            $completedRows,
            static fn (array $a, array $b): int => ($b['completed'] ?? 0) <=> ($a['completed'] ?? 0),
        );

        foreach ($scoreByUser as $uid => $avg) {
            if (! in_array($uid, $cmIds, true) || $avg === null) {
                continue;
            }
            $cmsWithWork[$uid] = true;
            $scoreRows[] = [
                'user_id' => $uid,
                'name' => $names[$uid] ?? ('#'.$uid),
                'avg_seo_score' => $avg,
            ];
        }

        usort(
            $scoreRows,
            static function (array $a, array $b): int {
                $cmp = ($b['avg_seo_score'] ?? 0) <=> ($a['avg_seo_score'] ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            },
        );

        // In-review stamped to a CM also counts as "có việc".
        foreach ($this->inReviewReviewerIds($siteIds, $monthDate, $filterUserId) as $uid) {
            if (in_array($uid, $cmIds, true)) {
                $cmsWithWork[$uid] = true;
            }
        }

        $completedTotal = array_sum(array_column($completedRows, 'completed'));
        $emptyCompleted = $completedRows === [];
        $emptyScores = $scoreRows === [];

        return [
            'empty' => false,
            'empty_reason' => null,
            'month' => $monthKey,
            'month_label' => ContentProjectMonthContext::display($monthKey),
            'user_id' => $filterUserId,
            'semantics' => [
                'waiting' => 'needs_review_ai_completed_no_cm_stamp_project_month',
                'in_review' => 'cm_stamp_present_not_approved_scheduled_published_project_month',
                'completed' => 'content_manager_reviewed_at_in_calendar_month_by_reviewed_by',
                'avg_seo_score' => 'avg_profile_score_of_completed_reviews_in_month',
                'page_exclusion' => 'seo_analytics_article_scope_via_content_type_meta',
            ],
            'kpis' => [
                'cms_with_work' => count($cmsWithWork),
                'waiting_review' => $waiting,
                'reviewed_in_month' => $completedTotal,
                'in_review' => $inReview,
            ],
            'completed_chart' => [
                'empty' => $emptyCompleted,
                'max' => max(1, $maxCompleted),
                'rows' => $completedRows,
            ],
            'score_chart' => [
                'empty' => $emptyScores,
                'rows' => $scoreRows,
            ],
            'content_manager_options' => $names,
        ];
    }

    /**
     * @return list<int>
     */
    private function contentManagerIds(): array
    {
        return $this->staff->baseAssignableStaffQuery()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0 && ! SeoOpsSystemUser::isSystemUserId($id))
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function namesById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function countWaiting(array $siteIds, string $monthDate, ?int $filterUserId): int
    {
        $query = $this->baseMonthProjectTasks($siteIds, $monthDate);
        // Waiting has no reviewer stamp — scope to projects owned by selected CM when filtered.
        if ($filterUserId !== null) {
            $query->where('p.user_id', $filterUserId);
        }
        $this->applyNeedsReviewConstraints($query);
        $this->analyticsScope->applyToTaskArticleId($query, 't.article_id');

        return (int) $query->count();
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function countInReview(array $siteIds, string $monthDate, ?int $filterUserId): int
    {
        if (! $this->hasCmReviewedColumn()) {
            return 0;
        }

        $query = $this->baseMonthProjectTasks($siteIds, $monthDate);
        $this->applyInReviewConstraints($query);
        if ($filterUserId !== null) {
            $query->where('t.content_manager_reviewed_by', $filterUserId);
        }
        $this->analyticsScope->applyToTaskArticleId($query, 't.article_id');

        return (int) $query->count();
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    private function inReviewReviewerIds(array $siteIds, string $monthDate, ?int $filterUserId): array
    {
        if (! $this->hasCmReviewedColumn()) {
            return [];
        }

        $query = $this->baseMonthProjectTasks($siteIds, $monthDate);
        $this->applyInReviewConstraints($query);
        $query->whereNotNull('t.content_manager_reviewed_by')
            ->where('t.content_manager_reviewed_by', '>', 0);
        if ($filterUserId !== null) {
            $query->where('t.content_manager_reviewed_by', $filterUserId);
        }
        $this->analyticsScope->applyToTaskArticleId($query, 't.article_id');

        return $query
            ->distinct()
            ->pluck('t.content_manager_reviewed_by')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, int>
     */
    private function completedByReviewer(
        array $siteIds,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        ?int $filterUserId,
    ): array {
        if (! $this->hasCmReviewedColumn()) {
            return [];
        }

        $query = $this->baseAccessibleTasks($siteIds);
        $query->whereNotNull('t.content_manager_reviewed_at')
            ->whereNotNull('t.content_manager_reviewed_by')
            ->where('t.content_manager_reviewed_by', '>', 0)
            ->whereBetween('t.content_manager_reviewed_at', [
                $monthStart->toDateTimeString(),
                $monthEnd->toDateTimeString(),
            ]);
        if ($filterUserId !== null) {
            $query->where('t.content_manager_reviewed_by', $filterUserId);
        }
        $this->analyticsScope->applyToTaskArticleId($query, 't.article_id');

        $out = [];
        foreach (
            $query
                ->groupBy('t.content_manager_reviewed_by')
                ->selectRaw('t.content_manager_reviewed_by as user_id, COUNT(t.id) as aggregate')
                ->get() as $row
        ) {
            $uid = (int) ($row->user_id ?? 0);
            if ($uid > 0) {
                $out[$uid] = max(0, (int) ($row->aggregate ?? 0));
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, float|null>
     */
    private function avgScoreByReviewer(
        array $siteIds,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        ?int $filterUserId,
    ): array {
        if (! $this->hasCmReviewedColumn()) {
            return [];
        }

        $query = $this->baseAccessibleTasks($siteIds);
        $query->leftJoin('seo_article_profiles as sap', 'sap.article_id', '=', 't.article_id')
            ->whereNotNull('t.content_manager_reviewed_at')
            ->whereNotNull('t.content_manager_reviewed_by')
            ->where('t.content_manager_reviewed_by', '>', 0)
            ->whereNotNull('t.article_id')
            ->whereNotNull('sap.seo_score')
            ->whereBetween('t.content_manager_reviewed_at', [
                $monthStart->toDateTimeString(),
                $monthEnd->toDateTimeString(),
            ]);
        if ($filterUserId !== null) {
            $query->where('t.content_manager_reviewed_by', $filterUserId);
        }
        $this->analyticsScope->applyToTaskArticleId($query, 't.article_id');

        $out = [];
        foreach (
            $query
                ->groupBy('t.content_manager_reviewed_by')
                ->selectRaw('t.content_manager_reviewed_by as user_id, AVG(sap.seo_score) as avg_score')
                ->get() as $row
        ) {
            $uid = (int) ($row->user_id ?? 0);
            if ($uid > 0 && $row->avg_score !== null) {
                $out[$uid] = round((float) $row->avg_score, 1);
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $siteIds
     * @return \Illuminate\Database\Query\Builder
     */
    private function baseMonthProjectTasks(array $siteIds, string $monthDate)
    {
        return $this->baseAccessibleTasks($siteIds)
            ->whereDate('p.month', $monthDate);
    }

    /**
     * @param  list<int>  $siteIds
     * @return \Illuminate\Database\Query\Builder
     */
    private function baseAccessibleTasks(array $siteIds)
    {
        $query = DB::connection('omi_seo_ai')
            ->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('p.archived_at')
            ->whereNull('t.archived_at')
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->whereIn('p.site_id', $siteIds);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('t.deleted_at');
        }
        ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($query, 'p');

        return $query;
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function applyNeedsReviewConstraints($query): void
    {
        $query->where('t.status', SeoProjectTask::STATUS_COMPLETED);

        if ($this->hasCmReviewedColumn()) {
            $query->whereNull('t.content_manager_reviewed_at');
        }

        $query->whereNull('t.scheduled_publish_at');
        if ($this->hasPublishPublishedAtColumn()) {
            $query->whereNull('t.publish_published_at');
        }
        if ($this->hasQueueStatusColumn()) {
            $query->where(function ($inner): void {
                $inner->whereNull('t.publish_queue_status')
                    ->orWhereNotIn('t.publish_queue_status', [
                        ContentProjectPublishQueueStatus::Waiting->value,
                        ContentProjectPublishQueueStatus::Processing->value,
                        ContentProjectPublishQueueStatus::Retrying->value,
                        ContentProjectPublishQueueStatus::Published->value,
                        ContentProjectPublishQueueStatus::Failed->value,
                    ]);
            });
        }

        $query->where(function ($outer): void {
            $outer->whereNull('t.article_id')
                ->orWhereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('articles as a')
                        ->whereColumn('a.id', 't.article_id')
                        ->where(function ($status): void {
                            $status->whereNull('a.review_status')
                                ->orWhereNotIn('a.review_status', ['approved', 'pending_review', 'archived']);
                        });
                });
        });
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function applyInReviewConstraints($query): void
    {
        $query->whereNotNull('t.content_manager_reviewed_at');
        $query->whereNull('t.scheduled_publish_at');
        if ($this->hasPublishPublishedAtColumn()) {
            $query->whereNull('t.publish_published_at');
        }

        $query->where(function ($outer): void {
            $outer->whereNull('t.article_id')
                ->orWhereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('articles as a')
                        ->whereColumn('a.id', 't.article_id')
                        ->where(function ($status): void {
                            $status->whereNull('a.review_status')
                                ->orWhere('a.review_status', '!=', 'approved');
                        });
                });
        });
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

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(string $monthKey, ?int $filterUserId): array
    {
        return [
            'empty' => true,
            'empty_reason' => 'no_data',
            'month' => $monthKey,
            'month_label' => ContentProjectMonthContext::display($monthKey),
            'user_id' => $filterUserId,
            'semantics' => [],
            'kpis' => [
                'cms_with_work' => 0,
                'waiting_review' => 0,
                'reviewed_in_month' => 0,
                'in_review' => 0,
            ],
            'completed_chart' => [
                'empty' => true,
                'max' => 1,
                'rows' => [],
            ],
            'score_chart' => [
                'empty' => true,
                'rows' => [],
            ],
            'content_manager_options' => [],
        ];
    }
}
