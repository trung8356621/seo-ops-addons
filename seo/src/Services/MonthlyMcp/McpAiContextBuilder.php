<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpReport;
use Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot;

/**
 * AI-facing MCP context. Reuses combined markdown from {@see McpMarkdownRenderer}.
 */
final class McpAiContextBuilder
{
    public function __construct(
        private readonly McpMarkdownRenderer $renderer,
        private readonly McpPeriodService $periods,
        private readonly MonthlyMcpReportService $reports,
        private readonly MonthlyMcpSnapshotService $snapshots,
    ) {}

    public function build(int $siteId, string $periodKey): string
    {
        $combined = $this->renderer->renderCombined($siteId, $periodKey);
        $metadata = $this->buildMetadata($siteId, $periodKey);

        return $metadata."\n\n---\n\n".$combined;
    }

    private function buildMetadata(int $siteId, string $periodKey): string
    {
        $period = $this->resolvePeriod($periodKey);
        $report = $period instanceof SeoMcpPeriod ? $this->reports->find($period, $siteId) : null;
        $domain = $this->resolveDomain($siteId, $period);
        $generatedAt = $report instanceof SeoMcpReport
            ? ($report->generated_at?->toDateTimeString() ?? now()->toDateTimeString())
            : now()->toDateTimeString();
        $periodLabel = $this->periodLabel($periodKey);
        $periodStatus = $period instanceof SeoMcpPeriod
            ? ($period->isFinalized() ? 'Finalized' : 'Open')
            : 'Unknown';

        return implode("\n", [
            '# MCP Context',
            '',
            '- Domain: '.$domain,
            '- Period: '.$periodLabel,
            '- MCP status: '.$periodStatus,
            '- Generated at: '.$generatedAt,
            '- Source: combined MCP markdown (site + keywords + report synthesis)',
        ]);
    }

    private function resolveDomain(int $siteId, ?SeoMcpPeriod $period): string
    {
        if ($period instanceof SeoMcpPeriod) {
            foreach ([McpSourceKey::Site, McpSourceKey::Keywords] as $source) {
                $snap = $this->snapshots->find($period, $siteId, $source);
                if (! $snap instanceof SeoMcpSourceSnapshot) {
                    continue;
                }
                $summary = is_array($snap->summary_json) ? $snap->summary_json : [];
                $identity = is_array($summary['identity'] ?? null) ? $summary['identity'] : [];
                $domain = trim((string) ($identity['domain'] ?? ''));
                if ($domain !== '') {
                    return $domain;
                }
            }
        }

        $site = Site::query()->find($siteId);

        return $site instanceof Site ? (string) ($site->domain ?? '') : '';
    }

    private function resolvePeriod(string $periodKey): ?SeoMcpPeriod
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m) !== 1) {
            return null;
        }

        return $this->periods->find((int) $m[1], (int) $m[2]);
    }

    private function periodLabel(string $periodKey): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m) !== 1) {
            return $periodKey;
        }

        return sprintf('%02d/%s', (int) $m[2], $m[1]);
    }
}
