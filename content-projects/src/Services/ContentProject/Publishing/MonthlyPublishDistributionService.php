<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Publishing;

use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Even monthly publish distribution — day counts + site-level min spacing within day windows.
 *
 * Pure scheduling math; callers supply month window and existing site anchors (UTC ISO).
 */
final class MonthlyPublishDistributionService
{
    public const DEFAULT_MIN_SPACING_MINUTES = 5;

    public const MIN_SPACING_MINUTES = 1;

    public const MAX_SPACING_MINUTES = 1440;

    /**
     * @param  array<string, mixed>  $options  day_start, day_end, min_spacing_minutes
     * @param  list<string>  $existingUtcIsoSlots
     * @return array{
     *     slots: list<Carbon>,
     *     unscheduled_count: int,
     *     unscheduled_reason: string|null,
     *     window_label: string,
     *     available_days: int,
     *     density_approx: float,
     * }
     */
    public function allocate(
        Carbon $windowStartLocal,
        Carbon $windowEndLocal,
        int $count,
        array $options,
        array $existingUtcIsoSlots,
        string $tz,
        ?string $windowLabel = null,
    ): array {
        if ($count <= 0) {
            return $this->emptyResult($windowLabel, $windowStartLocal, $windowEndLocal, $tz);
        }

        $minSpacing = $this->resolveMinSpacing($options);
        $dayStart = (string) ($options['day_start'] ?? '09:00');
        $dayEnd = (string) ($options['day_end'] ?? '17:00');

        $days = max(1, (int) $windowStartLocal->copy()->startOfDay()
            ->diffInDays($windowEndLocal->copy()->startOfDay()) + 1);

        $label = $windowLabel ?? $windowStartLocal->copy()->timezone($tz)->format('F Y');

        $existingByDay = $this->groupExistingByLocalDay($existingUtcIsoSlots, $tz);
        $existingCounts = [];
        $scan = $windowStartLocal->copy()->timezone($tz)->startOfDay();
        for ($dayIndex = 0; $dayIndex < $days; $dayIndex++) {
            $existingCounts[$dayIndex] = count($existingByDay[$scan->format('Y-m-d')] ?? []);
            $scan->addDay();
        }

        $dayCounts = $this->computeDayCountsBalanced($count, $days, $existingCounts);

        $slots = [];
        $dayCursor = $windowStartLocal->copy()->timezone($tz)->startOfDay();

        for ($dayIndex = 0; $dayIndex < $days; $dayIndex++) {
            $needed = $dayCounts[$dayIndex] ?? 0;
            if ($needed <= 0) {
                $dayCursor->addDay();
                continue;
            }

            $dayKey = $dayCursor->format('Y-m-d');
            $existingLocal = $existingByDay[$dayKey] ?? [];

            $daySlots = $this->assignTimesForDay(
                $dayCursor->copy(),
                $needed,
                $dayStart,
                $dayEnd,
                $minSpacing,
                $existingLocal,
                $tz,
            );

            foreach ($daySlots as $slot) {
                $slots[] = $slot;
            }

            $dayCursor->addDay();
        }

        $scheduled = count($slots);
        $unscheduled = max(0, $count - $scheduled);

        return [
            'slots' => $slots,
            'unscheduled_count' => $unscheduled,
            'unscheduled_reason' => $unscheduled > 0
                ? sprintf(
                    '%d item(s) could not fit in the publishing window without breaking min spacing (%d min).',
                    $unscheduled,
                    $minSpacing,
                )
                : null,
            'window_label' => $label,
            'available_days' => $days,
            'density_approx' => $days > 0 ? round($scheduled / $days, 2) : 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function resolveMinSpacing(array $options): int
    {
        $raw = (int) ($options['min_spacing_minutes'] ?? self::DEFAULT_MIN_SPACING_MINUTES);

        return max(self::MIN_SPACING_MINUTES, min(self::MAX_SPACING_MINUTES, $raw));
    }

    /**
     * @return list<int>  count per day index (length = dayCount)
     */
    public function computeDayCounts(int $itemCount, int $dayCount): array
    {
        return $this->computeDayCountsBalanced($itemCount, $dayCount, []);
    }

    /**
     * Allocate new items onto days so (existing + new) load stays as even as practical.
     * When several days share the minimum load and not all can receive an item, pick evenly among them.
     *
     * @param  list<int>|array<int, int>  $existingCounts  per-day existing scheduled counts
     * @return list<int>
     */
    public function computeDayCountsBalanced(int $itemCount, int $dayCount, array $existingCounts): array
    {
        if ($itemCount <= 0 || $dayCount <= 0) {
            return [];
        }

        $existing = [];
        for ($i = 0; $i < $dayCount; $i++) {
            $existing[$i] = max(0, (int) ($existingCounts[$i] ?? 0));
        }

        $new = array_fill(0, $dayCount, 0);
        $remaining = $itemCount;

        while ($remaining > 0) {
            $minLoad = PHP_INT_MAX;
            for ($i = 0; $i < $dayCount; $i++) {
                $minLoad = min($minLoad, $existing[$i] + $new[$i]);
            }

            $candidates = [];
            for ($i = 0; $i < $dayCount; $i++) {
                if (($existing[$i] + $new[$i]) === $minLoad) {
                    $candidates[] = $i;
                }
            }

            if ($candidates === []) {
                break;
            }

            $take = min($remaining, count($candidates));
            $picked = $take < count($candidates)
                ? $this->evenlySelectIndices($candidates, $take)
                : $candidates;

            foreach ($picked as $idx) {
                $new[$idx]++;
            }

            $remaining -= count($picked);
        }

        return $new;
    }

    /**
     * @param  list<int>  $candidates
     * @return list<int>
     */
    public function evenlySelectIndices(array $candidates, int $take): array
    {
        $n = count($candidates);
        if ($take <= 0 || $n === 0) {
            return [];
        }

        if ($take >= $n) {
            return array_values($candidates);
        }

        $used = [];
        $picked = [];

        for ($k = 0; $k < $take; $k++) {
            $pos = (int) round(($k + 0.5) * $n / $take) - 1;
            $pos = max(0, min($n - 1, $pos));

            while (isset($used[$pos]) && $pos < $n - 1) {
                $pos++;
            }

            if (isset($used[$pos])) {
                for ($scan = 0; $scan < $n; $scan++) {
                    if (! isset($used[$scan])) {
                        $pos = $scan;
                        break;
                    }
                }
            }

            $used[$pos] = true;
            $picked[] = $candidates[$pos];
        }

        sort($picked);

        return $picked;
    }

    /**
     * @param  list<string>  $existingUtcIsoSlots
     * @return array<string, list<Carbon>>  Y-m-d => local instants
     */
    public function groupExistingByLocalDay(array $existingUtcIsoSlots, string $tz): array
    {
        $grouped = [];

        foreach ($existingUtcIsoSlots as $iso) {
            if (! is_string($iso) || trim($iso) === '') {
                continue;
            }

            $local = Carbon::parse($iso)->timezone($tz);
            $key = $local->format('Y-m-d');
            $grouped[$key] ??= [];
            $grouped[$key][] = $local->copy();
        }

        foreach ($grouped as $key => $times) {
            usort($times, static fn (Carbon $a, Carbon $b): int => $a <=> $b);
            $grouped[$key] = $times;
        }

        return $grouped;
    }

    /**
     * @param  list<Carbon>  $existingLocal
     * @return list<Carbon>  UTC
     */
    public function assignTimesForDay(
        Carbon $dayLocal,
        int $needed,
        string $dayStart,
        string $dayEnd,
        int $minSpacingMinutes,
        array $existingLocal,
        string $tz,
    ): array {
        if ($needed <= 0) {
            return [];
        }

        [$sh, $sm] = $this->parseHm($dayStart);
        [$eh, $em] = $this->parseHm($dayEnd);

        $windowStart = $dayLocal->copy()->timezone($tz)->setTime($sh, $sm, 0);
        $windowEnd = $dayLocal->copy()->timezone($tz)->setTime($eh, $em, 0);

        if ($windowEnd->lte($windowStart)) {
            throw new InvalidArgumentException('Day window invalid: day_end must be after day_start.');
        }

        $now = Carbon::now($tz);
        $cursor = $windowStart->copy();

        if ($dayLocal->isSameDay($now) && $now->gt($cursor)) {
            $cursor = $now->copy()->second(0)->microsecond(0);
            if ($cursor->lt($windowStart)) {
                $cursor = $windowStart->copy();
            }
        }

        $occupied = array_map(static fn (Carbon $c): Carbon => $c->copy(), $existingLocal);
        usort($occupied, static fn (Carbon $a, Carbon $b): int => $a <=> $b);

        $out = [];

        while (count($out) < $needed) {
            $cursor = $this->nextAvailableAt($cursor, $occupied, $minSpacingMinutes);

            if ($cursor->gt($windowEnd)) {
                break;
            }

            $utc = $cursor->copy()->utc();
            $out[] = $utc;
            $occupied[] = $cursor->copy();
            usort($occupied, static fn (Carbon $a, Carbon $b): int => $a <=> $b);
            $cursor = $cursor->copy()->addMinutes($minSpacingMinutes);
        }

        return $out;
    }

    /**
     * @param  list<Carbon>  $occupied  local times sorted
     */
    public function nextAvailableAt(Carbon $cursor, array $occupied, int $minSpacingMinutes): Carbon
    {
        $candidate = $cursor->copy();
        $changed = true;
        $guard = 0;

        while ($changed && $guard < 500) {
            $guard++;
            $changed = false;

            foreach ($occupied as $existing) {
                $diffMinutes = (int) abs($candidate->diffInMinutes($existing, false));

                if ($diffMinutes < $minSpacingMinutes) {
                    $candidate = $existing->copy()->addMinutes($minSpacingMinutes);
                    $changed = true;
                }
            }
        }

        return $candidate;
    }

    /**
     * @return array{start: Carbon, end: Carbon, days: int, label: string}
     */
    public function resolveProjectMonthWindow(Carbon $projectMonth, string $tz): array
    {
        $monthStart = $projectMonth->copy()->timezone($tz)->startOfMonth()->startOfDay();
        $monthEnd = $projectMonth->copy()->timezone($tz)->endOfMonth()->endOfDay();
        $today = Carbon::now($tz)->startOfDay();

        if ($monthEnd->lt($today)) {
            throw new RuntimeException('Publishing window has already ended.');
        }

        $rangeStart = $monthStart->gt($today) ? $monthStart->copy() : $today->copy();
        $days = max(1, (int) $rangeStart->copy()->startOfDay()
            ->diffInDays($monthEnd->copy()->startOfDay()) + 1);

        $label = $projectMonth->copy()->timezone($tz)->format('F Y');
        if ($rangeStart->gt($monthStart)) {
            $label .= ' ('.__('seo-content-ai::filament.projects.publishing_window_remaining').')';
        }

        return [
            'start' => $rangeStart,
            'end' => $monthEnd,
            'days' => $days,
            'label' => $label,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseHm(string $value): array
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
            throw new InvalidArgumentException("Invalid time: {$value}");
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * @return array{
     *     slots: list<Carbon>,
     *     unscheduled_count: int,
     *     unscheduled_reason: string|null,
     *     window_label: string,
     *     available_days: int,
     *     density_approx: float,
     * }
     */
    private function emptyResult(
        ?string $windowLabel,
        Carbon $windowStartLocal,
        Carbon $windowEndLocal,
        string $tz,
    ): array {
        $days = max(1, (int) $windowStartLocal->copy()->startOfDay()
            ->diffInDays($windowEndLocal->copy()->startOfDay()) + 1);

        return [
            'slots' => [],
            'unscheduled_count' => 0,
            'unscheduled_reason' => null,
            'window_label' => $windowLabel ?? $windowStartLocal->copy()->timezone($tz)->format('F Y'),
            'available_days' => $days,
            'density_approx' => 0.0,
        ];
    }
}
