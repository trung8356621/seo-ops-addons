<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use Omnichannel\Addons\Seo\Enums\McpPeriodStatus;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use RuntimeException;

final class McpPeriodPolicy
{
    /**
     * @return array{needs_confirmation: bool, available: int, expected: int}
     */
    public function finalizeGate(int $availableSites, int $expectedSites, bool $confirmedPartial): array
    {
        $partial = $expectedSites > 0 && $availableSites < $expectedSites;

        return [
            'needs_confirmation' => $partial && ! $confirmedPartial,
            'available' => $availableSites,
            'expected' => $expectedSites,
        ];
    }

    public function assertOpen(SeoMcpPeriod $period): void
    {
        if (! $period->isOpen()) {
            throw new RuntimeException('Period is finalized.');
        }
    }

    public function applyFinalize(SeoMcpPeriod $period, ?int $actorId, int $available, int $expected): SeoMcpPeriod
    {
        $period->status = McpPeriodStatus::Finalized->value;
        $period->finalized_at = now();
        $period->finalized_by = $actorId;
        $period->manual_finalized = true;
        $period->available_sites = $available;
        $period->expected_sites = $expected;

        return $period;
    }

    public function applyReopen(SeoMcpPeriod $period): SeoMcpPeriod
    {
        $period->status = McpPeriodStatus::Open->value;
        $period->finalized_at = null;
        $period->finalized_by = null;
        $period->manual_finalized = false;

        return $period;
    }
}
