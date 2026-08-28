<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence;

use DateTimeImmutable;

/** Calendar-month helpers for GSC Performance Hub (YYYY-MM). */
final class GscMonthlyPeriod
{
    public const FORMAT = 'Y-m';

    public static function currentKey(): string
    {
        return now()->format(self::FORMAT);
    }

    public static function normalize(?string $periodKey): string
    {
        if ($periodKey !== null && preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m) === 1) {
            $month = (int) $m[2];
            if ($month >= 1 && $month <= 12) {
                return sprintf('%04d-%02d', (int) $m[1], $month);
            }
        }

        return self::currentKey();
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function parse(string $periodKey): array
    {
        $normalized = self::normalize($periodKey);

        return [(int) substr($normalized, 0, 4), (int) substr($normalized, 5, 2)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function bounds(string $periodKey): array
    {
        [$year, $month] = self::parse($periodKey);
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');

        return [$from, $to];
    }

    public static function label(string $periodKey): string
    {
        [$year, $month] = self::parse($periodKey);

        return sprintf('%02d/%d', $month, $year);
    }

    public static function previousKey(string $periodKey): string
    {
        [$year, $month] = self::parse($periodKey);
        $dt = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('-1 month');

        return $dt->format(self::FORMAT);
    }

    public static function nextKey(string $periodKey): string
    {
        [$year, $month] = self::parse($periodKey);
        $dt = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('+1 month');

        return $dt->format(self::FORMAT);
    }

    public static function canGoNext(string $periodKey): bool
    {
        return self::normalize($periodKey) < self::currentKey();
    }

    /**
     * @return list<string>
     */
    public static function recentSelectableKeys(int $maxMonths = 24): array
    {
        $keys = [];
        $cursor = new DateTimeImmutable('first day of this month');
        for ($i = 0; $i < $maxMonths; $i++) {
            $keys[] = $cursor->format(self::FORMAT);
            $cursor = $cursor->modify('-1 month');
        }

        return $keys;
    }

    /**
     * @param  list<string>  $withDataKeys
     * @return list<array{key: string, label: string, has_data: bool}>
     */
    public static function mergeOptions(array $withDataKeys, int $maxMonths = 24): array
    {
        $seen = [];
        $options = [];

        foreach (self::recentSelectableKeys($maxMonths) as $key) {
            $seen[$key] = true;
            $options[] = [
                'key' => $key,
                'label' => self::label($key),
                'has_data' => in_array($key, $withDataKeys, true),
            ];
        }

        foreach ($withDataKeys as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            $options[] = [
                'key' => $key,
                'label' => self::label($key),
                'has_data' => true,
            ];
        }

        usort($options, static fn (array $a, array $b): int => strcmp((string) $b['key'], (string) $a['key']));

        return $options;
    }
}
