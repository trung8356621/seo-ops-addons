<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff (role=staff + seo_role=content_manager) chưa gắn writer vào Content Project
 * trong đúng tháng đang xét (seo_projects.month).
 *
 * Assignment = seo_projects.user_id (không pivot). Cross-DB nên pluck id rồi whereNotIn.
 * Scope theo tháng toàn hệ thống — không filter domain (một project tháng X ở domain A
 * đã tính là có project tháng X).
 */
final class ContentProjectStaffAvailabilityService
{
    public const WIDGET_LIMIT = 50;

    public function canViewUnassignedStaff(): bool
    {
        return SeoAccessControl::canMutateContentProjects();
    }

    /**
     * @return Builder<User>
     */
    public function baseAssignableStaffQuery(): Builder
    {
        $query = User::query()
            ->where('role', User::ROLE_STAFF)
            ->where('seo_role', User::SEO_ROLE_CONTENT_MANAGER)
            ->where('status', User::STATUS_NORMAL);

        if (auth()->user()?->role !== User::ROLE_ADMIN) {
            $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
            $query->where('parent_id', $ownerId);
        }

        return $query->orderBy('name');
    }

    /**
     * @return Builder<User>
     */
    public function unassignedStaffQuery(
        CarbonImmutable|Carbon|string|null $month,
        ?string $search = null,
    ): Builder {
        $assignedIds = $this->assignedStaffIdsForMonth($month);

        $query = $this->baseAssignableStaffQuery();

        if ($assignedIds !== []) {
            $query->whereNotIn('id', $assignedIds);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * Alias API gợi ý trong spec.
     *
     * @return Collection<int, User>
     */
    public function getUnassignedStaffForMonth(
        CarbonImmutable|Carbon|string|null $month,
        ?int $domainId = null,
        ?string $search = null,
        ?int $limit = null,
    ): Collection {
        // $domainId giữ signature tương thích — mặc định không scope domain (xem class doc).
        unset($domainId);

        return $this->listUnassigned($month, $search, $limit);
    }

    /**
     * Staff đã có project active (chưa archive) trong đúng tháng — mọi domain.
     *
     * @return list<int>
     */
    public function assignedStaffIdsForMonth(CarbonImmutable|Carbon|string|null $month): array
    {
        $monthDate = ContentProjectMonthContext::toDateString($month);

        return SeoProject::query()
            ->activeProjects()
            ->where(function (Builder $builder): void {
                $builder
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            })
            ->whereDate('month', $monthDate)
            ->whereNotNull('user_id')
            ->where('user_id', '>', 0)
            ->distinct()
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @deprecated Dùng assignedStaffIdsForMonth — giữ để tránh break call cũ không truyền month.
     *
     * @return list<int>
     */
    public function activeAssignedStaffIds(): array
    {
        return $this->assignedStaffIdsForMonth(ContentProjectMonthContext::current());
    }

    public function isUnassigned(int $userId, CarbonImmutable|Carbon|string|null $month = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return $this->unassignedStaffQuery($month ?? ContentProjectMonthContext::current())
            ->whereKey($userId)
            ->exists();
    }

    public function countUnassigned(CarbonImmutable|Carbon|string|null $month, ?string $search = null): int
    {
        return (int) $this->unassignedStaffQuery($month, $search)->count();
    }

    /**
     * @return Collection<int, User>
     */
    public function listUnassigned(
        CarbonImmutable|Carbon|string|null $month,
        ?string $search = null,
        ?int $limit = null,
    ): Collection {
        $query = $this->unassignedStaffQuery($month, $search);

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get(['id', 'name', 'email']);
    }

    /**
     * @return array{
     *     month: string,
     *     month_display: string,
     *     total: int,
     *     staff: list<array{id: int, name: string, email: string, initials: string, create_url: string}>
     * }
     */
    public function widgetPayload(
        CarbonImmutable|Carbon|string|null $month,
        ?string $search = null,
        int $limit = self::WIDGET_LIMIT,
    ): array {
        $normalized = ContentProjectMonthContext::normalize($month);
        $total = $this->countUnassigned($normalized, $search);
        $staff = $this->listUnassigned($normalized, $search, $limit)
            ->map(fn (User $user): array => $this->presentStaff($user, $normalized))
            ->values()
            ->all();

        return [
            'month' => $normalized,
            'month_display' => ContentProjectMonthContext::display($normalized),
            'total' => $total,
            'staff' => $staff,
        ];
    }

    /**
     * @return array{unassigned: array<int, string>, assigned: array<int, string>}
     */
    public function groupedSelectOptions(
        CarbonImmutable|Carbon|string|null $month = null,
        ?string $search = null,
    ): array {
        $normalized = ContentProjectMonthContext::normalize($month);
        $assignedIds = $this->assignedStaffIdsForMonth($normalized);
        $all = $this->baseAssignableStaffQuery();

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $all->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        $unassigned = [];
        $assigned = [];

        foreach ($all->get(['id', 'name', 'email']) as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $label = $this->formatLabel($user);
            $id = (int) $user->getKey();

            if (in_array($id, $assignedIds, true)) {
                $assigned[$id] = $label;
            } else {
                $unassigned[$id] = $label;
            }
        }

        return [
            'unassigned' => $unassigned,
            'assigned' => $assigned,
        ];
    }

    /**
     * Re-check trong transaction: staff chưa có project active cùng tháng.
     * lockForUpdate trên row project cùng user+month (nếu có) để giảm race.
     */
    public function assertUnassignedForMonth(int $userId, CarbonImmutable|Carbon|string|null $month): void
    {
        if ($userId <= 0) {
            return;
        }

        $monthDate = ContentProjectMonthContext::toDateString($month);

        $base = static function () use ($monthDate): Builder {
            return SeoProject::query()
                ->activeProjects()
                ->where(function (Builder $builder): void {
                    $builder
                        ->where('kind', SeoProject::KIND_MONTHLY)
                        ->orWhereNull('kind');
                })
                ->whereDate('month', $monthDate);
        };

        // Serialize assign cùng tháng trong transaction (không có unique index user+month).
        // Empty month vẫn lock được (no rows) — race empty-empty cần unique index sau.
        if (DB::connection('omi_seo_ai')->transactionLevel() > 0) {
            $base()->lockForUpdate()->get(['id']);
        }

        $existing = $base()
            ->where('user_id', $userId)
            ->first(['id', 'user_id', 'month']);

        if ($existing === null) {
            return;
        }

        $user = User::query()->find($userId);
        $label = $user instanceof User
            ? $this->formatLabel($user)
            : '#'.$userId;

        throw \Illuminate\Validation\ValidationException::withMessages([
            'data.user_id' => __('seo-content-ai::filament.projects.unassigned_staff_race', [
                'name' => $label,
                'month' => ContentProjectMonthContext::display($month),
            ]),
        ]);
    }

    /**
     * @return array{id: int, name: string, email: string, initials: string, create_url: string}
     */
    public function presentStaff(User $user, CarbonImmutable|Carbon|string|null $month = null): array
    {
        $name = trim((string) ($user->name ?: ''));
        $email = trim((string) ($user->email ?? ''));
        $normalized = ContentProjectMonthContext::normalize($month);

        return [
            'id' => (int) $user->getKey(),
            'name' => $name !== '' ? $name : ($email !== '' ? $email : '#'.$user->getKey()),
            'email' => $email,
            'initials' => $this->initials($name !== '' ? $name : $email),
            'create_url' => $this->createProjectUrl((int) $user->getKey(), $normalized),
        ];
    }

    public function createProjectUrl(int $userId = 0, CarbonImmutable|Carbon|string|null $month = null): string
    {
        $base = SeoProjectResource::getUrl('create');
        $params = [];
        $normalized = ContentProjectMonthContext::normalize($month);
        $params['month'] = $normalized;

        if ($userId > 0) {
            $params['staff'] = $userId;
            // Giữ writer_id tương thích link cũ.
            $params['writer_id'] = $userId;
        }

        $query = http_build_query($params);

        return $base.(str_contains($base, '?') ? '&' : '?').$query;
    }

    public function formatLabel(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));
        $email = trim((string) ($user->email ?? ''));

        if ($name !== '' && $email !== '') {
            return sprintf('%s(%s)', $name, $email);
        }

        if ($name !== '') {
            return $name;
        }

        return $email !== '' ? $email : '#'.(int) $user->getKey();
    }

    /**
     * Chạy callback trong transaction SEO DB (lock assert dùng chung).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withAssignmentLock(callable $callback): mixed
    {
        return DB::connection('omi_seo_ai')->transaction($callback);
    }

    private function initials(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '?';
        }

        $parts = preg_split('/\s+/u', $label) ?: [];
        $chars = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $chars[] = mb_strtoupper(mb_substr($part, 0, 1));
            if (count($chars) >= 2) {
                break;
            }
        }

        if ($chars === []) {
            return '?';
        }

        return implode('', $chars);
    }
}
