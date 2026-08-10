<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Chuẩn hóa month context Content Projects: YYYY-MM (vd. 2026-08).
 * Field DB thực tế: seo_projects.month (date = ngày đầu tháng).
 */
final class ContentProjectMonthContext
{
    public static function current(): string
    {
        return CarbonImmutable::now()->startOfMonth()->format('Y-m');
    }

    /**
     * Parse nhiều dạng input thành YYYY-MM, hoặc null nếu không hợp lệ.
     */
    public static function parseOrNull(CarbonImmutable|Carbon|string|null $month): ?string
    {
        if ($month instanceof CarbonImmutable || $month instanceof Carbon) {
            return $month->copy()->startOfMonth()->format('Y-m');
        }

        $raw = trim((string) $month);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            [$year, $monthNum] = array_map('intval', explode('-', $raw));
            if ($monthNum < 1 || $monthNum > 12) {
                return null;
            }

            return sprintf('%04d-%02d', $year, $monthNum);
        }

        try {
            return CarbonImmutable::parse($raw)->startOfMonth()->format('Y-m');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function normalize(CarbonImmutable|Carbon|string|null $month): string
    {
        return self::parseOrNull($month) ?? self::current();
    }

    /** Ngày đầu tháng lưu DB: Y-m-d */
    public static function toDateString(CarbonImmutable|Carbon|string|null $month): string
    {
        $yyyyMm = self::normalize($month);

        return $yyyyMm.'-01';
    }

    /** Hiển thị UI: m/Y */
    public static function display(CarbonImmutable|Carbon|string|null $month): string
    {
        $yyyyMm = self::normalize($month);
        [$year, $monthNum] = explode('-', $yyyyMm);

        return sprintf('%02d/%s', (int) $monthNum, $year);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function selectOptions(int $pastMonths = 12, int $futureMonths = 6): array
    {
        $cursor = CarbonImmutable::now()->startOfMonth()->subMonths($pastMonths);
        $end = CarbonImmutable::now()->startOfMonth()->addMonths($futureMonths);
        $options = [];

        while ($cursor->lte($end)) {
            $value = $cursor->format('Y-m');
            $options[] = [
                'value' => $value,
                'label' => $cursor->format('m/Y'),
            ];
            $cursor = $cursor->addMonth();
        }

        return $options;
    }
}
