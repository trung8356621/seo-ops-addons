<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use App\Models\User;
use App\Services\Users\SeoOpsSystemUser;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writer monthly workload / capacity.
 *
 * used_capacity(user, month) = ACTIVE execution items + ARCHIVED execution items.
 * Shared Planning Draft excluded. Archive does NOT free capacity.
 */
final class ContentProjectWriterMonthlyCapacityService
{
    public function __construct(
        private readonly ContentProjectStaffAvailabilityService $staff,
    ) {}

    /**
     * Total used slots (active + archived execution) keyed by user_id.
     *
     * @param  list<int>  $userIds
     * @return array<int, int> user_id => total item count
     */
    public function itemCountsByUserId(
        array $userIds,
        CarbonImmutable|Carbon|string|null $month = null,
    ): array {
        $breakdown = $this->itemBreakdownByUserId($userIds, $month);
        $counts = [];
        foreach ($breakdown as $userId => $row) {
            $counts[$userId] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{active: int, archived: int, total: int}>
     */
    public function itemBreakdownByUserId(
        array $userIds,
        CarbonImmutable|Carbon|string|null $month = null,
    ): array {
        $ids = $this->normalizeUserIds($userIds);
        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = ['active' => 0, 'archived' => 0, 'total' => 0];
        }

        if ($ids === []) {
            return $counts;
        }

        $monthDate = ContentProjectMonthContext::toDateString($month);
        $query = DB::connection('omi_seo_ai')
            ->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            // Include archived projects — archive does not free writer capacity.
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('p.kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('p.kind');
            })
            ->whereDate('p.month', $monthDate)
            ->whereIn('p.user_id', $ids)
            ->whereNull('t.archived_at')
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('t.deleted_at');
        }

        $rows = $query
            ->groupBy('p.user_id')
            ->selectRaw(
                'p.user_id as user_id, '
                .'SUM(CASE WHEN p.archived_at IS NULL THEN 1 ELSE 0 END) as active_count, '
                .'SUM(CASE WHEN p.archived_at IS NOT NULL THEN 1 ELSE 0 END) as archived_count, '
                .'COUNT(t.id) as item_count'
            )
            ->get();

        foreach ($rows as $row) {
            $userId = (int) ($row->user_id ?? 0);
            if ($userId <= 0 || ! array_key_exists($userId, $counts)) {
                continue;
            }
            $active = max(0, (int) ($row->active_count ?? 0));
            $archived = max(0, (int) ($row->archived_count ?? 0));
            $total = max(0, (int) ($row->item_count ?? 0));
            $counts[$userId] = [
                'active' => $active,
                'archived' => $archived,
                'total' => $total,
            ];
        }

        return $counts;
    }

    /**
     * remaining = MAX_WRITER_MONTHLY_ITEMS - used_capacity (may be negative for legacy overage).
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    public function remainingByUserId(
        array $userIds,
        CarbonImmutable|Carbon|string|null $month = null,
    ): array {
        $capacity = ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS;
        $used = $this->itemCountsByUserId($userIds, $month);
        $remaining = [];
        foreach ($used as $userId => $count) {
            $remaining[$userId] = $capacity - $count;
        }

        return $remaining;
    }

    /**
     * Assignable staff with current-month workload for display.
     * System user is excluded. Capacity uses active + archived totals.
     *
     * @return array{
     *     month: string,
     *     month_label: string,
     *     month_display: string,
     *     capacity: int,
     *     writers: list<array{
     *         id: int,
     *         name: string,
     *         current: int,
     *         active: int,
     *         archived: int,
     *         remaining: int
     *     }>
     * }
     */
    public function writerSelectorPayload(CarbonImmutable|Carbon|string|null $month = null): array
    {
        $normalized = ContentProjectMonthContext::normalize($month);
        $capacity = ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS;
        $staff = $this->staff->listUnassigned($normalized, null, null);
        $userIds = $staff
            ->map(static fn (User $user): int => (int) $user->getKey())
            ->filter(static fn (int $id): bool => $id > 0 && ! SeoOpsSystemUser::isSystemUserId($id))
            ->values()
            ->all();

        $breakdown = $this->itemBreakdownByUserId($userIds, $normalized);
        $writers = [];

        foreach ($staff as $user) {
            if (! $user instanceof User) {
                continue;
            }
            $id = (int) $user->getKey();
            if ($id <= 0 || SeoOpsSystemUser::isSystemUserId($id)) {
                continue;
            }

            $name = trim((string) ($user->name ?? ''));
            $email = trim((string) ($user->email ?? ''));
            $row = $breakdown[$id] ?? ['active' => 0, 'archived' => 0, 'total' => 0];
            $total = (int) $row['total'];

            $writers[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ($email !== '' ? $email : '#'.$id),
                'current' => $total,
                'active' => (int) $row['active'],
                'archived' => (int) $row['archived'],
                'remaining' => $capacity - $total,
            ];
        }

        return [
            'month' => ContentProjectMonthContext::toDateString($normalized),
            'month_label' => ContentProjectMonthContext::display($normalized),
            'month_display' => 'Tháng '.ContentProjectMonthContext::display($normalized),
            'capacity' => $capacity,
            'writers' => $writers,
        ];
    }

    /**
     * Display names for known users (missing rows → #id).
     *
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    public function displayNamesByUserId(array $userIds): array
    {
        $ids = $this->normalizeUserIds($userIds);
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ($ids as $id) {
            $names[$id] = '#'.$id;
        }

        $users = User::query()->whereIn('id', $ids)->get(['id', 'name', 'email']);
        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }
            $id = (int) $user->getKey();
            $label = trim((string) ($user->name ?? ''));
            if ($label === '') {
                $label = trim((string) ($user->email ?? ''));
            }
            $names[$id] = $label !== '' ? $label : '#'.$id;
        }

        return $names;
    }

    /**
     * @param  list<int|string>  $userIds
     * @return list<int>
     */
    public function normalizeUserIds(array $userIds): array
    {
        $seen = [];
        $ids = [];
        foreach ($userIds as $raw) {
            $id = (int) $raw;
            if ($id <= 0 || isset($seen[$id]) || SeoOpsSystemUser::isSystemUserId($id)) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        return $ids;
    }
}
