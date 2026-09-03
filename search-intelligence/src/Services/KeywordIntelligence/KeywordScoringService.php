<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;

/**
 * Configurable priority scoring 0–100 with compact factors.
 */
final class KeywordScoringService
{
    /**
     * @param  array{
     *   relevance?: float|null,
     *   business_value?: float|null,
     *   search_volume?: int|null,
     *   keyword_difficulty?: float|null,
     *   competition?: float|null,
     *   has_existing_coverage?: bool,
     *   intent?: string|null,
     * }  $input
     * @return array{
     *   relevance_score: float,
     *   opportunity_score: float,
     *   priority_score: float,
     *   confidence: float,
     *   score_version: string,
     *   score_factors: list<array{label: string, delta: float}>,
     *   warnings: list<string>
     * }
     */
    public function score(array $input): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $this->scoringConfig();
        $weights = (array) ($cfg['weights'] ?? []);
        $penalties = (array) ($cfg['penalties'] ?? []);
        $version = (string) ($cfg['version'] ?? '1');

        $wRel = (float) ($weights['relevance'] ?? 0.30);
        $wBiz = (float) ($weights['business_value'] ?? 0.25);
        $wOpp = (float) ($weights['opportunity'] ?? 0.25);
        $wIntent = (float) ($weights['intent'] ?? 0.10);

        $relevance = $this->clamp((float) ($input['relevance'] ?? 50));
        $business = $this->clamp((float) ($input['business_value'] ?? 50));

        $volume = $input['search_volume'] ?? null;
        $difficulty = $input['keyword_difficulty'] ?? null;
        $competition = $input['competition'] ?? null;

        // Metric thiếu KHÔNG được coi là 0 — chỉ bỏ qua khỏi opportunity + cảnh báo minh bạch.
        $warnings = [];
        if (! is_numeric($volume)) {
            $warnings[] = 'keyword.missing_search_volume';
        }
        if (! is_numeric($difficulty)) {
            $warnings[] = 'keyword.missing_keyword_difficulty';
        }
        if (! is_numeric($competition)) {
            $warnings[] = 'keyword.missing_competition';
        }
        if (($input['relevance'] ?? null) === null) {
            $warnings[] = 'keyword.missing_relevance_input';
        }
        if (($input['business_value'] ?? null) === null) {
            $warnings[] = 'keyword.missing_business_value_input';
        }

        $metricsPresent = 0;
        $opportunity = 50.0;
        if (is_numeric($volume)) {
            $opportunity += min(30.0, log10(max(1, (float) $volume) + 1) * 10);
            $metricsPresent++;
        }
        if (is_numeric($difficulty)) {
            $opportunity += (100 - $this->clamp((float) $difficulty)) * 0.25;
            $metricsPresent++;
        }
        if (is_numeric($competition)) {
            $competitionScore = is_float($competition) || (float) $competition <= 1.0
                ? (float) $competition * 100
                : (float) $competition;
            $opportunity += (100 - $this->clamp($competitionScore)) * 0.15;
            $metricsPresent++;
        }
        $opportunity = $this->clamp($opportunity);

        $intentBonus = match ((string) ($input['intent'] ?? '')) {
            KeywordSearchIntent::Transactional->value, KeywordSearchIntent::Local->value => 12.0,
            KeywordSearchIntent::Commercial->value, KeywordSearchIntent::Mixed->value => 8.0,
            KeywordSearchIntent::Informational->value => 4.0,
            default => 0.0,
        };

        $factors = [
            ['label' => 'Relevance', 'delta' => round($relevance * $wRel, 2)],
            ['label' => 'Business value', 'delta' => round($business * $wBiz, 2)],
            ['label' => 'Opportunity', 'delta' => round($opportunity * $wOpp, 2)],
            ['label' => 'Intent weight', 'delta' => round($intentBonus * $wIntent * 10, 2)],
        ];

        $priority = ($relevance * $wRel)
            + ($business * $wBiz)
            + ($opportunity * $wOpp)
            + ($intentBonus * $wIntent);

        if (! empty($input['has_existing_coverage'])) {
            $coverPenalty = (float) ($penalties['existing_coverage'] ?? 10);
            $priority -= $coverPenalty;
            $factors[] = ['label' => 'Existing coverage penalty', 'delta' => round(-$coverPenalty, 2)];
        }

        $confidence = 0.4 + ($metricsPresent * 0.15);
        if (($input['relevance'] ?? null) !== null) {
            $confidence += 0.1;
        }
        if (($input['business_value'] ?? null) !== null) {
            $confidence += 0.1;
        }
        $confidence = min(0.95, $confidence);

        return [
            'relevance_score' => round($relevance, 2),
            'opportunity_score' => round($opportunity, 2),
            'priority_score' => round($this->clamp($priority), 2),
            'confidence' => round($confidence, 2),
            'score_version' => $version,
            'score_factors' => $factors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scoringConfig(): array
    {
        if (! function_exists('config')) {
            return [];
        }

        try {
            return (array) config('seo-content-ai.keyword_intelligence.scoring', []);
        } catch (\Throwable) {
            return [];
        }
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
