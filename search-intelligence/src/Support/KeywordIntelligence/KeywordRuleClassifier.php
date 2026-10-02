<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Omnichannel\Addons\SearchFoundation\Contracts\GlobalMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Services\MatchRules\IndustryMatchRuntime;

final class KeywordRuleClassifier
{
    private const INDUSTRY_GROUPS = ['products', 'product_families', 'materials', 'services', 'audiences', 'use_cases', 'features', 'adjacent_products'];

    public function __construct(
        private readonly ?GlobalMatchRuleProvider $globalRules = null,
        private readonly ?IndustryMatchRuntime $industryRules = null,
    ) {}
    public const KIND_KEYWORD_PHRASE = 'keyword_phrase';

    public const KIND_QUERY = 'query';

    public const KIND_SENTENCE = 'sentence';

    public const KIND_DESCRIPTIVE_PHRASE = 'descriptive_phrase';

    public const KIND_BRAND_ENTITY = 'brand_entity';

    public const KIND_URL_DOMAIN = 'url_domain';

    public const KIND_NOISE = 'noise';

    public const INTENT_INFORMATIONAL = 'informational';

    public const INTENT_COMMERCIAL = 'commercial';

    public const INTENT_TRANSACTIONAL = 'transactional';

    public const INTENT_NAVIGATIONAL = 'navigational';

    public const INTENT_UNKNOWN = 'unknown';

    /**
     * @return list<string>
     */
    public static function intents(): array
    {
        return [
            self::INTENT_INFORMATIONAL,
            self::INTENT_COMMERCIAL,
            self::INTENT_TRANSACTIONAL,
            self::INTENT_NAVIGATIONAL,
            self::INTENT_UNKNOWN,
        ];
    }

    public static function intentLabel(string $intent): string
    {
        return match ($intent) {
            self::INTENT_INFORMATIONAL => __('seo-content-ai::filament.keyword.intent_informational'),
            self::INTENT_COMMERCIAL => __('seo-content-ai::filament.keyword.intent_commercial'),
            self::INTENT_TRANSACTIONAL => __('seo-content-ai::filament.keyword.intent_transactional'),
            self::INTENT_NAVIGATIONAL => __('seo-content-ai::filament.keyword.intent_navigational'),
            self::INTENT_UNKNOWN => __('seo-content-ai::filament.keyword.intent_unknown'),
            default => $intent,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function intentFilterOptions(): array
    {
        $options = [];
        foreach (self::intents() as $intent) {
            $options[$intent] = self::intentLabel($intent);
        }

        return $options;
    }

    /**
     * @param  array{
     *     source_kind?: string,
     *     occurrence_count?: int,
     *     source_post_count?: int,
     *     target_post_count?: int,
     *     has_canonical_match?: bool
     * }  $context
     * @return array{
     *     phrase_kind: string,
     *     seo_intent: string,
     *     is_seo_keyword: bool,
     *     is_anchor_candidate: bool,
     *     anchor_priority: int,
     *     classification_confidence: float,
     *     keyword_score: float,
     *     is_ambiguous: bool,
     *     review_band: string,
     *     segments: list<array{text: string, hint: string, phrase_kind: string}>
     * }
     */
    public function classify(string $raw, string $normalized, array $context = []): array
    {
        $text = $normalized !== '' ? $normalized : mb_strtolower(trim($raw));
        $source = (string) ($context['source_kind'] ?? KeywordSourceNormalizer::OTHER);
        $occurrence = max(1, (int) ($context['occurrence_count'] ?? 1));
        $sourcePosts = max(0, (int) ($context['source_post_count'] ?? 0));
        $targetPosts = max(0, (int) ($context['target_post_count'] ?? 0));
        $hasCanonical = (bool) ($context['has_canonical_match'] ?? false);

        $globalRules = $this->globalRules($context);
        $features = $this->features($raw, $text, $context, $globalRules);
        $cta = $this->ctaAssessment($text, $raw, $features, $globalRules);
        $kind = $this->kind($raw, $text, $features, $globalRules);
        if ($cta['is_cta_like']) {
            $kind = (int) $features['word_count'] >= 6 ? self::KIND_SENTENCE : self::KIND_DESCRIPTIVE_PHRASE;
        }
        $intent = $this->intent($text, $kind, $features, $globalRules);
        $confidence = $this->confidence($kind, $features, $source, $occurrence, $hasCanonical);
        $keywordScore = $this->keywordScore($kind, $features, $source, $occurrence, $sourcePosts, $targetPosts, $hasCanonical);
        if ($cta['is_cta_like']) {
            $keywordScore = min($keywordScore, 0.12);
        }
        $isSeo = $this->isSeoKeyword($kind, $keywordScore, $source, $hasCanonical);
        if ($cta['is_cta_like']) {
            $isSeo = false;
        }
        $isAnchor = $this->isAnchorCandidate($kind, $features, $isSeo);
        $band = $confidence >= 0.90 ? 'auto' : ($confidence >= 0.65 ? 'review' : 'ambiguous');
        $skipSegments = (bool) ($context['skip_segments'] ?? false);

        return [
            'phrase_kind' => $kind,
            'seo_intent' => $intent,
            'is_seo_keyword' => $isSeo,
            'is_anchor_candidate' => $isAnchor,
            'anchor_priority' => $isAnchor ? $this->anchorPriority($features, $occurrence, $sourcePosts) : 0,
            'classification_confidence' => round($confidence, 2),
            'keyword_score' => round($keywordScore, 2),
            'is_ambiguous' => $band === 'ambiguous',
            'review_band' => $band,
            'segments' => $skipSegments ? [] : $this->segments($raw),
        ];
    }

    /**
     * @return array{
     *     word_count: int,
     *     has_url: bool,
     *     has_question: bool,
     *     has_terminator: bool,
     *     has_dash: bool,
     *     sentence_hint_hits: int,
     *     marketing_hits: int,
     *     product_hits: int,
     *     location_hits: int,
     *     proper_ratio: float,
     *     strong_sentence: bool
     * }
     */
    private function features(string $raw, string $text, array $context, array $globalRules): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));
        $wordCount = count($words);

        $hintHits = $this->hitCount($text, $globalRules['sentence_hints'] ?? []);
        $marketingHits = $this->hitCount($text, $globalRules['marketing_terms'] ?? []);
        $locationHits = $this->hitCount($text, $globalRules['location_terms'] ?? []);
        $explicitIndustryTerms = array_values(array_filter((array) ($context['industry_terms'] ?? []), 'is_string'));
        $siteId = (int) ($context['site_id'] ?? 0);
        $productHits = $explicitIndustryTerms !== []
            ? $this->hitCount($text, $explicitIndustryTerms)
            : count($this->industryRuntime()?->matchingEntries($siteId, self::INDUSTRY_GROUPS, $raw) ?? []);

        $rawTokens = preg_split('/\s+/u', trim($raw)) ?: [];
        $alpha = 0;
        $proper = 0;
        foreach ($rawTokens as $tok) {
            if (preg_match('/^\p{L}/u', $tok) !== 1) {
                continue;
            }
            $alpha++;
            if (preg_match('/^\p{Lu}/u', $tok) === 1) {
                $proper++;
            }
        }
        $properRatio = $alpha > 0 ? $proper / $alpha : 0.0;

        $strongSentence = $hintHits >= 2;

        return [
            'word_count' => $wordCount,
            'has_url' => preg_match('#https?://#i', $raw) === 1 || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $text) === 1,
            'has_question' => str_contains($text, '?') || $this->hitCount($text, $globalRules['question_terms'] ?? []) > 0,
            'has_terminator' => preg_match('/[.!?].+\s/u', $raw) === 1 || str_ends_with(trim($raw), '.'),
            'has_dash' => preg_match('/[–—]| \- /u', $raw) === 1,
            'sentence_hint_hits' => $hintHits,
            'marketing_hits' => $marketingHits,
            'product_hits' => $productHits,
            'location_hits' => $locationHits,
            'proper_ratio' => $properRatio,
            'strong_sentence' => $strongSentence,
        ];
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function kind(string $raw, string $text, array $features, array $globalRules): string
    {
        if ($text === '' || preg_match('/^[\p{P}\p{S}\s]+$/u', $text) === 1) {
            return self::KIND_NOISE;
        }
        if ($features['has_url']) {
            return self::KIND_URL_DOMAIN;
        }
        if ($features['has_question']) {
            return self::KIND_QUERY;
        }

        $wc = (int) $features['word_count'];
        if ($features['strong_sentence']
            || ($features['has_terminator'] && $wc >= 8 && (int) $features['sentence_hint_hits'] >= 1)
            || ((int) $features['sentence_hint_hits'] >= 3 && $wc >= 8)
        ) {
            return self::KIND_SENTENCE;
        }

        if ($features['has_dash'] && ((int) $features['marketing_hits'] >= 1 || $wc >= 8)) {
            return self::KIND_DESCRIPTIVE_PHRASE;
        }
        if ((int) $features['marketing_hits'] >= 2 && $wc >= 6) {
            return self::KIND_DESCRIPTIVE_PHRASE;
        }
        if ((int) $features['marketing_hits'] >= 1 && $wc >= 10 && (int) $features['sentence_hint_hits'] >= 1) {
            return self::KIND_DESCRIPTIVE_PHRASE;
        }

        if ($this->looksLikeBrand($raw, $features, $globalRules)) {
            return self::KIND_BRAND_ENTITY;
        }

        if ((int) $features['product_hits'] >= 1 || $wc <= 12) {
            return self::KIND_KEYWORD_PHRASE;
        }

        if ($wc > 12 && (int) $features['sentence_hint_hits'] >= 1) {
            return self::KIND_SENTENCE;
        }

        return self::KIND_KEYWORD_PHRASE;
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function looksLikeBrand(string $raw, array $features, array $globalRules): bool
    {
        $wc = (int) $features['word_count'];
        if ($wc < 1 || $wc > 5) {
            return false;
        }
        if ((int) $features['marketing_hits'] > 0 || $features['has_dash'] || $features['has_question']) {
            return false;
        }
        $folded = mb_strtolower($raw);
        $genericOnly = $this->hitCount($folded, $globalRules['generic_purchase_terms'] ?? []) > 0;
        if ($genericOnly && $wc >= 3 && (int) $features['product_hits'] >= 2) {
            return false;
        }
        if ($wc === 1 && preg_match('/^[A-ZÀ-Ỵ][\p{L}\p{N}&+\-]{1,40}$/u', trim($raw)) === 1) {
            return true;
        }
        if ((float) $features['proper_ratio'] >= 0.66 && $wc <= 4 && (int) $features['product_hits'] <= 1) {
            return true;
        }

        return $wc <= 3 && (float) $features['proper_ratio'] >= 0.5 && (int) $features['product_hits'] <= 1;
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function intent(string $text, string $kind, array $features, array $globalRules): string
    {
        if (in_array($kind, [self::KIND_NOISE, self::KIND_URL_DOMAIN, self::KIND_SENTENCE, self::KIND_DESCRIPTIVE_PHRASE], true)) {
            return self::INTENT_UNKNOWN;
        }
        if ($this->hitCount($text, $globalRules['transactional_terms'] ?? []) > 0 && $kind !== self::KIND_BRAND_ENTITY) {
            return self::INTENT_TRANSACTIONAL;
        }
        if ($kind === self::KIND_QUERY || $this->hitCount($text, $globalRules['informational_terms'] ?? []) > 0) {
            return self::INTENT_INFORMATIONAL;
        }
        if ($kind === self::KIND_BRAND_ENTITY) {
            return self::INTENT_NAVIGATIONAL;
        }
        if ($kind === self::KIND_KEYWORD_PHRASE && (int) $features['product_hits'] >= 1) {
            return self::INTENT_COMMERCIAL;
        }
        if ($kind === self::KIND_KEYWORD_PHRASE) {
            return self::INTENT_COMMERCIAL;
        }

        return self::INTENT_UNKNOWN;
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function confidence(string $kind, array $features, string $source, int $occurrence, bool $hasCanonical): float
    {
        $base = match ($kind) {
            self::KIND_URL_DOMAIN, self::KIND_NOISE => 0.96,
            self::KIND_SENTENCE => $features['strong_sentence'] ? 0.93 : 0.82,
            self::KIND_QUERY => 0.88,
            self::KIND_DESCRIPTIVE_PHRASE => $features['has_dash'] ? 0.91 : 0.78,
            self::KIND_BRAND_ENTITY => 0.74,
            default => 0.80,
        };
        if ($kind === self::KIND_KEYWORD_PHRASE && (int) $features['marketing_hits'] >= 1) {
            $base -= 0.12;
        }
        if ($kind === self::KIND_KEYWORD_PHRASE && (int) $features['word_count'] >= 10) {
            $base -= 0.08;
        }
        if ($hasCanonical) {
            $base += 0.05;
        }
        if ($source === KeywordSourceNormalizer::ANCHOR_TEXT && $kind === self::KIND_KEYWORD_PHRASE && $occurrence < 3) {
            $base -= 0.06;
        }

        return max(0.40, min(0.99, $base));
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function keywordScore(
        string $kind,
        array $features,
        string $source,
        int $occurrence,
        int $sourcePosts,
        int $targetPosts,
        bool $hasCanonical,
    ): float {
        if (in_array($kind, [self::KIND_NOISE, self::KIND_URL_DOMAIN, self::KIND_SENTENCE], true)) {
            return 0.05;
        }
        if ($kind === self::KIND_DESCRIPTIVE_PHRASE) {
            return 0.15;
        }
        $score = $kind === self::KIND_QUERY ? 0.72 : 0.70;
        $score += min(0.15, ((int) $features['product_hits']) * 0.04);
        $score += min(0.08, $features['location_hits'] * 0.04);
        $score += min(0.12, log(1 + $occurrence) * 0.05);
        $score += min(0.08, log(1 + $sourcePosts) * 0.04);
        $score += min(0.06, log(1 + $targetPosts) * 0.04);
        if ($hasCanonical) {
            $score += 0.10;
        }
        if ($source === KeywordSourceNormalizer::ANCHOR_TEXT) {
            $score -= 0.12;
            if ($occurrence < 3 && ! $hasCanonical) {
                $score -= 0.10;
            }
        }
        if ($source === KeywordSourceNormalizer::MANUAL) {
            $score += 0.12;
        }
        if ((int) $features['marketing_hits'] > 0) {
            $score -= 0.18;
        }
        if ((int) $features['word_count'] > 10) {
            $score -= 0.08;
        }

        return max(0.0, min(1.0, $score));
    }

    private function isSeoKeyword(string $kind, float $keywordScore, string $source, bool $hasCanonical): bool
    {
        if (in_array($kind, [self::KIND_SENTENCE, self::KIND_URL_DOMAIN, self::KIND_NOISE, self::KIND_DESCRIPTIVE_PHRASE], true)) {
            return false;
        }
        if ($kind === self::KIND_QUERY) {
            return true;
        }
        if ($kind === self::KIND_BRAND_ENTITY) {
            return true;
        }
        if ($source === KeywordSourceNormalizer::ANCHOR_TEXT && ! $hasCanonical) {
            return $keywordScore >= 0.55;
        }

        return $keywordScore >= 0.45;
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function isAnchorCandidate(string $kind, array $features, bool $isSeo): bool
    {
        if ($kind === self::KIND_QUERY || $kind === self::KIND_SENTENCE || $kind === self::KIND_URL_DOMAIN || $kind === self::KIND_NOISE || $kind === self::KIND_DESCRIPTIVE_PHRASE) {
            return false;
        }
        if ($kind === self::KIND_BRAND_ENTITY && (int) $features['word_count'] <= 4) {
            return true;
        }
        if ($kind !== self::KIND_KEYWORD_PHRASE) {
            return false;
        }
        $wc = (int) $features['word_count'];

        return $isSeo && $wc >= 2 && $wc <= 8 && (int) $features['marketing_hits'] === 0;
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function anchorPriority(array $features, int $occurrence, int $sourcePosts): int
    {
        $priority = 50;
        $priority += min(30, $occurrence * 4);
        $priority += min(15, $sourcePosts * 3);
        $priority -= min(20, max(0, ((int) $features['word_count']) - 4) * 3);

        return max(1, min(100, $priority));
    }

    /**
     * @return list<array{text: string, hint: string, phrase_kind: string}>
     */
    private function segments(string $raw): array
    {
        $segmenter = new KeywordAnchorSegmenter;
        $parts = $segmenter->segment($raw);
        if (count($parts) < 2) {
            return [];
        }

        $out = [];
        foreach ($parts as $part) {
            $norm = mb_strtolower(trim($part['text']));
            $sub = $this->classify($part['text'], $norm, [
                'source_kind' => KeywordSourceNormalizer::OTHER,
                'skip_segments' => true,
            ]);
            $out[] = [
                'text' => $part['text'],
                'hint' => $part['hint'],
                'phrase_kind' => $sub['phrase_kind'],
            ];
        }

        return $out;
    }

    /**
     * Generalized CTA / action-phrase detection (not site-specific blacklist).
     *
     * @param  array<string, mixed>  $features
     * @return array{score: int, is_cta_like: bool}
     */
    private function ctaAssessment(string $text, string $raw, array $features, array $globalRules): array
    {
        $score = 0;
        $wordCount = (int) ($features['word_count'] ?? 0);
        $productHits = (int) ($features['product_hits'] ?? 0);

        if ($this->startsWithAny($text, $globalRules['cta_action_terms'] ?? [])) {
            $score += 2;
        }
        if ($this->hitCount($text, $globalRules['cta_phrase_terms'] ?? []) > 0) {
            $score += 2;
        }
        if ($this->hitCount($text, $globalRules['sentence_hints'] ?? []) > 0 && $this->hitCount($text, $globalRules['cta_action_terms'] ?? []) > 0) {
            $score += 1;
        }
        if ($this->hitCount($text, $globalRules['cta_urgency_terms'] ?? []) > 0 && $this->hitCount($text, $globalRules['cta_action_terms'] ?? []) > 0) {
            $score += 1;
        }
        if (str_contains($raw, '→')) {
            $score += 2;
        }
        if ($wordCount <= 3 && $this->hitCount($text, $globalRules['cta_urgency_terms'] ?? []) > 0) {
            $score += 1;
        }

        $commercialSeoLead = $this->startsWithAny($text, $globalRules['commercial_lead_terms'] ?? [])
            && $productHits >= 1;
        if ($commercialSeoLead) {
            $score -= 3;
        }
        if ($productHits >= 2) {
            $score -= 1;
        }
        if ($features['has_question'] ?? false) {
            $score -= 2;
        }

        return [
            'score' => $score,
            'is_cta_like' => $score >= 3 && ! $commercialSeoLead && $productHits <= 1,
        ];
    }

    private function industryRuntime(): ?IndustryMatchRuntime
    {
        if ($this->industryRules !== null) {
            return $this->industryRules;
        }
        try {
            return app(IndustryMatchRuntime::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function globalRules(array $context): array
    {
        if (is_array($context['global_rules'] ?? null)) {
            return $context['global_rules'];
        }
        try {
            return ($this->globalRules ?? app(GlobalMatchRuleProvider::class))->globalMatchRules();
        } catch (\Throwable) {
            return [];
        }
    }

    private function hitCount(string $text, array $terms): int
    {
        $hits = 0;
        foreach ($terms as $term) {
            $term = mb_strtolower(trim((string) $term));
            if ($term !== '' && str_contains($text, $term)) {
                $hits++;
            }
        }

        return $hits;
    }

    private function startsWithAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            $term = mb_strtolower(trim((string) $term));
            if ($term !== '' && ($text === $term || str_starts_with($text, $term.' '))) {
                return true;
            }
        }

        return false;
    }
}
