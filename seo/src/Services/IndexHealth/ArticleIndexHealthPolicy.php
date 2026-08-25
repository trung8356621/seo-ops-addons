<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\IndexHealth;

use Carbon\Carbon;
use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;
use Omnichannel\Addons\Seo\Enums\ArticleIndexHealthStatus;

/**
 * Derive effective health + monthly due policy.
 */
final class ArticleIndexHealthPolicy
{
    public const RECHECK_MONTHS = 1;

    public function deriveEffective(
        ArticleIndexCheckStatus $checkStatus,
        ?ArticleIndexHealthStatus $previousHealth,
    ): ArticleIndexHealthStatus {
        return match ($checkStatus) {
            ArticleIndexCheckStatus::Indexed => ArticleIndexHealthStatus::Indexed,
            ArticleIndexCheckStatus::Unknown => ArticleIndexHealthStatus::Unknown,
            ArticleIndexCheckStatus::NotIndexed => ($previousHealth === ArticleIndexHealthStatus::Indexed
                || $previousHealth === ArticleIndexHealthStatus::Dropped)
                ? ArticleIndexHealthStatus::Dropped
                : ArticleIndexHealthStatus::NotIndexed,
        };
    }

    public function isDue(?Carbon $lastCheckedAt, ?Carbon $now = null): bool
    {
        if ($lastCheckedAt === null) {
            return true;
        }

        $now = ($now ?? Carbon::now())->copy();
        $dueAfter = $lastCheckedAt->copy()->addMonthsNoOverflow(self::RECHECK_MONTHS);

        return $now->gte($dueAfter);
    }

    public function nextCheckDueAt(?Carbon $lastCheckedAt): ?Carbon
    {
        if ($lastCheckedAt === null) {
            return null;
        }

        return $lastCheckedAt->copy()->addMonthsNoOverflow(self::RECHECK_MONTHS);
    }

    public function needsReview(
        ?ArticleIndexHealthStatus $current,
        ?Carbon $lastCheckedAt,
        ?Carbon $now = null,
    ): bool {
        if ($current === null) {
            return true;
        }

        if (in_array($current, [
            ArticleIndexHealthStatus::Dropped,
            ArticleIndexHealthStatus::NotIndexed,
            ArticleIndexHealthStatus::Unknown,
        ], true)) {
            return true;
        }

        return $this->isDue($lastCheckedAt, $now);
    }
}
