<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankSnapshot;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;

final class KeywordRankComparisonResultService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function buildRows(string $batchId): array
    {
        $snapshots = KeywordRankSnapshot::query()
            ->whereHas('run', static fn ($query) => $query->where('comparison_batch_id', $batchId))
            ->with(['keyword', 'run'])
            ->orderByDesc('checked_at')
            ->get();

        if ($snapshots->isEmpty()) {
            return [];
        }

        return $snapshots
            ->groupBy(static fn (KeywordRankSnapshot $snapshot): string => (string) ($snapshot->keyword?->phrase ?? $snapshot->keyword_id))
            ->map(function ($group, string $keywordPhrase): array {
                $row = [
                    'keyword' => $keywordPhrase,
                    'checked_at' => null,
                ];

                foreach (SerpProviderKeys::all() as $provider) {
                    $providerSnapshot = $group->firstWhere('provider', $provider);
                    $row[$provider] = [
                        'position' => $providerSnapshot?->position,
                        'url' => $providerSnapshot?->ranking_url,
                        'duration_ms' => $providerSnapshot?->duration_ms,
                        'status' => $providerSnapshot?->request_status,
                        'error' => $providerSnapshot?->error_message,
                    ];
                }

                $latest = $group->sortByDesc('checked_at')->first();
                $row['checked_at'] = $latest?->checked_at?->toDateTimeString();

                $positions = collect($row)
                    ->only(SerpProviderKeys::all())
                    ->pluck('position')
                    ->filter(static fn (mixed $value): bool => is_numeric($value))
                    ->map(static fn (mixed $value): float => (float) $value)
                    ->values();

                $row['position_spread'] = $positions->count() >= 2
                    ? round($positions->max() - $positions->min(), 1)
                    : null;

                return $row;
            })
            ->values()
            ->all();
    }
}
