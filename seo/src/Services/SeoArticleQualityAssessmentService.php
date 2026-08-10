<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Support\SeoScoringRuleMessageResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;

final class SeoArticleQualityAssessmentService
{
    /**
     * @param  array<string, mixed>  $analysis
     * @return array{
     *   score: int,
     *   technical_score: int,
     *   matched_rule_keys: list<string>,
     *   active_violations: list<array{key: string, label: string}>,
     *   is_low_quality: bool
     * }
     */
    public function assessFromAnalysis(array $analysis): array
    {
        $rawViolations = is_array($analysis['violations'] ?? null)
            ? $analysis['violations']
            : (is_array($analysis['reason_keys'] ?? null) ? $analysis['reason_keys'] : []);

        $matchedRuleKeys = SeoScoringRulesRegistry::activeViolations($rawViolations);
        $score = (int) ($analysis['seo_score'] ?? $analysis['score'] ?? SeoScoringCalculator::scoreFromViolations($rawViolations));

        $activeViolations = [];
        foreach ($matchedRuleKeys as $key) {
            $activeViolations[] = [
                'key' => $key,
                'label' => SeoScoringRuleMessageResolver::messageForKey($key),
            ];
        }

        return [
            'score' => $score,
            'technical_score' => $score,
            'matched_rule_keys' => $matchedRuleKeys,
            'active_violations' => $activeViolations,
            'is_low_quality' => $score < SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD,
        ];
    }
}
