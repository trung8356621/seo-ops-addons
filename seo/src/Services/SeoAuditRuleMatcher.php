<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;

final class SeoAuditRuleMatcher
{
    /**
     * Scoring-rule checkboxes and aggregate score filters use OR semantics.
     * Scope filters (domain, language) are applied separately via query (AND).
     *
     * @param  array{
     *   score: int,
     *   technical_score: int,
     *   matched_rule_keys: list<string>
     * }  $assessment
     * @param  list<string>  $selectedRuleKeys
     */
    public function matchesSelectedFilters(
        array $assessment,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
    ): bool {
        $selectedRuleKeys = array_values(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $selectedRuleKeys,
        )));

        $hasScoringSelection = $selectedRuleKeys !== []
            || $filterLowSeoScore
            || $filterTechnicalSeoScore;

        if (! $hasScoringSelection) {
            return true;
        }

        foreach ($selectedRuleKeys as $ruleKey) {
            if (! SeoScoringRulesRegistry::isRuleEnabled($ruleKey)) {
                continue;
            }

            if (in_array($ruleKey, $assessment['matched_rule_keys'] ?? [], true)) {
                return true;
            }
        }

        $threshold = SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD;
        $score = (int) ($assessment['score'] ?? 0);
        $technicalScore = (int) ($assessment['technical_score'] ?? $score);

        if ($filterLowSeoScore && $score < $threshold) {
            return true;
        }

        if ($filterTechnicalSeoScore && $technicalScore < $threshold) {
            return true;
        }

        return false;
    }
}
