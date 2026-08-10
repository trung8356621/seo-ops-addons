<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums\SerpSnapshotFreshnessStatus;

/**
 * fresh / stale / expired theo collected_at + config days.
 */
final class SerpSnapshotFreshnessService
{
    /**
     * @return array{
     *   status: SerpSnapshotFreshnessStatus,
     *   age_days: ?int,
     *   fresh_until_days: int,
     *   stale_until_days: int,
     *   reason_codes: list<string>
     * }
     */
    public function evaluate(?string $collectedAt, ?array $config = null): array
    {
        $freshDays = (int) ($config['fresh_days'] ?? $this->configInt('freshness.fresh_days', 7));
        $staleDays = (int) ($config['stale_days'] ?? $this->configInt('freshness.stale_days', 30));

        if ($collectedAt === null || trim($collectedAt) === '') {
            return [
                'status' => SerpSnapshotFreshnessStatus::Unknown,
                'age_days' => null,
                'fresh_until_days' => $freshDays,
                'stale_until_days' => $staleDays,
                'reason_codes' => ['snapshot.missing_collected_at'],
            ];
        }

        $timestamp = strtotime($collectedAt);
        if ($timestamp === false) {
            return [
                'status' => SerpSnapshotFreshnessStatus::Unknown,
                'age_days' => null,
                'fresh_until_days' => $freshDays,
                'stale_until_days' => $staleDays,
                'reason_codes' => ['snapshot.invalid_collected_at'],
            ];
        }

        $ageDays = (int) floor(max(0, time() - $timestamp) / 86400);
        $status = SerpSnapshotFreshnessStatus::Expired;
        $reasonCodes = ['snapshot.expired'];

        if ($ageDays <= $freshDays) {
            $status = SerpSnapshotFreshnessStatus::Fresh;
            $reasonCodes = ['snapshot.fresh'];
        } elseif ($ageDays <= $staleDays) {
            $status = SerpSnapshotFreshnessStatus::Stale;
            $reasonCodes = ['snapshot.stale'];
        }

        return [
            'status' => $status,
            'age_days' => $ageDays,
            'fresh_until_days' => $freshDays,
            'stale_until_days' => $staleDays,
            'reason_codes' => $reasonCodes,
        ];
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (int) config('seo-content-ai.serp_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
