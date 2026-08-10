<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums\SerpOverlapBand;

/**
 * SERP overlap giữa keywords — shared normalized URLs, position-weighted optional.
 */
final class SerpOverlapService
{
    public const OVERLAP_VERSION = '1.0.0';

    public function __construct(
        private readonly SerpUrlNormalizationService $urlNormalizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $resultsA
     * @param  list<array<string, mixed>>  $resultsB
     * @return array{
     *   score: float,
     *   band: SerpOverlapBand,
     *   shared_urls: list<string>,
     *   comparable_count: int,
     *   valid: bool,
     *   version: string
     * }
     */
    public function compare(array $resultsA, array $resultsB, ?array $config = null): array
    {
        $topN = (int) ($config['top_n'] ?? $this->configInt('overlap.top_n', 10));
        $minValid = (int) ($config['min_valid'] ?? $this->configInt('overlap.min_valid', 5));
        $positionWeighted = (bool) ($config['position_weighted'] ?? $this->configBool('overlap.position_weighted', true));

        $urlsA = $this->extractNormalizedUrls($resultsA, $topN);
        $urlsB = $this->extractNormalizedUrls($resultsB, $topN);
        $comparableCount = min(count($urlsA), count($urlsB));

        if ($comparableCount < $minValid) {
            return [
                'score' => 0.0,
                'band' => SerpOverlapBand::Low,
                'shared_urls' => [],
                'comparable_count' => $comparableCount,
                'valid' => false,
                'version' => self::OVERLAP_VERSION,
            ];
        }

        $shared = array_values(array_intersect($urlsA, $urlsB));
        $unionCount = count(array_unique(array_merge($urlsA, $urlsB)));
        $score = $unionCount > 0 ? count($shared) / $unionCount : 0.0;

        if ($positionWeighted) {
            $score = $this->positionWeightedScore($resultsA, $resultsB, $topN, $shared);
        }

        return [
            'score' => round($score, 4),
            'band' => $this->bandForScore($score),
            'shared_urls' => $shared,
            'comparable_count' => $comparableCount,
            'valid' => true,
            'version' => self::OVERLAP_VERSION,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<string>
     */
    private function extractNormalizedUrls(array $results, int $topN): array
    {
        $urls = [];
        foreach (array_slice($results, 0, $topN) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = (string) ($row['normalized_url'] ?? $row['url'] ?? $row['link'] ?? '');
            if ($url === '') {
                continue;
            }
            if (! isset($row['normalized_url'])) {
                $url = $this->urlNormalizer->normalize($url)['normalized_url'];
            }
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  list<string>  $shared
     */
    private function positionWeightedScore(array $resultsA, array $resultsB, int $topN, array $shared): float
    {
        if ($shared === []) {
            return 0.0;
        }

        $weights = $this->configFloatMap('overlap.position_weights', $this->defaultPositionWeights($topN));
        $score = 0.0;
        $maxScore = 0.0;

        foreach (array_slice($resultsA, 0, $topN) as $index => $rowA) {
            if (! is_array($rowA)) {
                continue;
            }
            $urlA = $this->normalizeRowUrl($rowA);
            $weight = $weights[(string) ($index + 1)] ?? max(0.05, 1.0 - ($index * 0.08));
            $maxScore += $weight;

            foreach (array_slice($resultsB, 0, $topN) as $indexB => $rowB) {
                if (! is_array($rowB)) {
                    continue;
                }
                $urlB = $this->normalizeRowUrl($rowB);
                if ($urlA !== '' && $urlA === $urlB) {
                    $weightB = $weights[(string) ($indexB + 1)] ?? max(0.05, 1.0 - ($indexB * 0.08));
                    $score += ($weight + $weightB) / 2;
                    break;
                }
            }
        }

        return $maxScore > 0 ? min(1.0, $score / $maxScore) : 0.0;
    }

    /** @return array<string, float> */
    private function defaultPositionWeights(int $topN): array
    {
        $weights = [];
        for ($i = 1; $i <= $topN; $i++) {
            $weights[(string) $i] = max(0.05, 1.1 - ($i * 0.1));
        }

        return $weights;
    }

    /** @param array<string, mixed> $row */
    private function normalizeRowUrl(array $row): string
    {
        $url = (string) ($row['normalized_url'] ?? $row['url'] ?? $row['link'] ?? '');
        if ($url === '') {
            return '';
        }

        if (isset($row['normalized_url'])) {
            return $url;
        }

        return $this->urlNormalizer->normalize($url)['normalized_url'];
    }

    private function bandForScore(float $score): SerpOverlapBand
    {
        $bands = $this->configFloatMap('overlap.bands', [
            'low' => 0.15,
            'moderate' => 0.35,
            'high' => 0.55,
            'very_high' => 0.75,
        ]);

        if ($score >= ($bands['very_high'] ?? 0.75)) {
            return SerpOverlapBand::VeryHigh;
        }
        if ($score >= ($bands['high'] ?? 0.55)) {
            return SerpOverlapBand::High;
        }
        if ($score >= ($bands['moderate'] ?? 0.35)) {
            return SerpOverlapBand::Moderate;
        }

        return SerpOverlapBand::Low;
    }

    /** @return array<string, float> */
    private function configFloatMap(string $key, array $default): array
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = config('seo-content-ai.serp_intelligence.'.$key, $default);

            return is_array($value) ? array_map('floatval', $value) : $default;
        } catch (\Throwable) {
            return $default;
        }
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

    private function configBool(string $key, bool $default): bool
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (bool) config('seo-content-ai.serp_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
