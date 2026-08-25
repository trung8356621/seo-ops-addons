<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpReport;
use RuntimeException;

final class MonthlyMcpReportService
{
    public function __construct(
        private readonly MonthlyMcpSnapshotService $snapshots,
        private readonly MonthlyMcpReportBuilder $builder,
        private readonly McpPeriodPolicy $policy,
        private readonly McpPeriodService $periods,
    ) {}

    /**
     * @param  list<string>|null  $sourceKeys
     */
    public function generate(SeoMcpPeriod $period, Site $site, ?array $sourceKeys = null): SeoMcpReport
    {
        $this->policy->assertOpen($period);
        $report = $this->locateOrCreate($period, (int) $site->id);
        $keys = $sourceKeys ?? [McpSourceKey::Site->value, McpSourceKey::Keywords->value, McpSourceKey::Gsc->value];
        $report->generation_status = 'running';
        $report->total_sources = count($keys);
        $report->completed_sources = 0;
        $report->last_activity_at = now();
        $report->save();

        $completed = 0;
        foreach ($keys as $key) {
            $report->current_source = $key;
            $report->last_activity_at = now();
            $report->save();
            $this->snapshots->capture($period, $site, $key);
            $completed++;
            $report->completed_sources = $completed;
            $report->last_activity_at = now();
            $report->save();
        }

        return $this->rebuildFromStored($period, $site, $report);
    }

    public function rebuildFromStored(SeoMcpPeriod $period, Site $site, ?SeoMcpReport $report = null): SeoMcpReport
    {
        $report ??= $this->locateOrCreate($period, (int) $site->id);
        $siteSnap = $this->snapshots->find($period, (int) $site->id, McpSourceKey::Site);
        $kwSnap = $this->snapshots->find($period, (int) $site->id, McpSourceKey::Keywords);
        $gscSnap = $this->snapshots->find($period, (int) $site->id, McpSourceKey::Gsc);
        $built = $this->builder->build($period, (int) $site->id, (string) ($site->domain ?? ''), $siteSnap, $kwSnap, $gscSnap);

        $report->revision = max(1, (int) $report->revision) + ($report->generated_at ? 1 : 0);
        $report->status = $built['status'];
        $report->site_snapshot_id = $siteSnap?->id;
        $report->keyword_snapshot_id = $kwSnap?->id;
        $report->overview_json = $built['overview'];
        $report->highlights_json = $built['highlights'];
        $report->risks_json = $built['risks'];
        $report->opportunities_json = $built['opportunities'];
        $report->action_plan_json = $built['recommended_actions'];
        $report->ai_context_json = $built['ai_context'];
        $report->generation_status = $built['status'] === 'ready' ? 'ready' : 'idle';
        $report->current_source = null;
        $report->generated_at = now();
        $report->last_activity_at = now();
        $report->save();
        $this->periods->refreshCoverage($period);

        return $report->fresh() ?? $report;
    }

    public function find(SeoMcpPeriod $period, int $siteId): ?SeoMcpReport
    {
        return SeoMcpReport::query()
            ->where('period_id', $period->id)
            ->where('site_id', $siteId)
            ->first();
    }

    public function requireOpen(SeoMcpPeriod $period): void
    {
        if (! $period->isOpen()) {
            throw new RuntimeException('Period is finalized.');
        }
    }

    private function locateOrCreate(SeoMcpPeriod $period, int $siteId): SeoMcpReport
    {
        $existing = $this->find($period, $siteId);
        if ($existing instanceof SeoMcpReport) {
            return $existing;
        }

        return SeoMcpReport::query()->create([
            'period_id' => $period->id,
            'site_id' => $siteId,
            'revision' => 0,
            'status' => 'missing',
            'total_sources' => 2,
        ]);
    }
}
