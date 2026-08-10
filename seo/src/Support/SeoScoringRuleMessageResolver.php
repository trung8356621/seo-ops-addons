<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

final class SeoScoringRuleMessageResolver
{
    /**
     * Chuẩn hóa legacy reason key (seo.heading, seo.heading.pass, …) → violation key mới.
     */
    public static function normalizeViolationKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        if (str_ends_with($key, '.pass')) {
            return null;
        }

        $mapped = match ($key) {
            'missing_focus_keyword', SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD => SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD,
            'seo.missing_focus_keyword' => SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD,
            'seo.heading', 'h2_missing' => SeoScoringRulesRegistry::KEY_H2_MISSING,
            'seo.length', 'content_length_low' => SeoScoringRulesRegistry::KEY_CONTENT_LENGTH_LOW,
            'seo.image_ratio', 'image_ratio_missing' => SeoScoringRulesRegistry::KEY_IMAGE_RATIO_MISSING,
            'seo.wiki_trust', 'wiki_trust_missing' => SeoScoringRulesRegistry::KEY_WIKI_TRUST_MISSING,
            'seo.faq_schema', 'faq_missing' => SeoScoringRulesRegistry::KEY_FAQ_MISSING,
            'seo.keyword_density' => SeoScoringRulesRegistry::KEY_KEYWORD_MISSING_IN_TITLE,
            default => self::isKnownViolationKey($key) ? $key : null,
        };

        return $mapped;
    }

    public static function messageForKey(string $key, ?string $locale = null): string
    {
        $normalized = self::normalizeViolationKey($key);
        if ($normalized !== null) {
            $messages = SeoScoringRulesRegistry::messagesForLocale($locale);
            $localeKey = 'seo_rules.'.$normalized;

            if (isset($messages[$localeKey])) {
                return $messages[$localeKey];
            }
        }

        if (str_starts_with($key, 'seo_rules.')) {
            $langKey = substr($key, 10);

            return (string) __("seo_rules.{$langKey}", [], $locale ?? app()->getLocale());
        }

        if (str_starts_with($key, 'seo.')) {
            $langKey = substr($key, 4);

            return (string) __("seo.{$langKey}", [], $locale ?? app()->getLocale());
        }

        return $key;
    }

    private static function isKnownViolationKey(string $key): bool
    {
        foreach (SeoScoringRulesRegistry::defaultRules() as $rule) {
            if ($rule['key'] === $key) {
                return true;
            }
        }

        return false;
    }
}
