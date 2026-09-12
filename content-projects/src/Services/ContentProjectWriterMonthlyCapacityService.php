<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
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
 * Include archived projects — archive does NOT free capacity.
 * Archived slots count SeoProjectArchiveItem rows (canonical articles), not duplicate tasks.
 * Shared Planning Draft excluded.
 *
 * Effective capacity comes from {@see ContentProjectWriterCapacitySettingsService}
 * (per-user override ?? global default) — not from execution project packing limits.
 */
final class ContentProjectWriterMonthlyCapacityService
{
    public function __construct(
        private readonly ContentProjectStaffAvailabilityService $staff,
        private readonly ContentProjectWriterCapacitySettingsService $capacitySettings,
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

        // Active cardinality = live tasks on non-archived projects.
        $activeQuery = DB::connection('omi_seo_ai')
            ->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('p.kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('p.kind');
            })
            ->whereDate('p.month', $monthDate)
            ->whereIn('p.user_id', $ids)
            ->whereNull('p.archived_at')
            ->whereNull('t.archived_at')
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $activeQuery->whereNull('t.deleted_at');
        }
        ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($activeQuery, 'p');

        foreach (
            $activeQuery
                ->groupBy('p.user_id')
                ->selectRaw('p.user_id as user_id, COUNT(t.id) as item_count')
                ->get() as $row
        ) {
            $userId = (int) ($row->user_id ?? 0);
            if ($userId <= 0 || ! array_key_exists($userId, $counts)) {
                continue;
            }
            $counts[$userId]['active'] = max(0, (int) ($row->item_count ?? 0));
        }

        // Archived cardinality = canonical archive items (1 / article), not duplicate tasks.
        $archivedQuery = DB::connection('omi_seo_ai')
            ->table('seo_project_archive_items as ai')
            ->join('seo_project_archives as a', 'a.id', '=', 'ai.seo_project_archive_id')
            ->join('seo_projects as p', 'p.id', '=', 'a.project_id')
            ->whereNull('a.restored_at')
            ->whereNotNull('p.archived_at')
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('p.kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('p.kind');
            })
            ->whereDate('p.month', $monthDate)
            ->whereIn('p.user_id', $ids)
            ->whereNotNull('ai.article_id')
            ->where('ai.article_id', '>', 0);
        ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($archivedQuery, 'p');

        foreach (
            $archivedQuery
                ->groupBy('p.user_id')
                ->selectRaw('p.user_id as user_id, COUNT(ai.id) as item_count')
                ->get() as $row
        ) {
            $userId = (int) ($row->user_id ?? 0);
            if ($userId <= 0 || ! array_key_exists($userId, $counts)) {
                continue;
            }
            $counts[$userId]['archived'] = max(0, (int) ($row->item_count ?? 0));
        }

        foreach ($counts as $userId => $row) {
            $counts[$userId]['total'] = (int) $row['active'] + (int) $row['archived'];
        }

        return $counts;
    }

    /**
     * Effective monthly capacity per user (override ?? global default).
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    public function capacityByUserId(array $userIds): array
    {
        return $this->capacitySettings->capacitiesForUsers($userIds);
    }

    /**
     * remaining = effective_capacity - used_capacity (may be negative for overage reporting).
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    public function remainingByUserId(
        array $userIds,
        CarbonImmutable|Carbon|string|null $month = null,
    ): array {
        $ids = $this->normalizeUserIds($userIds);
        $capacities = $this->capacityByUserId($ids);
        $used = $this->itemCountsByUserId($ids, $month);
        $remaining = [];
        foreach ($ids as $userId) {
            $capacity = (int) ($capacities[$userId] ?? 0);
            $total = (int) ($used[$userId] ?? 0);
            $remaining[$userId] = $capacity - $total;
        }

        return $remaining;
    }

    /**
     * Assignable staff with target-month workload for display.
     * System user is excluded. Capacity uses active + archived totals.
     *
     * @return array{
     *     month: string,
     *     month_label: string,
     *     month_display: string,
     *     default_capacity: int,
     *     team_capacity: int,
     *     writers: list<array{
     *         id: int,
     *         name: string,
     *         capacity: int,
     *         current: int,
     *         active: int,
     *         archived: int,
     *         remaining: int,
     *         assignable: bool
     *     }>
     * }
     */
    public function writerSelectorPayload(CarbonImmutable|Carbon|string|null $month = null): array
    {
        $normalized = ContentProjectMonthContext::normalize($month);
        $defaultCapacity = $this->capacitySettings->defaultMonthlyCapacity();
        $staff = $this->staff->listUnassigned($normalized, null, null);
        $userIds = $staff
            ->map(static fn (User $user): int => (int) $user->getKey())
            ->filter(static fn (int $id): bool => $id > 0 && ! SeoOpsSystemUser::isSystemUserId($id))
            ->values()
            ->all();

        $breakdown = $this->itemBreakdownByUserId($userIds, $normalized);
        $capacities = $this->capacityByUserId($userIds);
        $writers = [];
        $teamCapacity = 0;

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
            $capacity = (int) ($capacities[$id] ?? $defaultCapacity);
            $remaining = $capacity - $total;
            $teamCapacity += $capacity;

            $writers[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ($email !== '' ? $email : '#'.$id),
                'capacity' => $capacity,
                'current' => $total,
                'active' => (int) $row['active'],
                'archived' => (int) $row['archived'],
                'remaining' => $remaining,
                'assignable' => $capacity > 0 && $remaining > 0,
            ];
        }

        return [
            'month' => ContentProjectMonthContext::toDateString($normalized),
            'month_label' => ContentProjectMonthContext::display($normalized),
            'month_display' => 'Tháng '.ContentProjectMonthContext::display($normalized),
            'default_capacity' => $defaultCapacity,
            'team_capacity' => $teamCapacity,
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
