<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpPageType;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpResultType;

/**
 * Intent evidence từ SERP results + features — KHÔNG dùng keyword token rules.
 */
final class SerpIntentEvidenceService
{
    public const INTENT_EVIDENCE_VERSION = '1.0.0';

    public function __construct(
        private readonly SerpPageTypeClassifier $pageTypeClassifier,
        private readonly SerpResultClassifier $resultClassifier,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $features
     * @return array{
     *   observed_primary_intent: string,
     *   secondary_intents: list<string>,
     *   dominant_page_types: list<string>,
     *   feature_distribution: array<string, int>,
     *   confidence: float,
     *   reason_codes: list<string>,
     *   version: string
     * }
     */
    public function analyze(array $results, array $features = []): array
    {
        if ($results === [] && $features === []) {
            return $this->insufficient('no_results_or_features');
        }

        $pageTypeCounts = [];
        $resultTypeCounts = [];
        $featureDistribution = [];

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $classified = $this->resultClassifier->classify($row);
            $resultType = $classified['type'];
            $resultTypeCounts[$resultType->value] = ($resultTypeCounts[$resultType->value] ?? 0) + 1;

            $page = $this->pageTypeClassifier->classify([
                'url' => $row['url'] ?? $row['link'] ?? '',
                'title' => $row['title'] ?? '',
                'snippet' => $row['snippet'] ?? $row['description'] ?? '',
                'schema_types' => $row['schema_types'] ?? [],
            ]);
            $pageTypeEnum = $page['page_type'] instanceof SerpPageType
                ? $page['page_type']
                : (SerpPageType::tryFrom((string) $page['page_type']) ?? SerpPageType::Unknown);
            $pageType = $pageTypeEnum->value;
            $pageTypeCounts[$pageType] = ($pageTypeCounts[$pageType] ?? 0) + 1;
        }

        foreach ($features as $feature) {
            if (! is_array($feature)) {
                continue;
            }
            $key = mb_strtolower((string) ($feature['type'] ?? $feature['feature_type'] ?? 'unknown'), 'UTF-8');
            $featureDistribution[$key] = ($featureDistribution[$key] ?? 0) + 1;
        }

        $intentScores = $this->scoreIntentsFromSignals($resultTypeCounts, $pageTypeCounts, $featureDistribution);
        if ($intentScores === []) {
            return $this->insufficient('weak_signal_distribution');
        }

        arsort($intentScores);
        $primary = (string) array_key_first($intentScores);
        $primaryScore = (float) $intentScores[$primary];
        $secondary = [];
        foreach ($intentScores as $intent => $score) {
            if ($intent === $primary || $score < $primaryScore * 0.55) {
                continue;
            }
            $secondary[] = $intent;
        }

        $dominantPageTypes = $this->topKeys($pageTypeCounts, 3);
        $reasonCodes = $this->buildReasonCodes($resultTypeCounts, $pageTypeCounts, $featureDistribution);

        $confidence = min(0.95, max(0.35, $primaryScore));
        if (count($results) >= 3 && $primaryScore >= 0.2) {
            $confidence = min(0.95, max($confidence, round($primaryScore + 0.2, 2)));
        }

        return [
            'observed_primary_intent' => $primary,
            'secondary_intents' => $secondary,
            'dominant_page_types' => $dominantPageTypes,
            'feature_distribution' => $featureDistribution,
            'confidence' => $confidence,
            'reason_codes' => $reasonCodes,
            'version' => self::INTENT_EVIDENCE_VERSION,
        ];
    }

    /**
     * @param  array<string, int>  $resultTypeCounts
     * @param  array<string, int>  $pageTypeCounts
     * @param  array<string, int>  $featureDistribution
     * @return array<string, float>
     */
    private function scoreIntentsFromSignals(array $resultTypeCounts, array $pageTypeCounts, array $featureDistribution): array
    {
        $scores = [];

        $this->addScore($scores, KeywordSearchIntent::Transactional->value, ($pageTypeCounts[SerpPageType::Product->value] ?? 0) * 0.18);
        $this->addScore($scores, KeywordSearchIntent::Transactional->value, ($resultTypeCounts[SerpResultType::Shopping->value] ?? 0) * 0.22);

        $this->addScore($scores, KeywordSearchIntent::Commercial->value, ($pageTypeCounts[SerpPageType::Comparison->value] ?? 0) * 0.2);
        $this->addScore($scores, KeywordSearchIntent::Commercial->value, ($pageTypeCounts[SerpPageType::Review->value] ?? 0) * 0.16);
        $this->addScore($scores, KeywordSearchIntent::Commercial->value, ($pageTypeCounts[SerpPageType::Service->value] ?? 0) * 0.16);
        $this->addScore($scores, KeywordSearchIntent::Commercial->value, ($pageTypeCounts[SerpPageType::LandingPage->value] ?? 0) * 0.1);

        $this->addScore($scores, KeywordSearchIntent::Informational->value, ($pageTypeCounts[SerpPageType::Article->value] ?? 0) * 0.18);
        $this->addScore($scores, KeywordSearchIntent::Informational->value, ($pageTypeCounts[SerpPageType::Documentation->value] ?? 0) * 0.16);
        $this->addScore($scores, KeywordSearchIntent::Informational->value, ($resultTypeCounts[SerpResultType::FeaturedSnippet->value] ?? 0) * 0.2);
        $this->addScore($scores, KeywordSearchIntent::Informational->value, ($featureDistribution['people_also_ask'] ?? 0) * 0.15);

        $this->addScore($scores, KeywordSearchIntent::Local->value, ($pageTypeCounts[SerpPageType::LocalLanding->value] ?? 0) * 0.22);
        $this->addScore($scores, KeywordSearchIntent::Local->value, ($resultTypeCounts[SerpResultType::LocalPack->value] ?? 0) * 0.25);

        $this->addScore($scores, KeywordSearchIntent::Navigational->value, ($pageTypeCounts[SerpPageType::Homepage->value] ?? 0) * 0.2);
        $this->addScore($scores, KeywordSearchIntent::Navigational->value, ($resultTypeCounts[SerpResultType::Sitelink->value] ?? 0) * 0.18);

        if (($pageTypeCounts[SerpPageType::Forum->value] ?? 0) + ($pageTypeCounts[SerpPageType::Discussion->value] ?? 0) >= 2) {
            $this->addScore($scores, KeywordSearchIntent::Informational->value, 0.12);
        }

        return array_filter($scores, static fn (float $score): bool => $score > 0.05);
    }

    /** @param array<string, float> $scores */
    private function addScore(array &$scores, string $intent, float $delta): void
    {
        $scores[$intent] = ($scores[$intent] ?? 0.0) + $delta;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    private function topKeys(array $counts, int $limit): array
    {
        if ($counts === []) {
            return [];
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, $limit);
    }

    /**
     * @param  array<string, int>  $resultTypeCounts
     * @param  array<string, int>  $pageTypeCounts
     * @param  array<string, int>  $featureDistribution
     * @return list<string>
     */
    private function buildReasonCodes(array $resultTypeCounts, array $pageTypeCounts, array $featureDistribution): array
    {
        $codes = [];
        if (($resultTypeCounts[SerpResultType::LocalPack->value] ?? 0) > 0) {
            $codes[] = 'feature.local_pack_present';
        }
        if (($resultTypeCounts[SerpResultType::FeaturedSnippet->value] ?? 0) > 0) {
            $codes[] = 'feature.featured_snippet_present';
        }
        if (($pageTypeCounts[SerpPageType::Product->value] ?? 0) >= 2) {
            $codes[] = 'results.product_pages_dominant';
        }
        if (($pageTypeCounts[SerpPageType::Article->value] ?? 0) >= 3) {
            $codes[] = 'results.article_pages_dominant';
        }
        if (($featureDistribution['people_also_ask'] ?? 0) > 0) {
            $codes[] = 'feature.paa_present';
        }

        return $codes;
    }

    /**
     * @return array{
     *   observed_primary_intent: string,
     *   secondary_intents: list<string>,
     *   dominant_page_types: list<string>,
     *   feature_distribution: array<string, int>,
     *   confidence: float,
     *   reason_codes: list<string>,
     *   version: string
     * }
     */
    private function insufficient(string $code): array
    {
        return [
            'observed_primary_intent' => KeywordSearchIntent::Unknown->value,
            'secondary_intents' => [],
            'dominant_page_types' => [],
            'feature_distribution' => [],
            'confidence' => 0.2,
            'reason_codes' => ['insufficient_evidence', $code],
            'version' => self::INTENT_EVIDENCE_VERSION,
        ];
    }
}
