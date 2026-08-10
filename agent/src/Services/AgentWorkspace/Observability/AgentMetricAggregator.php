<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentMetricAggregate;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentMetricEvent;
use Throwable;

/**
 * Idempotent daily aggregation of metric events.
 */
final class AgentMetricAggregator
{
    /**
     * @return array{buckets: int, events: int}
     */
    public function aggregateDaily(?string $date = null): array
    {
        $day = $date ?? now()->toDateString();
        $events = 0;
        $buckets = 0;

        try {
            $rows = SeoAgentMetricEvent::query()
                ->whereDate('occurred_at', $day)
                ->get();
            $events = $rows->count();

            /** @var array<string, array{sum: float, count: int, dims: array<string, string>, site_id: int}> $groups */
            $groups = [];
            foreach ($rows as $row) {
                $dims = is_array($row->dimensions) ? $row->dimensions : [];
                ksort($dims);
                $dimHash = hash('sha256', json_encode($dims, JSON_THROW_ON_ERROR));
                $siteId = (int) ($row->site_id ?? 0);
                $key = $row->metric_key.'|'.$siteId.'|'.$dimHash;
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'metric_key' => (string) $row->metric_key,
                        'site_id' => $siteId,
                        'dims' => $dims,
                        'dim_hash' => $dimHash,
                        'sum' => 0.0,
                        'count' => 0,
                    ];
                }
                $groups[$key]['sum'] += (float) $row->value;
                $groups[$key]['count']++;
            }

            foreach ($groups as $g) {
                SeoAgentMetricAggregate::query()->updateOrCreate(
                    [
                        'metric_key' => $g['metric_key'],
                        'bucket' => 'day',
                        'bucket_date' => $day,
                        'site_id' => $g['site_id'],
                        'dim_hash' => $g['dim_hash'],
                    ],
                    [
                        'dimensions' => $g['dims'],
                        'value_sum' => $g['sum'],
                        'value_count' => $g['count'],
                    ],
                );
                $buckets++;
            }
        } catch (Throwable) {
            return ['buckets' => $buckets, 'events' => $events];
        }

        return ['buckets' => $buckets, 'events' => $events];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshot(?int $siteId = null, int $days = 7): array
    {
        try {
            $q = SeoAgentMetricAggregate::query()
                ->where('bucket', 'day')
                ->where('bucket_date', '>=', now()->subDays($days)->toDateString());
            if ($siteId !== null) {
                $q->where(function ($inner) use ($siteId): void {
                    $inner->where('site_id', $siteId)->orWhere('site_id', 0);
                });
            }

            return $q->orderByDesc('bucket_date')
                ->limit(500)
                ->get()
                ->map(static fn (SeoAgentMetricAggregate $a): array => [
                    'metric_key' => $a->metric_key,
                    'bucket_date' => optional($a->bucket_date)?->toDateString(),
                    'site_id' => $a->site_id,
                    'dimensions' => $a->dimensions,
                    'value_sum' => $a->value_sum,
                    'value_count' => $a->value_count,
                ])->all();
        } catch (Throwable) {
            return [];
        }
    }
}
