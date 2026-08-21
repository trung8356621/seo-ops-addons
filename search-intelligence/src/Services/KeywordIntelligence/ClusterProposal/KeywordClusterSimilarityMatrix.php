<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

final class KeywordClusterSimilarityMatrix
{
    /**
     * @param  list<int>  $clusterIds
     * @param  array<int, array<int, float>>  $similarity
     */
    public static function cohesion(array $clusterIds, array $similarity): float
    {
        if (count($clusterIds) < 2) {
            return 1.0;
        }

        $sum = 0.0;
        $pairs = 0;
        $count = count($clusterIds);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $sum += $similarity[$clusterIds[$i]][$clusterIds[$j]] ?? 0.0;
                $pairs++;
            }
        }

        return $pairs > 0 ? round($sum / $pairs, 6) : 0.0;
    }

    /**
     * @param  list<int>  $clusterIds
     * @param  array<int, array<int, float>>  $similarity
     */
    public static function medoid(array $clusterIds, array $similarity): int
    {
        $bestId = $clusterIds[0];
        $bestAverage = -1.0;

        foreach ($clusterIds as $candidateId) {
            $sum = 0.0;
            $pairs = 0;
            foreach ($clusterIds as $otherId) {
                if ($otherId === $candidateId) {
                    continue;
                }
                $sum += $similarity[$candidateId][$otherId] ?? 0.0;
                $pairs++;
            }
            $average = $pairs > 0 ? $sum / $pairs : 0.0;
            if ($average > $bestAverage || ($average === $bestAverage && $candidateId < $bestId)) {
                $bestAverage = $average;
                $bestId = $candidateId;
            }
        }

        return $bestId;
    }

    /**
     * @param  list<int>  $clusterIds
     * @param  array<int, array<int, float>>  $similarity
     */
    public static function minPairSimilarity(array $clusterIds, array $similarity): float
    {
        $min = 1.0;
        $count = count($clusterIds);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $min = min($min, $similarity[$clusterIds[$i]][$clusterIds[$j]] ?? 0.0);
            }
        }

        return round($min, 6);
    }

    /**
     * @param  list<int>  $clusterIds
     * @param  array<int, array<int, float>>  $similarity
     * @return array<int, float>
     */
    public static function representativeSimilarities(int $medoidId, array $clusterIds, array $similarity): array
    {
        $values = [];
        foreach ($clusterIds as $memberId) {
            $values[$memberId] = round($similarity[$medoidId][$memberId] ?? 0.0, 6);
        }

        return $values;
    }

    /**
     * @param  list<float>  $values
     */
    public static function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $index = ($percentile / 100.0) * (count($values) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        if ($lower === $upper) {
            return round($values[$lower], 6);
        }

        $weight = $index - $lower;

        return round($values[$lower] * (1.0 - $weight) + $values[$upper] * $weight, 6);
    }

    /**
     * @param  list<int>  $leftIds
     * @param  list<int>  $rightIds
     * @param  array<int, array<int, float>>  $similarity
     */
    public static function averageCrossSimilarity(array $leftIds, array $rightIds, array $similarity): float
    {
        if ($leftIds === [] || $rightIds === []) {
            return 0.0;
        }

        $sum = 0.0;
        $pairs = 0;
        foreach ($leftIds as $leftId) {
            foreach ($rightIds as $rightId) {
                $sum += $similarity[$leftId][$rightId] ?? 0.0;
                $pairs++;
            }
        }

        return $pairs > 0 ? round($sum / $pairs, 6) : 0.0;
    }
}
