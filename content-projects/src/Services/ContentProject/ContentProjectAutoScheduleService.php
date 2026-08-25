<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Publishing\MonthlyPublishDistributionService;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Publishing\Services\Publishing\ContentPublishingStrategyResolver;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Auto Schedule / Quick Mode — phân bố lịch Publishing Queue (không WordPress).
 *
 * Project-level: không bắt buộc selection. Loại Publishing/Published.
 * Preview + Apply dùng cùng PublishingSchedulePlan (mỗi item 1 UTC timestamp).
 */
final class ContentProjectAutoScheduleService
{
    public const MIN_INTERVAL_MINUTES = 5;

    /** Safety delay before first In Day / Quick safe slot (system timezone minutes). */
    public const SAFETY_DELAY_MINUTES = 5;

    public function __construct(
        private readonly ContentProjectPublishingQueueService $queue,
        private readonly ContentPublishingStrategyResolver $strategyResolver = new ContentPublishingStrategyResolver,
        private readonly MonthlyPublishDistributionService $monthlyDistribution = new MonthlyPublishDistributionService,
    ) {}

    /**
     * @param  list<int>  $taskIds  empty = toàn bộ eligible trong project
     * @param  array<string, mixed>  $options
     * @return array{
     *     scheduled: int,
     *     slots: list<string>,
     *     item_schedule_map: array<int, string>,
     *     eligible_ids: list<int>,
     *     excluded: list<array{id: int, reason: string}>,
     *     first_publish_at: string|null,
     *     last_publish_at: string|null,
     *     timezone: string,
     * }
     */
    public function schedule(SeoProject $project, array $taskIds, array $options): array
    {
        $plan = $this->buildPlan($project, $taskIds, $options);

        if ($plan->eligibleIds === [] || $plan->itemScheduleMap === []) {
            return $plan->toArray(0);
        }

        if ($plan->blocked !== null && $plan->blocked !== '') {
            throw new RuntimeException($plan->blocked);
        }

        $scheduled = $this->queue->schedulePlan($project, $plan->itemScheduleMap);

        return $plan->toArray($scheduled);
    }

    /**
     * @param  list<int>  $taskIds
     * @param  array<string, mixed>  $options
     * @return array{
     *     scheduled: int,
     *     slots: list<string>,
     *     item_schedule_map: array<int, string>,
     *     eligible_ids: list<int>,
     *     excluded: list<array{id: int, reason: string}>,
     *     first_publish_at: string|null,
     *     last_publish_at: string|null,
     *     timezone: string,
     *     blocked: string|null,
     *     suggested_max_interval: int|null,
     * }
     */
    public function preview(SeoProject $project, array $taskIds, array $options): array
    {
        return $this->buildPlan($project, $taskIds, $options)->toArray(0);
    }

    /**
     * @param  list<int>  $taskIds
     * @param  array<string, mixed>  $options
     */
    public function buildPlan(SeoProject $project, array $taskIds, array $options): PublishingSchedulePlan
    {
        $tz = SystemDateTime::timezone();
        $mode = (string) ($options['mode'] ?? 'interval');
        $allowReschedule = array_key_exists('allow_reschedule', $options)
            ? (bool) $options['allow_reschedule']
            : ($mode !== 'monthly_even');
        $resolved = $this->resolveEligible($project, $taskIds, $allowReschedule);
        $ids = $resolved['eligible_ids'];

        if ($ids === []) {
            return PublishingSchedulePlan::empty($tz, $resolved['excluded']);
        }

        $mode = (string) ($options['mode'] ?? 'interval');

        try {
            $monthlyMeta = null;
            $slots = match ($mode) {
                'monthly_even' => $this->buildMonthlyEvenSlots($project, count($ids), $options, $tz, $monthlyMeta),
                'interval' => $this->buildIntervalSlots(
                    $this->parseStartAt($options['start_at'] ?? null, $tz),
                    count($ids),
                    max(self::MIN_INTERVAL_MINUTES, (int) ($options['interval_minutes'] ?? 15)),
                ),
                'per_day' => $this->buildPerDaySlots(
                    $this->parseStartAt($options['start_at'] ?? null, $tz)->timezone($tz)->startOfDay(),
                    count($ids),
                    max(1, (int) ($options['per_day'] ?? 3)),
                    (string) ($options['day_start'] ?? '09:00'),
                    (string) ($options['day_end'] ?? '17:00'),
                    $tz,
                ),
                'random_windows' => $this->buildRandomWindowSlots(
                    $this->parseStartAt($options['start_at'] ?? null, $tz)->timezone($tz)->startOfDay(),
                    count($ids),
                    is_array($options['windows'] ?? null) ? $options['windows'] : [
                        ['start' => '08:00', 'end' => '11:30'],
                        ['start' => '14:00', 'end' => '17:00'],
                    ],
                    $tz,
                ),
                'project_month' => $this->buildProjectMonthSlots(
                    $project,
                    count($ids),
                    (string) ($options['day_start'] ?? '09:00'),
                    (string) ($options['day_end'] ?? '17:00'),
                    $tz,
                ),
                'quick' => $this->buildQuickModeSlots(
                    count($ids),
                    max(1, (int) ($options['days'] ?? 1)),
                    (string) ($options['start_time'] ?? $options['day_start'] ?? '08:00'),
                    (string) ($options['end_time'] ?? $options['day_end'] ?? '17:00'),
                    $tz,
                ),
                'in_day' => $this->buildInDaySlots(
                    count($ids),
                    max(self::MIN_INTERVAL_MINUTES, (int) ($options['interval_minutes'] ?? 15)),
                    $tz,
                ),
                default => throw new InvalidArgumentException('Auto Schedule mode không hợp lệ.'),
            };
        } catch (RuntimeException $e) {
            $suggestedMax = null;
            $blocked = $e->getMessage();
            if (str_starts_with($e->getMessage(), 'in_day.overflow|')) {
                $parts = explode('|', $e->getMessage(), 3);
                $suggestedMax = isset($parts[1]) ? (int) $parts[1] : null;
                $blocked = $parts[2] ?? $e->getMessage();
            }

            return new PublishingSchedulePlan(
                eligibleIds: $ids,
                slots: [],
                itemScheduleMap: [],
                excluded: $resolved['excluded'],
                timezone: $tz,
                blocked: $blocked,
                suggestedMaxInterval: ($suggestedMax !== null && $suggestedMax > 0) ? $suggestedMax : null,
            );
        }

        $excluded = $resolved['excluded'];
        $schedulableIds = $ids;

        if ($mode === 'monthly_even' && is_array($monthlyMeta)) {
            $monthlyMeta['preserved_count'] = count(array_filter(
                $resolved['excluded'],
                static fn (array $row): bool => ($row['reason'] ?? '') === 'scheduled_locked',
            ));
        }

        if ($mode === 'monthly_even' && is_array($monthlyMeta) && ($monthlyMeta['unscheduled_count'] ?? 0) > 0) {
            $fitCount = count($slots);
            $overflowIds = array_slice($ids, $fitCount);
            foreach ($overflowIds as $overflowId) {
                $excluded[] = [
                    'id' => (int) $overflowId,
                    'reason' => 'window_overflow',
                ];
            }
            $schedulableIds = array_slice($ids, 0, $fitCount);
        }

        return PublishingSchedulePlan::fromSlots(
            $schedulableIds,
            $slots,
            $excluded,
            $tz,
            distributionMeta: is_array($monthlyMeta) ? $monthlyMeta : null,
        );
    }

    /**
     * @param  list<int>  $requestedIds
     * @return array{eligible_ids: list<int>, excluded: list<array{id: int, reason: string}>}
     */
    public function resolveEligible(SeoProject $project, array $requestedIds = [], bool $allowReschedule = true): array
    {
        $requested = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $requestedIds),
            static fn (int $id): bool => $id > 0,
        )));

        $q = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->active()
            ->where('article_id', '>', 0)
            ->orderBy('id');

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_queued_at')) {
            $q->whereNotNull('publishing_queued_at');
        }

        if ($requested !== []) {
            $q->whereIn('id', $requested);
        }

        $excluded = [];
        $eligible = [];

        foreach ($q->get() as $task) {
            $id = (int) $task->getKey();
            if ($this->strategyResolver->resolve($task)->isImmediateUpdate()) {
                $excluded[] = ['id' => $id, 'reason' => 'update_existing_immediate'];
                continue;
            }

            $status = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
                ?? ContentProjectPublishQueueStatus::None;

            if ($status === ContentProjectPublishQueueStatus::Processing) {
                $excluded[] = ['id' => $id, 'reason' => 'publishing'];
                continue;
            }
            if ($status === ContentProjectPublishQueueStatus::Published || $task->publish_published_at !== null) {
                $excluded[] = ['id' => $id, 'reason' => 'published'];
                continue;
            }

            $hasSchedule = $task->scheduled_publish_at !== null;
            $isUnscheduled = ! $hasSchedule && in_array($status, [
                ContentProjectPublishQueueStatus::None,
                ContentProjectPublishQueueStatus::Failed,
                ContentProjectPublishQueueStatus::Cancelled,
                ContentProjectPublishQueueStatus::Skipped,
            ], true);

            $isScheduledPlan = $hasSchedule && in_array($status, [
                ContentProjectPublishQueueStatus::None,
                ContentProjectPublishQueueStatus::Waiting,
                ContentProjectPublishQueueStatus::Retrying,
                ContentProjectPublishQueueStatus::Failed,
            ], true);

            if ($isUnscheduled) {
                $eligible[] = $id;
                continue;
            }
            if ($allowReschedule && $isScheduledPlan) {
                $eligible[] = $id;
                continue;
            }

            $excluded[] = ['id' => $id, 'reason' => $hasSchedule ? 'scheduled_locked' : 'not_eligible'];
        }

        return ['eligible_ids' => $eligible, 'excluded' => $excluded];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $distributionMeta  populated on success
     * @return list<Carbon>
     */
    private function buildMonthlyEvenSlots(
        SeoProject $project,
        int $count,
        array $options,
        string $tz,
        ?array &$distributionMeta = null,
    ): array {
        $month = $project->month;
        if ($month === null) {
            throw new RuntimeException('Project month missing — cannot use monthly_even distribution.');
        }

        $window = $this->monthlyDistribution->resolveProjectMonthWindow($month, $tz);
        $siteId = (int) ($project->site_id ?? 0);
        if ($siteId <= 0) {
            throw new RuntimeException('Project site_id missing — cannot use monthly_even distribution.');
        }

        $windowStartUtc = $window['start']->copy()->utc();
        $windowEndUtc = $window['end']->copy()->utc();

        $existingIso = SeoProjectTask::query()
            ->where('site_id', $siteId)
            ->whereNotNull('scheduled_publish_at')
            ->whereBetween('scheduled_publish_at', [$windowStartUtc, $windowEndUtc])
            ->orderBy('scheduled_publish_at')
            ->pluck('scheduled_publish_at')
            ->map(static fn ($at): string => $at instanceof Carbon
                ? $at->copy()->utc()->toIso8601String()
                : Carbon::parse((string) $at)->utc()->toIso8601String())
            ->values()
            ->all();

        $minSpacing = $this->monthlyDistribution->resolveMinSpacing($options);

        $result = $this->monthlyDistribution->allocate(
            $window['start'],
            $window['end'],
            $count,
            $options + ['min_spacing_minutes' => $minSpacing],
            $existingIso,
            $tz,
            $window['label'],
        );

        $distributionMeta = [
            'mode' => 'monthly_even',
            'window_label' => $result['window_label'],
            'available_days' => $result['available_days'],
            'density_approx' => $result['density_approx'],
            'min_spacing_minutes' => $minSpacing,
            'unscheduled_count' => $result['unscheduled_count'],
            'unscheduled_reason' => $result['unscheduled_reason'],
            'existing_anchor_count' => count($existingIso),
        ];

        return $result['slots'];
    }

    /**
     * @return list<Carbon>
     */
    private function buildInDaySlots(int $count, int $intervalMinutes, string $tz): array
    {
        $intervalMinutes = max(self::MIN_INTERVAL_MINUTES, $intervalMinutes);
        $nowLocal = Carbon::now($tz);
        $dayEnd = $nowLocal->copy()->endOfDay();

        // slot[0] = next safe slot (now + safety); slot[n] = slot[0] + n × interval
        $cursor = $nowLocal->copy()->addMinutes(self::SAFETY_DELAY_MINUTES)->second(0)->microsecond(0);

        $neededEnd = $cursor->copy()->addMinutes($intervalMinutes * max(0, $count - 1));
        if ($neededEnd->gt($dayEnd)) {
            $remainingMinutes = max(0, (int) $cursor->diffInMinutes($dayEnd));
            $maxInterval = $count > 1 ? (int) floor($remainingMinutes / ($count - 1)) : $intervalMinutes;
            $maxInterval = max(self::MIN_INTERVAL_MINUTES, $maxInterval);
            throw new RuntimeException(sprintf(
                'in_day.overflow|%d|Lịch vượt quá 23:59 theo timezone %s. Interval tối đa trong ngày ≈ %d phút, hoặc chuyển sang 1/2/3 ngày.',
                $maxInterval,
                $tz,
                $maxInterval,
            ));
        }

        $slots = [];
        for ($i = 0; $i < $count; $i++) {
            $at = $cursor->copy()->addMinutes($i * $intervalMinutes);
            if ($at->gt($dayEnd)) {
                throw new RuntimeException(sprintf(
                    'in_day.overflow|%d|Lịch vượt quá 23:59 theo timezone %s.',
                    max(self::MIN_INTERVAL_MINUTES, $intervalMinutes),
                    $tz,
                ));
            }
            $slots[] = $at->copy()->utc();
        }

        return $slots;
    }

    /**
     * @return list<Carbon>
     */
    private function buildIntervalSlots(Carbon $startUtc, int $count, int $intervalMinutes): array
    {
        $slots = [];
        $cursor = $startUtc->copy()->utc();
        for ($i = 0; $i < $count; $i++) {
            $slots[] = $cursor->copy();
            $cursor->addMinutes($intervalMinutes);
        }

        return $slots;
    }

    /**
     * @return list<Carbon>
     */
    private function buildPerDaySlots(
        Carbon $startDayLocal,
        int $count,
        int $perDay,
        string $dayStart,
        string $dayEnd,
        string $tz,
    ): array {
        [$sh, $sm] = $this->parseHm($dayStart);
        [$eh, $em] = $this->parseHm($dayEnd);
        $nowUtc = now()->utc();

        $slots = [];
        $day = $startDayLocal->copy()->timezone($tz)->startOfDay();
        while (count($slots) < $count) {
            $windowStart = $day->copy()->setTime($sh, $sm);
            $windowEnd = $day->copy()->setTime($eh, $em);
            if ($windowEnd->lte($windowStart)) {
                throw new RuntimeException('Khung giờ per_day không hợp lệ.');
            }

            $spanMinutes = max(1, (int) $windowStart->diffInMinutes($windowEnd));
            $step = max(self::MIN_INTERVAL_MINUTES, intdiv($spanMinutes, max(1, $perDay)));

            for ($i = 0; $i < $perDay && count($slots) < $count; $i++) {
                $at = $windowStart->copy()->addMinutes($i * $step)->utc();
                if ($at->lt($nowUtc)) {
                    $at = $nowUtc->copy()->addMinutes(self::MIN_INTERVAL_MINUTES);
                }
                $slots[] = $at;
            }

            $day->addDay();
        }

        return $this->dedupeSlots($slots);
    }

    /**
     * @param  list<array{start: string, end: string}>  $windows
     * @return list<Carbon>
     */
    private function buildRandomWindowSlots(Carbon $startDayLocal, int $count, array $windows, string $tz): array
    {
        if ($windows === []) {
            throw new InvalidArgumentException('Cần ít nhất 1 khung giờ.');
        }

        $slots = [];
        $day = $startDayLocal->copy()->timezone($tz)->startOfDay();
        $guard = 0;

        while (count($slots) < $count && $guard < 5000) {
            $guard++;
            foreach ($windows as $window) {
                if (count($slots) >= $count) {
                    break;
                }

                [$sh, $sm] = $this->parseHm((string) ($window['start'] ?? '08:00'));
                [$eh, $em] = $this->parseHm((string) ($window['end'] ?? '11:30'));
                $from = $day->copy()->setTime($sh, $sm);
                $to = $day->copy()->setTime($eh, $em);
                if ($to->lte($from)) {
                    continue;
                }

                $minutes = (int) $from->diffInMinutes($to);
                $offset = random_int(0, max(0, $minutes));
                $slots[] = $from->copy()->addMinutes($offset)->utc();
            }
            $day->addDay();
        }

        usort($slots, static fn (Carbon $a, Carbon $b): int => $a <=> $b);

        return $this->dedupeSlots(array_slice($slots, 0, $count));
    }

    /**
     * @return list<Carbon>
     */
    private function buildProjectMonthSlots(
        SeoProject $project,
        int $count,
        string $dayStart,
        string $dayEnd,
        string $tz,
    ): array {
        $month = $project->month;
        if ($month === null) {
            throw new RuntimeException('Project month missing — dùng Quick Mode hoặc khoảng tùy chỉnh.');
        }

        $monthStart = $month->copy()->timezone($tz)->startOfMonth()->startOfDay();
        $monthEnd = $month->copy()->timezone($tz)->endOfMonth()->endOfDay();
        $today = Carbon::now($tz)->startOfDay();

        if ($monthEnd->lt($today)) {
            throw new RuntimeException('Tháng dự án đã kết thúc — dùng Quick Mode (In Day / N days).');
        }

        $rangeStart = $monthStart->gt($today) ? $monthStart->copy() : $today->copy();
        $days = max(1, $rangeStart->diffInDays($monthEnd->copy()->startOfDay()) + 1);
        $perDay = max(1, (int) ceil($count / $days));

        return $this->buildPerDaySlots($rangeStart, $count, $perDay, $dayStart, $dayEnd, $tz);
    }

    /**
     * @return list<Carbon>
     */
    private function buildQuickModeSlots(
        int $count,
        int $days,
        string $startTime,
        string $endTime,
        string $tz,
    ): array {
        $days = max(1, $days);
        $nowLocal = Carbon::now($tz);
        $startDay = $nowLocal->copy()->startOfDay();
        if ($nowLocal->format('H:i') > $endTime) {
            $startDay->addDay();
        }

        $perDay = max(1, (int) ceil($count / $days));

        return $this->buildPerDaySlots($startDay, $count, $perDay, $startTime, $endTime, $tz);
    }

    /**
     * @param  list<Carbon>  $slots
     * @return list<Carbon>
     */
    private function dedupeSlots(array $slots): array
    {
        $out = [];
        $prev = null;
        foreach ($slots as $slot) {
            $at = $slot->copy()->utc();
            if ($prev instanceof Carbon && $at->lte($prev)) {
                $at = $prev->copy()->addMinutes(self::MIN_INTERVAL_MINUTES);
            }
            if ($at->lt(now()->utc())) {
                $at = now()->utc()->addMinutes(self::MIN_INTERVAL_MINUTES);
            }
            $out[] = $at;
            $prev = $at;
        }

        return $out;
    }

    private function parseStartAt(mixed $value, string $tz): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->timezone($tz)->utc();
        }
        if (is_string($value) && trim($value) !== '') {
            return SystemDateTime::parseSystemInputToUtc($value);
        }

        return Carbon::now($tz)->addMinutes(self::SAFETY_DELAY_MINUTES)->utc();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseHm(string $value): array
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
            throw new InvalidArgumentException("Giờ không hợp lệ: {$value}");
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
