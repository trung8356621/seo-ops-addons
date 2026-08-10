<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\Seo\Services\SeoScoringSettingsService;

final class SeoScoringRulesRegistry
{
    public const BASE_SCORE = 100;

    public const AUDIT_LOW_SCORE_THRESHOLD = 60;

    public const AGGREGATE_FILTER_LOW_SEO_SCORE = 'aggregate_low_seo_score';

    public const AGGREGATE_FILTER_TECHNICAL_SEO_SCORE = 'aggregate_technical_seo_score';

    public const META_KEY_VIOLATIONS = 'seo_rule_violations';

    /** Canonical scoring contract version — bump when rule weights / normalization change. */
    public const SCORE_VERSION = 'seo-scoring.v1';

    public const META_KEY_ANALYZED_CONTENT_HASH = 'seo_analyzed_content_hash';

    public const META_KEY_SCORE_VERSION = 'seo_score_version';

    public const META_KEY_SCORE_CALCULATED_AT = 'seo_score_calculated_at';

    private const H2_MIN_COUNT = 2;

    public const KEY_MISSING_FOCUS_KEYWORD = 'missing_focus_keyword';

    public const KEY_H2_MISSING = 'h2_missing';

    public const KEY_CONTENT_LENGTH_LOW = 'content_length_low';

    public const KEY_IMAGE_RATIO_MISSING = 'image_ratio_missing';

    public const KEY_IMAGE_RATIO_POOR = 'image_ratio_poor';

    public const KEY_IMAGE_RATIO_LOW = 'image_ratio_low';

    public const KEY_IMAGE_RATIO_SUBOPTIMAL = 'image_ratio_suboptimal';

    public const KEY_IMAGE_ALT_MISSING = 'image_alt_missing';

    public const KEY_WIKI_TRUST_MISSING = 'wiki_trust_missing';

    public const KEY_FAQ_MISSING = 'faq_missing';

    public const KEY_KEYWORD_MISSING_IN_TITLE = 'keyword_missing_in_title';

    public const KEY_KEYWORD_MISSING_IN_META = 'keyword_missing_in_meta';

    public const KEY_KEYWORD_MISSING_IN_SLUG = 'keyword_missing_in_slug';

    public const KEY_KEYWORD_MISSING_IN_INTRO = 'keyword_missing_in_intro';

    public const KEY_FEATURED_SNIPPET_MISSING = 'featured_snippet_missing';

    public const KEY_FEATURED_SNIPPET_BELOW_GOOD = 'featured_snippet_below_good';

    public const KEY_FEATURED_SNIPPET_BELOW_EXCELLENT = 'featured_snippet_below_excellent';

    /**
     * @return list<array{key: string, deduction: int, locale_key: string}>
     */
    public static function defaultRules(): array
    {
        return [
            ['key' => self::KEY_MISSING_FOCUS_KEYWORD, 'deduction' => 100, 'locale_key' => 'seo_rules.missing_focus_keyword'],
            ['key' => self::KEY_H2_MISSING, 'deduction' => 20, 'locale_key' => 'seo_rules.h2_missing'],
            ['key' => self::KEY_CONTENT_LENGTH_LOW, 'deduction' => 15, 'locale_key' => 'seo_rules.content_length_low'],
            ['key' => self::KEY_IMAGE_RATIO_MISSING, 'deduction' => 12, 'locale_key' => 'seo_rules.image_ratio_missing'],
            ['key' => self::KEY_IMAGE_RATIO_POOR, 'deduction' => 7, 'locale_key' => 'seo_rules.image_ratio_poor'],
            ['key' => self::KEY_IMAGE_RATIO_LOW, 'deduction' => 4, 'locale_key' => 'seo_rules.image_ratio_low'],
            ['key' => self::KEY_IMAGE_RATIO_SUBOPTIMAL, 'deduction' => 2, 'locale_key' => 'seo_rules.image_ratio_suboptimal'],
            ['key' => self::KEY_IMAGE_ALT_MISSING, 'deduction' => 5, 'locale_key' => 'seo_rules.image_alt_missing'],
            ['key' => self::KEY_WIKI_TRUST_MISSING, 'deduction' => 15, 'locale_key' => 'seo_rules.wiki_trust_missing'],
            ['key' => self::KEY_FAQ_MISSING, 'deduction' => 10, 'locale_key' => 'seo_rules.faq_missing'],
            ['key' => self::KEY_KEYWORD_MISSING_IN_TITLE, 'deduction' => 4, 'locale_key' => 'seo_rules.keyword_missing_in_title'],
            ['key' => self::KEY_KEYWORD_MISSING_IN_META, 'deduction' => 4, 'locale_key' => 'seo_rules.keyword_missing_in_meta'],
            ['key' => self::KEY_KEYWORD_MISSING_IN_SLUG, 'deduction' => 4, 'locale_key' => 'seo_rules.keyword_missing_in_slug'],
            ['key' => self::KEY_KEYWORD_MISSING_IN_INTRO, 'deduction' => 3, 'locale_key' => 'seo_rules.keyword_missing_in_intro'],
            ['key' => self::KEY_FEATURED_SNIPPET_MISSING, 'deduction' => 10, 'locale_key' => 'seo_rules.featured_snippet_missing'],
            ['key' => self::KEY_FEATURED_SNIPPET_BELOW_GOOD, 'deduction' => 7, 'locale_key' => 'seo_rules.featured_snippet_below_good'],
            ['key' => self::KEY_FEATURED_SNIPPET_BELOW_EXCELLENT, 'deduction' => 4, 'locale_key' => 'seo_rules.featured_snippet_below_excellent'],
        ];
    }

    /**
     * @return list<array{key: string, deduction: int, enabled: bool, locale_key: string}>
     */
    public static function rules(): array
    {
        return app(SeoScoringSettingsService::class)->effectiveRules();
    }

    /**
     * @return list<array{key: string, deduction: int, enabled: bool, locale_key: string}>
     */
    public static function publicRulesForClient(): array
    {
        return self::rules();
    }

    /**
     * @return array<string, string>
     */
    public static function messagesForLocale(?string $locale = null): array
    {
        $previous = app()->getLocale();
        if ($locale !== null && $locale !== '') {
            app()->setLocale($locale);
        }

        $lines = [];
        foreach (self::defaultRules() as $rule) {
            $langKey = str_starts_with($rule['locale_key'], 'seo_rules.')
                ? substr($rule['locale_key'], 10)
                : $rule['locale_key'];
            $lines[$rule['locale_key']] = (string) __("seo_rules.{$langKey}");

            foreach (["{$langKey}_label", "{$langKey}_detail"] as $extraKey) {
                $translated = __("seo_rules.{$extraKey}");
                if (is_string($translated) && $translated !== "seo_rules.{$extraKey}") {
                    $lines['seo_rules.'.$extraKey] = $translated;
                }
            }
        }

        $allSeoRules = trans('seo_rules');
        if (is_array($allSeoRules)) {
            foreach ($allSeoRules as $langKey => $value) {
                if (is_string($value) && $value !== '') {
                    $lines['seo_rules.'.$langKey] = $value;
                }
            }
        }

        foreach (array_keys((array) trans('seo')) as $legacyKey) {
            $lines['seo.'.$legacyKey] = (string) __("seo.{$legacyKey}");
        }

        if ($locale !== null && $locale !== '') {
            app()->setLocale($previous);
        }

        return $lines;
    }

    public static function defaultDeductionFor(string $key): int
    {
        foreach (self::defaultRules() as $rule) {
            if ($rule['key'] === $key) {
                return (int) $rule['deduction'];
            }
        }

        return 0;
    }

    public static function deductionFor(string $key): int
    {
        return app(SeoScoringSettingsService::class)->deductionFor($key);
    }

    public static function isRuleEnabled(string $key): bool
    {
        return app(SeoScoringSettingsService::class)->isRuleEnabled($key);
    }

    public static function isRuleFilterable(string $key): bool
    {
        $normalized = trim($key);
        if ($normalized === '') {
            return false;
        }

        $meta = self::ruleCatalog()[$normalized] ?? null;
        if (! is_array($meta)) {
            return false;
        }

        return (bool) ($meta['filterable'] ?? false);
    }

    public static function isKnownKey(string $key): bool
    {
        $normalized = SeoScoringRuleMessageResolver::normalizeViolationKey($key);

        return $normalized !== null && (
            self::defaultDeductionFor($normalized) > 0
            || $normalized === self::KEY_MISSING_FOCUS_KEYWORD
        );
    }

    /**
     * @param  list<string>  $violations
     * @return list<string>
     */
    public static function sanitizeViolations(array $violations): array
    {
        $result = [];
        foreach ($violations as $key) {
            if (! is_string($key)) {
                continue;
            }

            $normalized = SeoScoringRuleMessageResolver::normalizeViolationKey($key);
            if ($normalized === null) {
                continue;
            }

            $result[] = $normalized;
        }

        return array_values(array_unique($result));
    }

    /**
     * @return list<string>
     */
    public static function knownKeys(): array
    {
        return array_map(
            static fn (array $rule): string => $rule['key'],
            self::defaultRules(),
        );
    }

    /**
     * @return list<string>
     */
    public static function violationKeysForRule(string $ruleKey): array
    {
        $catalog = self::ruleCatalog()[$ruleKey]['violation_keys'] ?? [$ruleKey];

        return array_values(array_unique([$ruleKey, ...$catalog]));
    }

    public static function canonicalRuleKeyForViolation(string $violationKey): ?string
    {
        $normalized = SeoScoringRuleMessageResolver::normalizeViolationKey($violationKey);
        if ($normalized === null) {
            return null;
        }

        foreach (self::ruleCatalog() as $ruleKey => $meta) {
            if (in_array($violationKey, $meta['violation_keys'] ?? [], true)
                || in_array($normalized, $meta['violation_keys'] ?? [], true)
                || $ruleKey === $normalized) {
                return $ruleKey;
            }
        }

        return self::isKnownKey($normalized) ? $normalized : null;
    }

    /**
     * @param  list<string>  $violations
     * @return list<string>
     */
    public static function activeViolations(array $violations): array
    {
        return array_values(array_filter(
            self::sanitizeViolations($violations),
            static fn (string $key): bool => self::isRuleEnabled($key),
        ));
    }

    /**
     * @return list<array{
     *   key: string,
     *   label: string,
     *   short_label: string,
     *   description: string,
     *   category: string,
     *   enabled: bool,
     *   deduction: int,
     *   filterable: bool,
     *   violation_keys: list<string>,
     *   threshold: array<string, mixed>|null
     * }>
     */
    public static function effectiveRuleDefinitions(?int $articleLengthTarget = null): array
    {
        $lengthTarget = max(1, $articleLengthTarget ?? app(SeoPromptSettingsService::class)->resolveArticleLengthTarget('article'));
        $definitions = [];

        foreach (self::rules() as $rule) {
            $definitions[] = self::enrichRuleDefinition($rule, $lengthTarget);
        }

        return $definitions;
    }

    /**
     * @return list<array{
     *   key: string,
     *   label: string,
     *   category: string,
     *   deduction: int,
     *   threshold: array<string, mixed>|null
     * }>
     */
    public static function auditFilterDefinitions(?int $articleLengthTarget = null): array
    {
        $filters = [];

        foreach (self::effectiveRuleDefinitions($articleLengthTarget) as $rule) {
            if (! ($rule['enabled'] ?? false) || ! ($rule['filterable'] ?? false)) {
                continue;
            }

            $filters[] = [
                'key' => $rule['key'],
                'label' => $rule['label'],
                'category' => $rule['category'],
                'deduction' => $rule['deduction'],
                'threshold' => $rule['threshold'],
            ];
        }

        return $filters;
    }

    /**
     * @return list<array{key: string, label: string, threshold: int}>
     */
    public static function aggregateFilterDefinitions(): array
    {
        $threshold = self::AUDIT_LOW_SCORE_THRESHOLD;

        return [
            [
                'key' => self::AGGREGATE_FILTER_LOW_SEO_SCORE,
                'label' => (string) __('seo-content-ai::filament.articles_optimal.filter_low_score_dynamic', [
                    'threshold' => $threshold,
                ]),
                'threshold' => $threshold,
            ],
        ];
    }

    /**
     * @param  array{key: string, deduction: int, enabled: bool, locale_key: string}  $rule
     * @return array{
     *   key: string,
     *   label: string,
     *   short_label: string,
     *   description: string,
     *   category: string,
     *   enabled: bool,
     *   deduction: int,
     *   filterable: bool,
     *   violation_keys: list<string>,
     *   threshold: array<string, mixed>|null
     * }
     */
    public static function enrichRuleDefinition(array $rule, int $articleLengthTarget): array
    {
        $key = (string) $rule['key'];
        $meta = self::ruleCatalog()[$key] ?? [];
        $description = SeoScoringRuleMessageResolver::messageForKey($key);
        $shortLabel = (string) ($meta['short_label'] ?? $description);
        $threshold = self::resolveThresholdForRule($key, $meta, $articleLengthTarget);

        return [
            'key' => $key,
            'label' => self::formatFilterLabel($shortLabel, $threshold),
            'short_label' => $shortLabel,
            'description' => $description,
            'category' => (string) ($meta['category'] ?? 'general'),
            'enabled' => (bool) ($rule['enabled'] ?? true),
            'deduction' => (int) ($rule['deduction'] ?? 0),
            'filterable' => (bool) ($meta['filterable'] ?? false),
            'violation_keys' => self::violationKeysForRule($key),
            'threshold' => $threshold,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $threshold
     */
    public static function formatFilterLabel(string $shortLabel, ?array $threshold): string
    {
        if ($threshold === null) {
            return $shortLabel;
        }

        $value = $threshold['value'] ?? null;
        if (! is_numeric($value)) {
            return $shortLabel;
        }

        $unit = (string) ($threshold['unit'] ?? '');
        $type = (string) ($threshold['type'] ?? '');

        if ($type === 'min' && $unit === 'words') {
            return (string) __('seo-content-ai::filament.articles_optimal.filter_threshold_min_words', [
                'label' => $shortLabel,
                'value' => (int) $value,
            ]);
        }

        if ($type === 'min') {
            return (string) __('seo-content-ai::filament.articles_optimal.filter_threshold_min', [
                'label' => $shortLabel,
                'value' => (int) $value,
            ]);
        }

        return $shortLabel;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private static function resolveThresholdForRule(string $key, array $meta, int $articleLengthTarget): ?array
    {
        $threshold = $meta['threshold'] ?? null;
        if (! is_array($threshold)) {
            return null;
        }

        $resolved = $threshold;
        if (($threshold['resolver'] ?? null) === 'article_length_target') {
            $resolved['value'] = $articleLengthTarget;
        }

        if ($key === self::KEY_H2_MISSING) {
            $resolved['value'] = self::H2_MIN_COUNT;
        }

        return $resolved;
    }

    /**
     * @return array<string, array{
     *   category: string,
     *   filterable: bool,
     *   short_label?: string,
     *   violation_keys?: list<string>,
     *   threshold?: array<string, mixed>
     * }>
     */
    private static function ruleCatalog(): array
    {
        return [
            self::KEY_MISSING_FOCUS_KEYWORD => [
                'category' => 'keyword',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.missing_focus_keyword'),
                'violation_keys' => ['seo.missing_focus_keyword', 'missing_focus_keyword'],
            ],
            self::KEY_H2_MISSING => [
                'category' => 'structure',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.h2_missing'),
                'violation_keys' => ['seo.heading', 'h2_missing'],
                'threshold' => ['type' => 'min', 'field' => 'h2_count', 'value' => self::H2_MIN_COUNT],
            ],
            self::KEY_CONTENT_LENGTH_LOW => [
                'category' => 'content',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.content_length_low'),
                'violation_keys' => ['seo.length', 'content_length_low', 'seo_rules.content_length_low'],
                'threshold' => [
                    'type' => 'min',
                    'field' => 'word_count',
                    'resolver' => 'article_length_target',
                    'unit' => 'words',
                ],
            ],
            self::KEY_IMAGE_RATIO_MISSING => [
                'category' => 'media',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.image_ratio_missing'),
                'violation_keys' => ['seo.image_ratio', 'image_ratio_missing'],
            ],
            self::KEY_IMAGE_RATIO_POOR => [
                'category' => 'media',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.image_ratio_poor'),
                'violation_keys' => ['image_ratio_poor'],
            ],
            self::KEY_IMAGE_RATIO_LOW => [
                'category' => 'media',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.image_ratio_low'),
                'violation_keys' => ['image_ratio_low'],
            ],
            self::KEY_IMAGE_RATIO_SUBOPTIMAL => [
                'category' => 'media',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.image_ratio_suboptimal'),
                'violation_keys' => ['image_ratio_suboptimal'],
            ],
            self::KEY_IMAGE_ALT_MISSING => [
                'category' => 'media',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.image_alt_missing'),
                'violation_keys' => ['image_alt_missing'],
            ],
            self::KEY_WIKI_TRUST_MISSING => [
                'category' => 'trust',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.wiki_trust_missing'),
                'violation_keys' => ['seo.wiki_trust', 'wiki_trust_missing'],
            ],
            self::KEY_FAQ_MISSING => [
                'category' => 'structure',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.faq_missing'),
                'violation_keys' => ['seo.faq_schema', 'faq_missing'],
            ],
            self::KEY_KEYWORD_MISSING_IN_TITLE => [
                'category' => 'keyword',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.keyword_missing_in_title'),
                'violation_keys' => ['seo.keyword_density', 'keyword_missing_in_title'],
            ],
            self::KEY_KEYWORD_MISSING_IN_META => [
                'category' => 'keyword',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.keyword_missing_in_meta'),
                'violation_keys' => ['keyword_missing_in_meta'],
            ],
            self::KEY_KEYWORD_MISSING_IN_SLUG => [
                'category' => 'keyword',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.keyword_missing_in_slug'),
                'violation_keys' => ['keyword_missing_in_slug'],
            ],
            self::KEY_KEYWORD_MISSING_IN_INTRO => [
                'category' => 'keyword',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.keyword_missing_in_intro'),
                'violation_keys' => ['keyword_missing_in_intro'],
            ],
            self::KEY_FEATURED_SNIPPET_MISSING => [
                'category' => 'structure',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.featured_snippet_missing'),
                'violation_keys' => ['featured_snippet_missing'],
            ],
            self::KEY_FEATURED_SNIPPET_BELOW_GOOD => [
                'category' => 'structure',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.featured_snippet_below_good'),
                'violation_keys' => ['featured_snippet_below_good'],
            ],
            self::KEY_FEATURED_SNIPPET_BELOW_EXCELLENT => [
                'category' => 'structure',
                'filterable' => true,
                'short_label' => __('seo-content-ai::filament.articles_optimal.rule_short.featured_snippet_below_excellent'),
                'violation_keys' => ['featured_snippet_below_excellent'],
            ],
        ];
    }
}
