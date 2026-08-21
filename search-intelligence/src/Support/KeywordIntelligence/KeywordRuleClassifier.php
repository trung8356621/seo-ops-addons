<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordRuleClassifier
{
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

        $features = $this->features($raw, $text);
        $cta = $this->ctaAssessment($text, $raw, $features);
        $kind = $this->kind($raw, $text, $features);
        if ($cta['is_cta_like']) {
            $kind = (int) $features['word_count'] >= 6 ? self::KIND_SENTENCE : self::KIND_DESCRIPTIVE_PHRASE;
        }
        $intent = $this->intent($text, $kind, $features);
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
    private function features(string $raw, string $text): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));
        $wordCount = count($words);

        $sentenceHints = [
            'chúng tôi', 'công ty chúng tôi', 'có thể', 'mang lại',
            'khách hàng', 'được', 'giúp', 'sẽ', 'đang', 'nên', 'cần',
        ];
        $hintHits = 0;
        foreach ($sentenceHints as $hint) {
            if (str_contains($text, $hint)) {
                $hintHits++;
            }
        }
        if (preg_match('/\blà\b/u', $text) === 1) {
            $hintHits++;
        }

        $marketing = ['đơn vị', 'uy tín', 'chuyên nghiệp', 'hàng đầu', 'chất lượng', 'đáng tin', 'cam kết', 'tận tâm'];
        $marketingHits = 0;
        foreach ($marketing as $m) {
            if (str_contains($text, $m)) {
                $marketingHits++;
            }
        }

        $products = ['túi', 'balo', 'vải', 'canvas', 'không dệt', 'xưởng', 'may', 'gia công', 'in logo', 'quà tặng', 'sản xuất'];
        $productHits = 0;
        foreach ($products as $p) {
            if (str_contains($text, $p)) {
                $productHits++;
            }
        }

        $locationHits = preg_match('/\b(tại|tp\.?|tphcm|hồ chí minh|hcm|hà nội|đà nẵng)\b/u', $text) === 1 ? 1 : 0;

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

        $strongSentence = str_contains($text, 'công ty chúng tôi')
            || (str_contains($text, 'chúng tôi') && ($hintHits >= 2 || str_contains($text, 'chuyên')));

        return [
            'word_count' => $wordCount,
            'has_url' => preg_match('#https?://#i', $raw) === 1 || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $text) === 1,
            'has_question' => str_contains($text, '?') || preg_match('/\b(gì|sao|như thế nào|ở đâu|làm sao|tại sao|how|what|where|why|không)\b/u', $text) === 1 && preg_match('/\b(ở đâu|là gì|như thế nào|tại sao|làm sao|how|what|where|why)\b/u', $text) === 1,
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
    private function kind(string $raw, string $text, array $features): string
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

        if ($this->looksLikeBrand($raw, $features)) {
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
    private function looksLikeBrand(string $raw, array $features): bool
    {
        $wc = (int) $features['word_count'];
        if ($wc < 1 || $wc > 5) {
            return false;
        }
        if ((int) $features['marketing_hits'] > 0 || $features['has_dash'] || $features['has_question']) {
            return false;
        }
        $folded = mb_strtolower($raw);
        $genericOnly = preg_match('/\b(túi|balo|canvas|không dệt|vải|giá|mua)\b/u', $folded) === 1;
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
    private function intent(string $text, string $kind, array $features): string
    {
        if (in_array($kind, [self::KIND_NOISE, self::KIND_URL_DOMAIN, self::KIND_SENTENCE, self::KIND_DESCRIPTIVE_PHRASE], true)) {
            return self::INTENT_UNKNOWN;
        }
        if (preg_match('/\b(mua|đặt hàng|order|buy|báo giá|giá xưởng|thanh toán)\b/u', $text) === 1) {
            return self::INTENT_TRANSACTIONAL;
        }
        if (preg_match('/\b(giá|báo giá|chi phí|bảng giá)\b/u', $text) === 1 && $kind !== self::KIND_BRAND_ENTITY) {
            return self::INTENT_TRANSACTIONAL;
        }
        if ($kind === self::KIND_QUERY || preg_match('/\b(là gì|hướng dẫn|cách|what is|how to)\b/u', $text) === 1) {
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
        $segmenter = new KeywordAnchorSegmenter();
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
    private function ctaAssessment(string $text, string $raw, array $features): array
    {
        $score = 0;
        $wordCount = (int) ($features['word_count'] ?? 0);
        $productHits = (int) ($features['product_hits'] ?? 0);

        if (preg_match('/^(nhận|liên hệ|đăng ký|gọi|xem|tìm hiểu|điền|bắt đầu|click|contact|get|request|read more|sign up)\b/u', $text) === 1) {
            $score += 2;
        }
        if (preg_match('/\b(liên hệ ngay|nhận tư vấn|đăng ký nhận|gọi ngay|xem thêm|tìm hiểu thêm|điền form|contact us|get quote|request quote)\b/u', $text) === 1) {
            $score += 2;
        }
        if (preg_match('/\b(chúng tôi|contact us)\b/u', $text) === 1 && preg_match('/\b(liên hệ|nhận|gọi|đăng ký|tư vấn)\b/u', $text) === 1) {
            $score += 1;
        }
        if (preg_match('/\b(ngay|miễn phí)\b/u', $text) === 1 && preg_match('/\b(nhận|liên hệ|đăng ký|gọi|tư vấn|báo giá ngay)\b/u', $text) === 1) {
            $score += 1;
        }
        if (str_contains($raw, '→')) {
            $score += 2;
        }
        if ($wordCount <= 3 && preg_match('/\b(ngay|miễn phí|here|now)\b/u', $text) === 1) {
            $score += 1;
        }

        $commercialSeoLead = preg_match('/^(báo giá|giá|mua|cách|xưởng|sản xuất|gia công|in logo)\b/u', $text) === 1
            && $productHits >= 1;
        if ($commercialSeoLead) {
            $score -= 3;
        }
        if ($productHits >= 2 && preg_match('/\b(túi|balo|canvas|vải|không dệt|may|xưởng)\b/u', $text) === 1) {
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
}
