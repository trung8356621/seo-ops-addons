<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

final class KeywordClusterLineageLedger
{
    public const EVENT_SPLIT = 'SPLIT';

    public const EVENT_PEEL = 'PEEL';

    public const EVENT_REHOME = 'REHOME';

    public const EVENT_COMPETITIVE_REASSIGN = 'COMPETITIVE_REASSIGN';

    public const EVENT_DUPLICATE_MERGE = 'DUPLICATE_MERGE';

    public const EVENT_RELEASE = 'RELEASE';

    public const EVENT_UNCHANGED = 'UNCHANGED';

    /** @var array<int, string> */
    private array $keywordLineage = [];

    /** @var array<string, array{label: string, initial_count: int, member_ids: list<int>}> */
    private array $lineages = [];

    /** @var list<array<string, mixed>> */
    private array $events = [];

    /**
     * @param  list<list<int>>  $initialClusters
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     */
    public static function fromInitialClusters(array $initialClusters, array $profileMap): self
    {
        $ledger = new self();
        foreach ($initialClusters as $index => $memberIds) {
            sort($memberIds, SORT_NUMERIC);
            $lineageId = 'lineage_'.$index;
            $medoidId = $memberIds === []
                ? 0
                : KeywordClusterSimilarityMatrix::medoid($memberIds, self::identitySimilarity($memberIds));
            $label = $profileMap[$medoidId]->phrase ?? ('cluster_'.$index);

            $ledger->lineages[$lineageId] = [
                'label' => $label,
                'initial_count' => count($memberIds),
                'member_ids' => $memberIds,
            ];

            foreach ($memberIds as $keywordId) {
                $ledger->keywordLineage[$keywordId] = $lineageId;
            }
        }

        return $ledger;
    }

    /**
     * @param  list<int>  $keywordIds
     * @param  array<string, mixed>  $meta
     */
    public function record(string $event, array $keywordIds, array $meta = []): void
    {
        sort($keywordIds, SORT_NUMERIC);
        $this->events[] = [
            'event' => $event,
            'keyword_ids' => $keywordIds,
            'meta' => $meta,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @param  list<array{member_ids: list<int>}>  $finalDrafts
     * @param  list<int>  $releasedMemberIds
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @param  array<int, array<int, float>>  $similarity
     * @return array{
     *     lineages: list<array{
     *         lineage_id: string,
     *         initial_label: string,
     *         initial_count: int,
     *         destinations: list<array{label: string, count: int}>,
     *         released: int,
     *         total: int,
     *         conserved: bool,
     *     }>,
     *     all_conserved: bool,
     * }
     */
    public function buildDisposition(
        array $finalDrafts,
        array $releasedMemberIds,
        array $profileMap,
        array $similarity,
    ): array {
        /** @var array<int, string> $keywordDestination */
        $keywordDestination = [];
        foreach ($finalDrafts as $draft) {
            $memberIds = $draft['member_ids'] ?? [];
            if ($memberIds === []) {
                continue;
            }
            $medoidId = KeywordClusterSimilarityMatrix::medoid($memberIds, $similarity);
            $label = $profileMap[$medoidId]->phrase ?? (string) $medoidId;
            foreach ($memberIds as $keywordId) {
                $keywordDestination[$keywordId] = $label;
            }
        }

        foreach ($releasedMemberIds as $keywordId) {
            $keywordDestination[(int) $keywordId] = '__RELEASED__';
        }

        $reports = [];
        $allConserved = true;

        foreach ($this->lineages as $lineageId => $lineage) {
            $destinationCounts = [];
            $released = 0;

            foreach ($lineage['member_ids'] as $keywordId) {
                $dest = $keywordDestination[$keywordId] ?? '__MISSING__';
                if ($dest === '__RELEASED__') {
                    $released++;
                } elseif ($dest === '__MISSING__') {
                    $destinationCounts['__MISSING__'] = ($destinationCounts['__MISSING__'] ?? 0) + 1;
                } else {
                    $destinationCounts[$dest] = ($destinationCounts[$dest] ?? 0) + 1;
                }
            }

            ksort($destinationCounts, SORT_STRING);
            $destinations = [];
            $assigned = 0;
            foreach ($destinationCounts as $label => $count) {
                $destinations[] = ['label' => $label, 'count' => $count];
                $assigned += $count;
            }

            $total = $assigned + $released;
            $conserved = $total === $lineage['initial_count'] && ! isset($destinationCounts['__MISSING__']);
            if (! $conserved) {
                $allConserved = false;
            }

            $reports[] = [
                'lineage_id' => $lineageId,
                'initial_label' => $lineage['label'],
                'initial_count' => $lineage['initial_count'],
                'destinations' => $destinations,
                'released' => $released,
                'total' => $total,
                'conserved' => $conserved,
            ];
        }

        usort(
            $reports,
            static fn (array $a, array $b): int => ($b['initial_count'] <=> $a['initial_count'])
                ?: strcmp((string) $a['initial_label'], (string) $b['initial_label']),
        );

        return [
            'lineages' => $reports,
            'all_conserved' => $allConserved,
        ];
    }

    public function initialLineageForKeyword(int $keywordId): ?string
    {
        return $this->keywordLineage[$keywordId] ?? null;
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, array<int, float>>
     */
    private static function identitySimilarity(array $memberIds): array
    {
        $similarity = [];
        foreach ($memberIds as $leftId) {
            foreach ($memberIds as $rightId) {
                $similarity[$leftId][$rightId] = $leftId === $rightId ? 1.0 : 0.5;
            }
        }

        return $similarity;
    }
}
