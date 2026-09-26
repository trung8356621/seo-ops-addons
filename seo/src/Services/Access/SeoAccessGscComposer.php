<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Access;

use InvalidArgumentException;
use Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMonthlyPeriod;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextListSlice;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextSource;

/**
 * Composed GSC resource for SEO Access — one load via GscContextSource.
 */
class SeoAccessGscComposer
{
    public const SCHEMA = 'seo.access.gsc.v1';

    /** @var list<string> */
    public const INCLUDE_ALLOWLIST = ['performance', 'opportunities', 'cannibalization'];

    private const LIST_LIMIT = 10;

    public function __construct(
        private readonly GscContextSource $source,
    ) {}

    /**
     * @param  list<string>|null  $include
     * @return array<string, mixed>
     */
    public function compose(int $siteId, ?string $period = null, ?array $include = null): array
    {
        $periodKey = $this->normalizePeriod($period);
        $sections = $this->normalizeInclude($include);
        $ctx = $this->source->load($siteId, $periodKey);
        $summary = $ctx->summary;
        $metrics = $ctx->metrics;
        $limit = self::LIST_LIMIT;

        $payload = [
            'schema' => self::SCHEMA,
            'site_ref' => 'site:'.$siteId,
            'period' => $periodKey,
            'generated_at' => $ctx->generatedAt,
            'source_updated_at' => $ctx->sourceUpdatedAt,
            'available' => $ctx->available,
            'stale' => $ctx->stale,
        ];

        if (in_array('performance', $sections, true)) {
            $payload['performance'] = [
                'period' => $summary['period'] ?? ['current' => $periodKey],
                'totals' => $summary['totals'] ?? null,
                'comparison' => $summary['comparison'] ?? null,
                'clicks' => $metrics['clicks'] ?? 0,
                'impressions' => $metrics['impressions'] ?? 0,
                'absent' => ($metrics['absent'] ?? false) === true,
                'top_queries' => ContextListSlice::fromAll(
                    is_array($summary['top_queries'] ?? null) ? $summary['top_queries'] : [],
                    $limit,
                ),
                'top_pages' => ContextListSlice::fromAll(
                    is_array($summary['top_pages'] ?? null) ? $summary['top_pages'] : [],
                    $limit,
                ),
            ];
        }

        if (in_array('opportunities', $sections, true)) {
            $opportunities = [
                'period' => $periodKey,
                'counts' => [
                    'rising' => (int) ($metrics['rising_count'] ?? 0),
                    'falling' => (int) ($metrics['falling_count'] ?? 0),
                    'high_impression_low_ctr' => (int) ($metrics['ctr_opportunity_count'] ?? 0),
                    'near_page_one' => (int) ($metrics['near_page_one_count'] ?? 0),
                    'content_decay' => (int) ($metrics['content_decay_count'] ?? 0),
                    'new_content' => (int) ($metrics['new_content_opportunity_count'] ?? 0),
                ],
                'absent' => ($metrics['absent'] ?? false) === true,
            ];
            foreach ([
                'rising_queries',
                'falling_queries',
                'high_impression_low_ctr',
                'near_page_one',
                'content_decay',
                'new_content_opportunities',
            ] as $key) {
                $opportunities[$key] = ContextListSlice::fromAll(
                    is_array($summary[$key] ?? null) ? $summary[$key] : [],
                    $limit,
                );
            }
            $payload['opportunities'] = $opportunities;
        }

        if (in_array('cannibalization', $sections, true)) {
            $items = is_array($summary['possible_cannibalization'] ?? null)
                ? $summary['possible_cannibalization']
                : [];
            $payload['cannibalization'] = [
                'period' => $periodKey,
                'count' => (int) ($metrics['possible_cannibalization_count'] ?? count($items)),
                'absent' => ($metrics['absent'] ?? false) === true,
                'items' => ContextListSlice::fromAll($items, $limit),
            ];
        }

        return $payload;
    }

    private function normalizePeriod(?string $period): string
    {
        if ($period === null || trim($period) === '') {
            return GscMonthlyPeriod::currentKey();
        }

        $period = trim($period);
        if (preg_match('/^(\d{4})-(\d{2})$/', $period, $m) !== 1) {
            throw new InvalidArgumentException('Invalid period. Expected YYYY-MM.');
        }
        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Invalid period. Expected YYYY-MM.');
        }

        return GscMonthlyPeriod::normalize($period);
    }

    /**
     * @param  list<string>|null  $include
     * @return list<string>
     */
    private function normalizeInclude(?array $include): array
    {
        if ($include === null || $include === []) {
            return self::INCLUDE_ALLOWLIST;
        }

        $out = [];
        foreach ($include as $item) {
            if (! is_string($item)) {
                continue;
            }
            $key = strtolower(trim($item));
            if (! in_array($key, self::INCLUDE_ALLOWLIST, true)) {
                throw new InvalidArgumentException(
                    'Invalid GSC include section: '.$key.'. Allowed: '.implode(', ', self::INCLUDE_ALLOWLIST)
                );
            }
            $out[] = $key;
        }

        if ($out === []) {
            throw new InvalidArgumentException('include must contain at least one allowlisted section.');
        }

        return array_values(array_unique($out));
    }
}
