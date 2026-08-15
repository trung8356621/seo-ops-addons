<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpReport;

/**
 * MCP tool: read stored monthly AI context. Never rebuilds source modules.
 */
final class DomainMonthlyIntelligenceService
{
    public function __construct(
        private readonly McpPeriodService $periods,
        private readonly MonthlyMcpReportService $reports,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, message?: string, data: array<string, mixed>}
     */
    public function read(int $siteId, array $input = []): array
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return ['ok' => false, 'message' => 'Site not found.', 'data' => []];
        }
        $period = $this->resolvePeriod($input);
        if (! $period instanceof SeoMcpPeriod) {
            return ['ok' => false, 'message' => 'No MCP period available.', 'data' => []];
        }
        $report = $this->reports->find($period, $siteId);
        if (! $report instanceof SeoMcpReport || ! is_array($report->ai_context_json)) {
            return [
                'ok' => true,
                'data' => [
                    'schema' => MonthlyMcpReportBuilder::SCHEMA,
                    'period' => $period->periodKey(),
                    'site_id' => $siteId,
                    'status' => 'missing',
                    'ai_context' => null,
                    'rebuilt' => false,
                ],
            ];
        }

        return [
            'ok' => true,
            'data' => [
                'schema' => MonthlyMcpReportBuilder::SCHEMA,
                'period' => $period->periodKey(),
                'site_id' => $siteId,
                'status' => (string) $report->status,
                'revision' => (int) $report->revision,
                'generated_at' => $report->generated_at?->toIso8601String(),
                'ai_context' => $report->ai_context_json,
                'rebuilt' => false,
                'sources' => [
                    McpSourceKey::Site->value,
                    McpSourceKey::Keywords->value,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolvePeriod(array $input): ?SeoMcpPeriod
    {
        $raw = (string) ($input['period'] ?? $input['month'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})$/', $raw, $m) === 1) {
            return $this->periods->find((int) $m[1], (int) $m[2]);
        }
        if (isset($input['year'], $input['month'])) {
            return $this->periods->find((int) $input['year'], (int) $input['month']);
        }

        return $this->periods->currentOpenOrLatestFinalized();
    }
}
