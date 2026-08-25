<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscDailyMetric;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyStatus;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscIntelligencePolicy;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityDetectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPlanningSignalNormalizer;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryCannibalizationDetector;
use Throwable;

/**
 * Compact GSC monthly MCP payload. Reads persisted daily facts — never live GSC API.
 */
final class GscMcpContextBuilder
{
    public const SCHEMA = 'gsc.mcp.v1';

    public function __construct(
        private readonly GscPerformanceAggregationService $aggregation,
        private readonly GscOpportunityDetectionService $opportunityDetection,
        private readonly GscQueryCannibalizationDetector $cannibalization,
        private readonly GscPlanningSignalNormalizer $signalNormalizer,
    ) {}

    /**
     * @return array{
     *   metrics: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   context: array<string, mixed>,
     *   source_updated_at: ?string
     * }
     */
    public function build(int $siteId, string $periodKey): array
    {
        $domain = (string) (Site::query()->find($siteId)?->domain ?? '');
        $property = $this->resolveProperty($siteId);
        if ($property === null) {
            return $this->emptyPayload($domain, $periodKey, null, 'no_gsc_property');
        }

        [$currentFrom, $currentTo] = $this->periodBounds($periodKey);
        if ($currentFrom === null) {
            return $this->emptyPayload($domain, $periodKey, $property->public_ref, 'invalid_period');
        }

        $previousKey = $this->previousPeriodKey($periodKey);
        [$prevFrom, $prevTo] = $this->periodBounds($previousKey);

        $currentRows = $this->factsInRange((int) $property->id, $currentFrom, $currentTo);
        $previousRows = ($prevFrom !== null && $prevTo !== null)
            ? $this->factsInRange((int) $property->id, $prevFrom, $prevTo)
            : [];

        $partial = $this->isPartialCurrentMonth($periodKey, $currentTo);
        $sourceUpdatedAt = $property->last_synced_at?->toIso8601String();

        return $this->fromPrepared(
            $currentRows,
            $previousRows,
            $domain,
            $periodKey,
            $previousKey,
            $property->public_ref,
            (string) ($property->property_uri ?? ''),
            $partial,
            $sourceUpdatedAt,
        );
    }

    public function sourceUpdatedAt(int $siteId): ?string
    {
        $property = $this->resolveProperty($siteId);

        return $property?->last_synced_at?->toIso8601String();
    }

    /**
     * @param  list<array<string, mixed>>  $currentRows
     * @param  list<array<string, mixed>>  $previousRows
     * @return array{
     *   metrics: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   context: array<string, mixed>,
     *   source_updated_at: ?string
     * }
     */
    public function fromPrepared(
        array $currentRows,
        array $previousRows,
        string $domain,
        string $periodKey,
        string $previousPeriodKey,
        ?string $propertyRef,
        string $propertyUri = '',
        bool $partial = false,
        ?string $sourceUpdatedAt = null,
    ): array {
        $totals = $this->aggregation->aggregate($currentRows);
        $prevTotals = $this->aggregation->aggregate($previousRows);
        $comparison = $this->aggregation->comparePeriods($totals, $prevTotals);

        $byQueryCurrent = $this->groupByQuery($currentRows);
        $byQueryPrevious = $this->groupByQuery($previousRows);
        $byPage = $this->groupByPage($currentRows);

        $this->opportunityDetection->resetFingerprints();
        $opportunities = [];
        foreach ($byQueryCurrent as $query => $rows) {
            $baseline = $byQueryPrevious[$query] ?? [];
            $opportunities = array_merge(
                $opportunities,
                $this->opportunityDetection->detect($rows, $baseline, [
                    'normalized_query' => $query,
                    'has_published_page' => $this->rowsHavePage($rows),
                ]),
            );
        }

        $cannibalization = [];
        foreach (array_keys($byQueryCurrent) as $query) {
            $cannibalization = array_merge(
                $cannibalization,
                $this->cannibalization->detect($query, $currentRows),
            );
        }

        $planningSignals = $this->signalNormalizer->normalize($opportunities, $cannibalization);

        $rising = $this->filterSignals($planningSignals, ['rising_query']);
        $falling = $this->filterSignals($planningSignals, ['falling_query', 'content_decay']);
        $ctrOpp = $this->filterSignals($planningSignals, ['high_impression_low_ctr']);
        $nearPageOne = $this->filterSignals($planningSignals, ['near_page_one']);
        $decay = $this->filterSignals($planningSignals, ['content_decay']);
        $cannib = $this->filterSignals($planningSignals, ['possible_cannibalization']);
        $newContent = $this->filterSignals($planningSignals, ['new_content_opportunity']);

        $topQueries = $this->topEntities($byQueryCurrent, GscIntelligencePolicy::MAX_TOP_QUERIES);
        $topPages = $this->topEntities($byPage, GscIntelligencePolicy::MAX_TOP_PAGES);

        $metrics = [
            'clicks' => (int) ($totals['clicks'] ?? 0),
            'impressions' => (int) ($totals['impressions'] ?? 0),
            'ctr' => $totals['ctr'] ?? null,
            'avg_position' => $totals['position'] ?? null,
            'query_count' => count($byQueryCurrent),
            'page_count' => count($byPage),
            'rising_count' => count($rising),
            'falling_count' => count($falling),
            'ctr_opportunity_count' => count($ctrOpp),
            'near_page_one_count' => count($nearPageOne),
            'content_decay_count' => count($decay),
            'possible_cannibalization_count' => count($cannib),
            'new_content_opportunity_count' => count($newContent),
            'partial' => $partial,
        ];

        $summary = [
            'identity' => [
                'domain' => $domain,
                'property_ref' => $propertyRef,
                'property_uri' => $propertyUri,
            ],
            'period' => [
                'current' => $periodKey,
                'previous' => $previousPeriodKey,
                'partial' => $partial,
            ],
            'totals' => $totals,
            'previous_totals' => $prevTotals,
            'comparison' => $comparison,
            'top_queries' => $topQueries,
            'top_pages' => $topPages,
            'rising_queries' => array_slice($rising, 0, GscIntelligencePolicy::MAX_RISING),
            'falling_queries' => array_slice($falling, 0, GscIntelligencePolicy::MAX_FALLING),
            'high_impression_low_ctr' => array_slice($ctrOpp, 0, GscIntelligencePolicy::MAX_CTR_OPPORTUNITIES),
            'near_page_one' => array_slice($nearPageOne, 0, GscIntelligencePolicy::MAX_NEAR_PAGE_ONE),
            'content_decay' => array_slice($decay, 0, GscIntelligencePolicy::MAX_CONTENT_DECAY),
            'possible_cannibalization' => array_slice($cannib, 0, GscIntelligencePolicy::MAX_CANNIBALIZATION),
            'new_content_opportunities' => array_slice($newContent, 0, GscIntelligencePolicy::MAX_PLANNING_SIGNALS),
        ];

        $context = [
            'schema' => self::SCHEMA,
            'planning_signals' => $planningSignals,
            'ai_lines' => $this->buildAiLines($planningSignals, $comparison, $partial),
            'note' => 'GSC impressions are Search Console impressions, not global search volume. No keyword difficulty.',
        ];

        return [
            'metrics' => $metrics,
            'summary' => $summary,
            'context' => $context,
            'source_updated_at' => $sourceUpdatedAt,
        ];
    }

    /**
     * @return array{
     *   metrics: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   context: array<string, mixed>,
     *   source_updated_at: ?string
     * }
     */
    private function emptyPayload(string $domain, string $periodKey, ?string $propertyRef, string $reason): array
    {
        return [
            'metrics' => [
                'clicks' => 0,
                'impressions' => 0,
                'ctr' => null,
                'avg_position' => null,
                'query_count' => 0,
                'page_count' => 0,
                'rising_count' => 0,
                'falling_count' => 0,
                'ctr_opportunity_count' => 0,
                'near_page_one_count' => 0,
                'content_decay_count' => 0,
                'possible_cannibalization_count' => 0,
                'new_content_opportunity_count' => 0,
                'partial' => false,
                'absent' => true,
                'absent_reason' => $reason,
            ],
            'summary' => [
                'identity' => [
                    'domain' => $domain,
                    'property_ref' => $propertyRef,
                    'property_uri' => '',
                ],
                'period' => [
                    'current' => $periodKey,
                    'previous' => $this->previousPeriodKey($periodKey),
                    'partial' => false,
                ],
                'top_queries' => [],
                'top_pages' => [],
                'rising_queries' => [],
                'falling_queries' => [],
                'high_impression_low_ctr' => [],
                'near_page_one' => [],
                'content_decay' => [],
                'possible_cannibalization' => [],
                'new_content_opportunities' => [],
            ],
            'context' => [
                'schema' => self::SCHEMA,
                'planning_signals' => [],
                'ai_lines' => [],
                'note' => 'No GSC Search Performance data for this site/period.',
            ],
            'source_updated_at' => null,
        ];
    }

    private function resolveProperty(int $siteId): ?SeoGscProperty
    {
        if ($siteId <= 0) {
            return null;
        }

        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_gsc_properties')) {
                return null;
            }

            return SeoGscProperty::query()
                ->where('site_id', $siteId)
                ->whereNull('archived_at')
                ->where('status', '!=', GscPropertyStatus::Archived->value)
                ->orderByDesc('id')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function factsInRange(int $propertyId, string $from, string $to): array
    {
        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_gsc_daily_metrics')) {
                return [];
            }

            return SeoGscDailyMetric::query()
                ->where('property_id', $propertyId)
                ->whereBetween('metric_date', [$from, $to])
                ->get()
                ->map(static function (SeoGscDailyMetric $m): array {
                    return [
                        'date' => $m->metric_date?->format('Y-m-d') ?? (string) $m->metric_date,
                        'query' => (string) ($m->query ?? ''),
                        'normalized_query' => (string) ($m->normalized_query ?? $m->query ?? ''),
                        'page' => (string) ($m->page ?? ''),
                        'normalized_page' => (string) ($m->normalized_page ?? $m->page ?? ''),
                        'clicks' => (int) $m->clicks,
                        'impressions' => (int) $m->impressions,
                        'ctr' => (float) $m->ctr,
                        'position' => $m->position !== null ? (float) $m->position : null,
                    ];
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByQuery(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $q = (string) ($row['normalized_query'] ?? $row['query'] ?? '');
            if ($q === '') {
                continue;
            }
            $out[$q][] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByPage(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $p = (string) ($row['normalized_page'] ?? $row['page'] ?? '');
            if ($p === '') {
                continue;
            }
            $out[$p][] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @return list<array<string, mixed>>
     */
    private function topEntities(array $groups, int $limit): array
    {
        $ranked = [];
        foreach ($groups as $key => $rows) {
            $agg = $this->aggregation->aggregate($rows);
            $ranked[] = [
                'key' => $key,
                'clicks' => (int) ($agg['clicks'] ?? 0),
                'impressions' => (int) ($agg['impressions'] ?? 0),
                'ctr' => $agg['ctr'] ?? null,
                'position' => $agg['position'] ?? null,
                'row_count' => count($rows),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => ($b['impressions'] <=> $a['impressions']) ?: ($b['clicks'] <=> $a['clicks']));

        return array_slice($ranked, 0, $limit);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function rowsHavePage(array $rows): bool
    {
        foreach ($rows as $row) {
            if (trim((string) ($row['normalized_page'] ?? $row['page'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{type: string, label: string, query: string, lane: string, evidence_type: string, metrics: array<string, mixed>}>  $signals
     * @param  list<string>  $types
     * @return list<array{type: string, label: string, query: string, lane: string, evidence_type: string, metrics: array<string, mixed>}>
     */
    private function filterSignals(array $signals, array $types): array
    {
        return array_values(array_filter(
            $signals,
            static fn (array $s): bool => in_array($s['type'], $types, true),
        ));
    }

    /**
     * @param  list<array{type: string, label: string, query: string, lane: string, evidence_type: string, metrics: array<string, mixed>}>  $signals
     * @param  array<string, mixed>  $comparison
     * @return list<string>
     */
    private function buildAiLines(array $signals, array $comparison, bool $partial): array
    {
        $lines = [];
        if ($partial) {
            $lines[] = 'Period note: current month GSC data is partial — do not treat vs previous full month as equivalent.';
        }
        if (($comparison['baseline_zero'] ?? true) === false) {
            $clicksDelta = (int) ($comparison['clicks_delta'] ?? 0);
            if ($clicksDelta !== 0) {
                $lines[] = 'Site clicks delta vs previous month: '.($clicksDelta > 0 ? '+' : '').$clicksDelta;
            }
        }

        foreach ($signals as $signal) {
            if (count($lines) >= GscIntelligencePolicy::MAX_AI_CONTEXT_LINES) {
                break;
            }
            $lines[] = $signal['label'];
        }

        return $lines;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function periodBounds(string $periodKey): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m) !== 1) {
            return [null, null];
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            return [null, null];
        }

        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');

        return [$from, $to];
    }

    private function previousPeriodKey(string $periodKey): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m) !== 1) {
            return $periodKey;
        }
        $dt = (new \DateTimeImmutable(sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2])))
            ->modify('-1 month');

        return $dt->format('Y-m');
    }

    private function isPartialCurrentMonth(string $periodKey, string $periodEnd): bool
    {
        $today = new \DateTimeImmutable('today');
        if ($today->format('Y-m') !== $periodKey) {
            return false;
        }

        return $today->format('Y-m-d') < $periodEnd;
    }
}
