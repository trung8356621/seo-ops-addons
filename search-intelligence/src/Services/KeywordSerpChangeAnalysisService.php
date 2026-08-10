<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordGroupMetricType;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordGroupMetricSnapshot;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankSnapshot;
use Illuminate\Support\Collection;

final class KeywordSerpChangeAnalysisService
{
    public const TOP_N = 10;

    /**
     * @return list<array<string, mixed>>
     */
    public function buildChanges(?int $groupId, string $provider): array
    {
        if ($groupId === null || $groupId <= 0) {
            return [];
        }

        $snapshots = KeywordRankSnapshot::query()
            ->where('rank_group_id', $groupId)
            ->where('provider', $provider)
            ->orderByDesc('checked_at')
            ->with('keyword')
            ->get()
            ->groupBy(static fn (KeywordRankSnapshot $snapshot): int => (int) ($snapshot->rank_group_item_id ?? 0));

        $changes = [];

        foreach ($snapshots as $itemId => $group) {
            if ((int) $itemId <= 0 || $group->count() < 2) {
                continue;
            }

            /** @var Collection<int, KeywordRankSnapshot> $ordered */
            $ordered = $group->sortByDesc('checked_at')->values();
            $latest = $ordered->get(0);
            $previous = $ordered->get(1);

            if ($latest === null || $previous === null) {
                continue;
            }

            $analysis = $this->analyzePair($latest, $previous);
            if ($analysis === null) {
                continue;
            }

            $changes[] = $analysis;
        }

        usort($changes, static fn (array $a, array $b): int => abs((int) ($b['change'] ?? 0)) <=> abs((int) ($a['change'] ?? 0)));

        return array_slice($changes, 0, 50);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function analyzePair(KeywordRankSnapshot $latest, KeywordRankSnapshot $previous): ?array
    {
        $latestPos = $this->normalizePosition($latest);
        $previousPos = $this->normalizePosition($previous);
        $latestInTop = $latestPos !== null && $latestPos <= self::TOP_N;
        $previousInTop = $previousPos !== null && $previousPos <= self::TOP_N;

        $changeType = 'unchanged';
        $delta = null;

        if ($previousInTop && ! $latestInTop) {
            $changeType = 'lost';
        } elseif (! $previousInTop && $latestInTop) {
            $changeType = 'entered';
        } elseif ($latestPos !== null && $previousPos !== null && $latestPos !== $previousPos) {
            $changeType = 'rank_delta';
            $delta = (int) round((float) $previousPos - (float) $latestPos);
        } elseif ($this->urlsChanged($latest, $previous)) {
            $changeType = 'url_changed';
        }

        if ($changeType === 'unchanged') {
            return null;
        }

        return [
            'keyword' => (string) ($latest->keyword?->phrase ?? ''),
            'change_type' => $changeType,
            'position' => $latestPos,
            'previous_position' => $previousPos,
            'change' => $delta,
            'url' => $latest->ranking_url,
            'previous_url' => $previous->ranking_url,
            'volume' => $this->latestMetricValue($latest, KeywordGroupMetricType::SearchVolume),
            'allintitle' => $this->latestMetricValue($latest, KeywordGroupMetricType::Allintitle),
            'updated_at' => $latest->checked_at?->toDateTimeString(),
        ];
    }

    private function normalizePosition(KeywordRankSnapshot $snapshot): ?float
    {
        if ($snapshot->position === null) {
            return null;
        }

        if (in_array($snapshot->request_status, ['success_not_found'], true)) {
            return null;
        }

        return (float) $snapshot->position;
    }

    private function urlsChanged(KeywordRankSnapshot $latest, KeywordRankSnapshot $previous): bool
    {
        $latestUrl = trim((string) ($latest->ranking_url ?? ''));
        $previousUrl = trim((string) ($previous->ranking_url ?? ''));

        if ($latestUrl === '' || $previousUrl === '') {
            return false;
        }

        return $latestUrl !== $previousUrl;
    }

    private function latestMetricValue(KeywordRankSnapshot $rankSnapshot, KeywordGroupMetricType $metricType): ?int
    {
        $itemId = (int) ($rankSnapshot->rank_group_item_id ?? 0);
        if ($itemId <= 0) {
            return null;
        }

        $value = KeywordGroupMetricSnapshot::query()
            ->where('rank_group_item_id', $itemId)
            ->where('metric_type', $metricType->value)
            ->orderByDesc('checked_at')
            ->value('value_int');

        return is_numeric($value) ? (int) $value : null;
    }
}
