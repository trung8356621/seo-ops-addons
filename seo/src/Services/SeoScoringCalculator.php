<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Support\SeoScoringRuleMessageResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;

final class SeoScoringCalculator
{
    /**
     * @param  list<string>  $violations
     */
    public static function scoreFromViolations(array $violations): int
    {
        if (
            in_array(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $violations, true)
            && SeoScoringRulesRegistry::isRuleEnabled(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD)
        ) {
            return 0;
        }

        $deduction = 0;
        foreach (SeoScoringRulesRegistry::sanitizeViolations($violations) as $key) {
            if (! SeoScoringRulesRegistry::isRuleEnabled($key)) {
                continue;
            }

            $deduction += SeoScoringRulesRegistry::deductionFor($key);
        }

        return max(0, SeoScoringRulesRegistry::BASE_SCORE - $deduction);
    }

    /**
     * @param  list<string>  $violations
     * @return list<array{key: string, deduction: int, message: string}>
     */
    public static function violationLines(array $violations, ?string $locale = null): array
    {
        $messages = SeoScoringRulesRegistry::messagesForLocale($locale);
        $lines = [];

        foreach (SeoScoringRulesRegistry::sanitizeViolations($violations) as $key) {
            if (! SeoScoringRulesRegistry::isRuleEnabled($key)) {
                continue;
            }

            $deduction = SeoScoringRulesRegistry::deductionFor($key);
            if ($deduction <= 0) {
                continue;
            }

            $rule = self::findRule($key);
            if ($rule === null) {
                continue;
            }

            $lines[] = [
                'key' => $key,
                'deduction' => $deduction,
                'message' => $messages[$rule['locale_key']]
                    ?? SeoScoringRuleMessageResolver::messageForKey($key, $locale),
            ];
        }

        return $lines;
    }

    /**
     * @return array{key: string, deduction: int, locale_key: string}|null
     */
    private static function findRule(string $key): ?array
    {
        foreach (SeoScoringRulesRegistry::rules() as $rule) {
            if ($rule['key'] === $key) {
                return $rule;
            }
        }

        return null;
    }
}
