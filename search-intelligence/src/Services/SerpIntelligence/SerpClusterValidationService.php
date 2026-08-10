<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums\SerpClusterValidationAction;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums\SerpOverlapBand;

/**
 * Gợi ý validation cluster từ SERP overlap — không mutate DB.
 */
final class SerpClusterValidationService
{
    public function __construct(
        private readonly SerpOverlapService $overlapService,
    ) {}

    /**
     * @param  list<array{keyword_ref: string, results: list<array<string, mixed>>}>  $members
     * @return list<array{
     *   action: SerpClusterValidationAction,
     *   keyword_ref: ?string,
     *   confidence: float,
     *   reason_codes: list<string>,
     *   metadata: array<string, mixed>
     * }>
     */
    public function suggest(array $members, ?array $config = null): array
    {
        if (count($members) < 2) {
            return [[
                'action' => SerpClusterValidationAction::KeepCluster,
                'keyword_ref' => null,
                'confidence' => 0.5,
                'reason_codes' => ['cluster.too_small_for_serp_validation'],
                'metadata' => [],
            ]];
        }

        $outlierThreshold = (float) ($config['outlier_overlap_max'] ?? $this->configFloat('cluster_validation.outlier_overlap_max', 0.2));
        $splitThreshold = (float) ($config['split_overlap_max'] ?? $this->configFloat('cluster_validation.split_overlap_max', 0.25));
        $suggestions = [];
        $pairScores = [];

        for ($i = 0; $i < count($members); $i++) {
            for ($j = $i + 1; $j < count($members); $j++) {
                $a = $members[$i];
                $b = $members[$j];
                $overlap = $this->overlapService->compare(
                    is_array($a['results'] ?? null) ? $a['results'] : [],
                    is_array($b['results'] ?? null) ? $b['results'] : [],
                    $config,
                );
                $pairScores[] = $overlap;
            }
        }

        if ($pairScores === []) {
            return [[
                'action' => SerpClusterValidationAction::ReviewKeyword,
                'keyword_ref' => null,
                'confidence' => 0.4,
                'reason_codes' => ['cluster.no_pairwise_overlap'],
                'metadata' => [],
            ]];
        }

        $avgScore = array_sum(array_column($pairScores, 'score')) / count($pairScores);
        $validPairs = array_filter($pairScores, static fn (array $row): bool => (bool) ($row['valid'] ?? false));

        if ($validPairs === []) {
            return [[
                'action' => SerpClusterValidationAction::ResampleSerp,
                'keyword_ref' => null,
                'confidence' => 0.55,
                'reason_codes' => ['cluster.insufficient_serp_coverage'],
                'metadata' => ['avg_score' => $avgScore],
            ]];
        }

        if ($avgScore < $splitThreshold) {
            $suggestions[] = [
                'action' => SerpClusterValidationAction::SplitCluster,
                'keyword_ref' => null,
                'confidence' => min(0.9, 0.5 + ($splitThreshold - $avgScore)),
                'reason_codes' => ['cluster.low_average_overlap'],
                'metadata' => ['avg_score' => $avgScore, 'threshold' => $splitThreshold],
            ];
        }

        foreach ($members as $member) {
            $keywordRef = (string) ($member['keyword_ref'] ?? '');
            if ($keywordRef === '') {
                continue;
            }

            $scoresAgainstOthers = [];
            foreach ($members as $other) {
                if (($other['keyword_ref'] ?? '') === $keywordRef) {
                    continue;
                }
                $overlap = $this->overlapService->compare(
                    is_array($member['results'] ?? null) ? $member['results'] : [],
                    is_array($other['results'] ?? null) ? $other['results'] : [],
                    $config,
                );
                if ($overlap['valid']) {
                    $scoresAgainstOthers[] = $overlap['score'];
                }
            }

            if ($scoresAgainstOthers === []) {
                continue;
            }

            $memberAvg = array_sum($scoresAgainstOthers) / count($scoresAgainstOthers);
            if ($memberAvg <= $outlierThreshold) {
                $suggestions[] = [
                    'action' => SerpClusterValidationAction::RemoveOutlier,
                    'keyword_ref' => $keywordRef,
                    'confidence' => min(0.92, 0.55 + ($outlierThreshold - $memberAvg)),
                    'reason_codes' => ['keyword.serp_outlier'],
                    'metadata' => ['member_avg_overlap' => $memberAvg],
                ];
            }
        }

        if ($suggestions === []) {
            $band = $this->overlapService->compare(
                is_array($members[0]['results'] ?? null) ? $members[0]['results'] : [],
                is_array($members[1]['results'] ?? null) ? $members[1]['results'] : [],
                $config,
            )['band'];

            if ($band === SerpOverlapBand::VeryHigh || $band === SerpOverlapBand::High) {
                return [[
                    'action' => SerpClusterValidationAction::KeepCluster,
                    'keyword_ref' => null,
                    'confidence' => 0.78,
                    'reason_codes' => ['cluster.high_overlap'],
                    'metadata' => ['avg_score' => $avgScore],
                ]];
            }

            return [[
                'action' => SerpClusterValidationAction::ReviewKeyword,
                'keyword_ref' => null,
                'confidence' => 0.5,
                'reason_codes' => ['cluster.moderate_overlap_review'],
                'metadata' => ['avg_score' => $avgScore],
            ]];
        }

        return $suggestions;
    }

    private function configFloat(string $key, float $default): float
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (float) config('seo-content-ai.serp_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
