<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Enums\McpPeriodStatus;
use Omnichannel\Addons\Seo\Enums\McpReportStatus;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpReport;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use RuntimeException;

final class McpPeriodService
{
    public const WORKSPACE_DEFAULT = 'default';

    public function __construct(
        private readonly McpPeriodPolicy $policy,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_mcp_periods');
    }

    public function find(int $year, int $month, string $workspaceKey = self::WORKSPACE_DEFAULT): ?SeoMcpPeriod
    {
        if (! $this->tablesReady()) {
            return null;
        }

        return SeoMcpPeriod::query()
            ->where('workspace_key', $workspaceKey)
            ->where('year', $year)
            ->where('month', $month)
            ->first();
    }

    public function create(int $year, int $month, string $workspaceKey = self::WORKSPACE_DEFAULT): SeoMcpPeriod
    {
        if (! $this->tablesReady()) {
            throw new RuntimeException('MCP period tables are not migrated.');
        }
        $existing = $this->find($year, $month, $workspaceKey);
        if ($existing instanceof SeoMcpPeriod) {
            return $existing;
        }

        return SeoMcpPeriod::query()->create([
            'workspace_key' => $workspaceKey,
            'year' => $year,
            'month' => $month,
            'status' => McpPeriodStatus::Open->value,
            'opened_at' => now(),
            'expected_sites' => $this->expectedSiteCount(),
            'available_sites' => 0,
        ]);
    }

    public function ensureCurrentMonth(): SeoMcpPeriod
    {
        return $this->create((int) now()->year, (int) now()->month);
    }

    /**
     * @return list<SeoMcpPeriod>
     */
    public function selectablePeriods(): array
    {
        if (! $this->tablesReady()) {
            return [];
        }
        $rows = SeoMcpPeriod::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(24)
            ->get()
            ->all();
        $current = $this->find((int) now()->year, (int) now()->month);
        if (! $current instanceof SeoMcpPeriod) {
            array_unshift($rows, new SeoMcpPeriod([
                'year' => (int) now()->year,
                'month' => (int) now()->month,
                'status' => McpPeriodStatus::Open->value,
                'expected_sites' => $this->expectedSiteCount(),
                'available_sites' => 0,
            ]));
        }

        return $rows;
    }

    public function currentOpenOrLatestFinalized(): ?SeoMcpPeriod
    {
        if (! $this->tablesReady()) {
            return null;
        }
        $open = SeoMcpPeriod::query()
            ->where('status', McpPeriodStatus::Open->value)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();
        if ($open instanceof SeoMcpPeriod) {
            return $open;
        }

        return SeoMcpPeriod::query()
            ->where('status', McpPeriodStatus::Finalized->value)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();
    }

    /**
     * @return array{needs_confirmation: bool, period?: SeoMcpPeriod, available: int, expected: int}
     */
    public function finalize(SeoMcpPeriod $period, ?int $actorId, bool $confirmedPartial): array
    {
        $expected = $this->expectedSiteCount();
        $available = $this->availableSiteCount($period);
        $gate = $this->policy->finalizeGate($available, $expected, $confirmedPartial);
        if ($gate['needs_confirmation']) {
            return $gate;
        }
        $this->policy->applyFinalize($period, $actorId, $available, $expected);
        $period->save();

        return ['needs_confirmation' => false, 'period' => $period->fresh() ?? $period, 'available' => $available, 'expected' => $expected];
    }

    public function reopen(SeoMcpPeriod $period): SeoMcpPeriod
    {
        $this->policy->applyReopen($period);
        $period->save();

        return $period;
    }

    public function expectedSiteCount(): int
    {
        try {
            return SeoAccessControl::accessibleSitesQuery()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function availableSiteCount(SeoMcpPeriod $period): int
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_mcp_reports') || ! $period->exists) {
            return 0;
        }

        return (int) SeoMcpReport::query()
            ->where('period_id', $period->id)
            ->where('status', McpReportStatus::Ready->value)
            ->whereNotNull('site_id')
            ->distinct()
            ->count('site_id');
    }

    public function refreshCoverage(SeoMcpPeriod $period): void
    {
        if (! $period->exists) {
            return;
        }
        $period->expected_sites = $this->expectedSiteCount();
        $period->available_sites = $this->availableSiteCount($period);
        $period->save();
    }
}
