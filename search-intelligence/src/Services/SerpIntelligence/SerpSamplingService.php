<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

/**
 * Chọn keyword đại diện per cluster — explainable scoring factors.
 */
final class SerpSamplingService
{
    /**
     * @param  list<array<string, mixed>>  $keywords  keyword_ref, normalized, volume?, business_score?, has_serp?
     * @return list<array{
     *   keyword_ref: string,
     *   score: float,
     *   factors: list<string>,
     *   selected: bool
     * }>
     */
    public function selectRepresentatives(array $keywords, ?array $config = null): array
    {
        $maxQueries = (int) ($config['max_queries'] ?? $this->configInt('sampling.max_queries', 3));
        $minQueries = (int) ($config['min_queries'] ?? $this->configInt('sampling.min_queries', 1));
        $scored = [];

        foreach ($keywords as $keyword) {
            if (! is_array($keyword)) {
                continue;
            }

            $ref = (string) ($keyword['keyword_ref'] ?? $keyword['ref'] ?? '');
            if ($ref === '') {
                continue;
            }

            $score = 0.0;
            $factors = [];

            $volume = (float) ($keyword['volume'] ?? $keyword['search_volume'] ?? 0);
            if ($volume > 0) {
                $volumeScore = min(0.35, log(max(1.0, $volume), 10) / 10);
                $score += $volumeScore;
                $factors[] = 'volume';
            }

            $businessScore = (float) ($keyword['business_score'] ?? $keyword['score'] ?? 0);
            if ($businessScore > 0) {
                $score += min(0.3, $businessScore / 100);
                $factors[] = 'business_score';
            }

            if (($keyword['is_primary'] ?? false) === true) {
                $score += 0.25;
                $factors[] = 'primary_keyword';
            }

            if (($keyword['has_serp'] ?? false) === true) {
                $score += 0.1;
                $factors[] = 'existing_serp_snapshot';
            } else {
                $score += 0.05;
                $factors[] = 'needs_serp_collection';
            }

            $normalized = (string) ($keyword['normalized'] ?? $keyword['normalized_query'] ?? '');
            $tokenCount = $normalized === '' ? 0 : count(preg_split('/\s+/u', $normalized) ?: []);
            if ($tokenCount >= 2 && $tokenCount <= 5) {
                $score += 0.08;
                $factors[] = 'moderate_query_length';
            }

            $scored[] = [
                'keyword_ref' => $ref,
                'score' => round($score, 4),
                'factors' => $factors,
                'selected' => false,
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $limit = max($minQueries, min($maxQueries, count($scored)));
        for ($i = 0; $i < $limit; $i++) {
            $scored[$i]['selected'] = true;
        }

        return $scored;
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (int) config('seo-content-ai.serp_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
