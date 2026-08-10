<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

/**
 * Chia date range sync GSC — data_delay, overlap, chunk size từ config.
 */
final class GscSyncDateRangeService
{
    private const DEFAULT_DATA_DELAY_DAYS = 3;

    private const DEFAULT_INCREMENTAL_OVERLAP_DAYS = 2;

    private const DEFAULT_MAX_DAYS_PER_CHUNK = 28;

    /**
     * @return array{start: string, end: string}
     */
    public function latestAvailableEnd(?\DateTimeInterface $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('today');
        $delayDays = $this->configInt('sync.data_delay_days', self::DEFAULT_DATA_DELAY_DAYS);
        $end = $now->modify('-'.$delayDays.' days');

        return ['start' => '', 'end' => $end->format('Y-m-d')];
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    public function buildIncrementalRanges(string $lastSyncedDate, ?string $endDate = null): array
    {
        $overlap = $this->configInt('sync.incremental_overlap_days', self::DEFAULT_INCREMENTAL_OVERLAP_DAYS);
        $chunkSize = $this->configInt('sync.max_days_per_chunk', self::DEFAULT_MAX_DAYS_PER_CHUNK);

        $start = (new \DateTimeImmutable($lastSyncedDate))->modify('-'.$overlap.' days');
        $end = $endDate !== null && $endDate !== ''
            ? new \DateTimeImmutable($endDate)
            : new \DateTimeImmutable($this->latestAvailableEnd()['end']);

        if ($start > $end) {
            return [];
        }

        return $this->chunkRange($start, $end, max(1, $chunkSize));
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    public function buildFullRanges(string $startDate, ?string $endDate = null): array
    {
        $chunkSize = $this->configInt('sync.max_days_per_chunk', self::DEFAULT_MAX_DAYS_PER_CHUNK);
        $start = new \DateTimeImmutable($startDate);
        $end = $endDate !== null && $endDate !== ''
            ? new \DateTimeImmutable($endDate)
            : new \DateTimeImmutable($this->latestAvailableEnd()['end']);

        if ($start > $end) {
            return [];
        }

        return $this->chunkRange($start, $end, max(1, $chunkSize));
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    private function chunkRange(\DateTimeImmutable $start, \DateTimeImmutable $end, int $chunkSize): array
    {
        $ranges = [];
        $cursor = $start;

        while ($cursor <= $end) {
            $chunkEnd = $cursor->modify('+'.($chunkSize - 1).' days');
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }

            $ranges[] = [
                'start' => $cursor->format('Y-m-d'),
                'end' => $chunkEnd->format('Y-m-d'),
            ];

            $cursor = $chunkEnd->modify('+1 day');
        }

        return $ranges;
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (int) config('seo-content-ai.gsc_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
