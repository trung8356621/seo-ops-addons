<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use App\Services\Users\SeoOpsSystemUser;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Shared gate: writer monthly capacity before any execution workload entry.
 * Draft planning projects never consume capacity.
 *
 * SSOT for used/remaining counts: {@see ContentProjectWriterMonthlyCapacityService}.
 * Packing helpers (projectWithRemainingCapacity) must run only after this gate passes.
 */
final class WriterMonthlyCapacityGate
{
    public function __construct(
        private readonly ContentProjectWriterMonthlyCapacityService $capacity,
    ) {}

    /**
     * @return array{ok: true}|array{ok: false, code: string, reason: string, remaining: int, incoming: int, user_id: int}
     */
    public function assertCanAccept(int $userId, CarbonImmutable|Carbon|string|null $month, int $incomingCount): array
    {
        $incoming = max(0, $incomingCount);
        if ($incoming === 0) {
            return ['ok' => true];
        }

        if ($userId <= 0 || SeoOpsSystemUser::isSystemUserId($userId)) {
            return [
                'ok' => false,
                'code' => ContentProjectActionCodes::SYSTEM_USER_REJECTED,
                'reason' => ContentProjectActionCodes::SYSTEM_USER_REJECTED,
                'remaining' => 0,
                'incoming' => $incoming,
                'user_id' => $userId,
            ];
        }

        $remaining = (int) ($this->capacity->remainingByUserId([$userId], $month)[$userId] ?? 0);
        if ($incoming > $remaining) {
            return [
                'ok' => false,
                'code' => ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED,
                'reason' => ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED,
                'remaining' => max(0, $remaining),
                'incoming' => $incoming,
                'user_id' => $userId,
            ];
        }

        return ['ok' => true];
    }

    /**
     * Gate when assigning into a concrete project. Draft → always OK.
     * Locks the writer's execution projects for the month so concurrent assigns serialize.
     *
     * @return array{ok: true}|array{ok: false, code: string, reason: string, remaining: int, incoming: int, user_id: int}
     */
    public function assertProjectCanAccept(SeoProject $project, int $incomingCount): array
    {
        if ($project->isDraftPlanning() || (string) ($project->status ?? '') === SeoProject::STATUS_DRAFT) {
            return ['ok' => true];
        }

        $userId = (int) ($project->user_id ?? 0);
        $month = $project->month ?? ContentProjectMonthContext::current();
        $this->lockWriterMonthProjects($userId, $month);

        return $this->assertCanAccept($userId, $month, $incomingCount);
    }

    /**
     * How many more items this execution project owner can take this month (0 for Draft / system user).
     */
    public function remainingForProject(SeoProject $project): int
    {
        if ($project->isDraftPlanning() || (string) ($project->status ?? '') === SeoProject::STATUS_DRAFT) {
            return PHP_INT_MAX;
        }

        $userId = (int) ($project->user_id ?? 0);
        if ($userId <= 0 || SeoOpsSystemUser::isSystemUserId($userId)) {
            return 0;
        }

        return max(0, (int) ($this->capacity->remainingByUserId([$userId], $project->month)[$userId] ?? 0));
    }

    /**
     * Lock writer-month rows then return remaining slots (Draft → PHP_INT_MAX).
     */
    public function lockAndRemainingForProject(SeoProject $project): int
    {
        if ($project->isDraftPlanning() || (string) ($project->status ?? '') === SeoProject::STATUS_DRAFT) {
            return PHP_INT_MAX;
        }

        $userId = (int) ($project->user_id ?? 0);
        $month = $project->month ?? ContentProjectMonthContext::current();
        $this->lockWriterMonthProjects($userId, $month);

        return $this->remainingForProject($project);
    }

    /**
     * @throws InvalidArgumentException with message = action code
     */
    public function throwUnlessProjectCanAccept(SeoProject $project, int $incomingCount): void
    {
        $result = $this->assertProjectCanAccept($project, $incomingCount);
        if (($result['ok'] ?? false) === true) {
            return;
        }

        throw new InvalidArgumentException((string) ($result['code'] ?? ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED));
    }

    /**
     * Serialize concurrent capacity checks for the same writer+month.
     */
    private function lockWriterMonthProjects(int $userId, CarbonImmutable|Carbon|string|null $month): void
    {
        if ($userId <= 0) {
            return;
        }

        $monthDate = ContentProjectMonthContext::toDateString($month);

        SeoProject::query()
            ->where('user_id', $userId)
            ->where('status', '!=', SeoProject::STATUS_DRAFT)
            ->whereDate('month', $monthDate)
            ->lockForUpdate()
            ->get(['id']);
    }
}