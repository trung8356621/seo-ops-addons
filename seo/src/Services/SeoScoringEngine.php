<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Omnichannel\Addons\Seo\Support\SeoReasonPresentation;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

final class SeoScoringEngine
{
    private const SNIPPET_TIER_NONE = 'none';

    private const SNIPPET_TIER_AVERAGE = 'average';

    private const SNIPPET_TIER_GOOD = 'good';

    private const SNIPPET_TIER_EXCELLENT = 'excellent';

    /**
     * @return array{rows_min: int, rows_range: int, rows_max: int, min_columns: int, max_columns: int}
     */
    private function defaultFeaturedSnippetThresholds(): array
    {
        return [
            'rows_min' => 6,
            'rows_range' => 8,
            'rows_max' => 10,
            'min_columns' => 2,
            'max_columns' => 5,
        ];
    }

    /**
     * @param  list<array{question?: string, answer?: string}>  $faqsMeta
     * @param  array{seo_title?: string, meta_description?: string, slug?: string, domain?: string, article_length_target?: int, featured_snippet_thresholds?: array<string, int>}  $context
     * @return list<string>
     */
    public function analyzeViolations(
        string $htmlContent,
        string $targetKeyword = '',
        array $faqsMeta = [],
        array $context = [],
    ): array {
        $keyword = $this->normalizeFocusKeyword($targetKeyword);

        if ($keyword === '') {
            return [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD];
        }

        $seoTitle = trim((string) ($context['seo_title'] ?? ''));
        $metaDescription = trim((string) ($context['meta_description'] ?? ''));
        $slug = trim((string) ($context['slug'] ?? ''));
        $domain = trim((string) ($context['domain'] ?? ''));
        $lengthTarget = max(1, (int) ($context['article_length_target'] ?? 2000));

        $violations = [];

        if ($this->countH2Tags($htmlContent) < 2) {
            $violations[] = SeoScoringRulesRegistry::KEY_H2_MISSING;
        }

        if ($this->countWords($htmlContent) < $lengthTarget) {
            $violations[] = SeoScoringRulesRegistry::KEY_CONTENT_LENGTH_LOW;
        }

        foreach ($this->resolveImageRatioViolations($htmlContent) as $key) {
            $violations[] = $key;
        }

        if (! $this->hasWikiTrustExternalLink($htmlContent, $domain)) {
            $violations[] = SeoScoringRulesRegistry::KEY_WIKI_TRUST_MISSING;
        }

        if (! $this->hasFaqData($faqsMeta, $htmlContent)) {
            $violations[] = SeoScoringRulesRegistry::KEY_FAQ_MISSING;
        }

        foreach ($this->resolveKeywordViolations($htmlContent, $keyword, $seoTitle, $metaDescription, $slug) as $key) {
            $violations[] = $key;
        }

        $snippetViolation = $this->resolveFeaturedSnippetViolation(
            $htmlContent,
            is_array($context['featured_snippet_thresholds'] ?? null)
                ? $context['featured_snippet_thresholds']
                : $this->defaultFeaturedSnippetThresholds(),
        );
        if ($snippetViolation !== null) {
            $violations[] = $snippetViolation;
        }

        return array_values(array_filter(
            SeoScoringRulesRegistry::sanitizeViolations($violations),
            static fn (string $key): bool => SeoScoringRulesRegistry::isRuleEnabled($key),
        ));
    }

    /**
     * @return list<string>
     */
    private function resolveImageRatioViolations(string $html): array
    {
        $result = $this->calculateTextToImageMetrics($html);
        $violations = [];

        $missing = max(0, (int) ($result['missing_image_count'] ?? 0));
        $validCount = max(0, (int) ($result['current_image_count'] ?? 0));
        $wordCount = max(0, (int) ($result['current_word_count'] ?? 0));

        $ratioKey = match (true) {
            $wordCount >= 10 && $validCount === 0 => SeoScoringRulesRegistry::KEY_IMAGE_RATIO_MISSING,
            $missing >= 3 => SeoScoringRulesRegistry::KEY_IMAGE_RATIO_POOR,
            $missing >= 2 => SeoScoringRulesRegistry::KEY_IMAGE_RATIO_LOW,
            $missing === 1 => SeoScoringRulesRegistry::KEY_IMAGE_RATIO_SUBOPTIMAL,
            default => null,
        };

        if ($ratioKey !== null) {
            $violations[] = $ratioKey;
        }

        if ($result['missing_alt'] > 0) {
            $violations[] = SeoScoringRulesRegistry::KEY_IMAGE_ALT_MISSING;
        }

        return $violations;
    }

    /**
     * @return array{
     *   base_score: int,
     *   missing_alt: int,
     *   current_image_count: int,
     *   recommended_image_count: int,
     *   missing_image_count: int,
     *   current_word_count: int,
     *   target_words_per_image: int
     * }
     */
    private function calculateTextToImageMetrics(string $htmlContent): array
    {
        $metrics = SeoReasonPresentation::imageRatioMetrics($htmlContent);

        return [
            'base_score' => (int) $metrics['base_score'],
            'missing_alt' => (int) $metrics['missing_alt'],
            'current_image_count' => (int) $metrics['current_image_count'],
            'recommended_image_count' => (int) $metrics['recommended_image_count'],
            'missing_image_count' => (int) $metrics['missing_image_count'],
            'current_word_count' => (int) $metrics['current_word_count'],
            'target_words_per_image' => (int) $metrics['target_words_per_image'],
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveKeywordViolations(
        string $html,
        string $keyword,
        string $seoTitle,
        string $metaDescription,
        string $slug,
    ): array {
        $violations = [];

        if (! KeywordPhraseMatcher::contains($seoTitle, $keyword)) {
            $violations[] = SeoScoringRulesRegistry::KEY_KEYWORD_MISSING_IN_TITLE;
        }

        // Keyword always lowercased before meta compare (matcher also normalizes).
        $keywordForMeta = mb_strtolower(trim($keyword), 'UTF-8');
        if (! KeywordPhraseMatcher::contains($metaDescription, $keywordForMeta)) {
            $violations[] = SeoScoringRulesRegistry::KEY_KEYWORD_MISSING_IN_META;
        }

        if (! $this->slugContainsFocusKeyword($slug, $keyword)) {
            $violations[] = SeoScoringRulesRegistry::KEY_KEYWORD_MISSING_IN_SLUG;
        }

        if (! KeywordPhraseMatcher::contains($this->sliceFirstWords($html, 100), $keyword)) {
            $violations[] = SeoScoringRulesRegistry::KEY_KEYWORD_MISSING_IN_INTRO;
        }

        return $violations;
    }

    /**
     * @param  array{rows_min?: int, rows_range?: int, rows_max?: int, min_columns?: int, max_columns?: int}  $thresholds
     */
    private function resolveFeaturedSnippetViolation(string $content, array $thresholds): ?string
    {
        $content = trim($content);
        if ($content === '' || preg_match('/<table\b/i', $content) !== 1) {
            return SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_MISSING;
        }

        $tier = $this->bestFeaturedSnippetTierFromHtml($content, $thresholds);

        return match ($tier) {
            self::SNIPPET_TIER_EXCELLENT => null,
            self::SNIPPET_TIER_GOOD => SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_BELOW_EXCELLENT,
            self::SNIPPET_TIER_AVERAGE => SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_BELOW_GOOD,
            default => SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_MISSING,
        };
    }

    /**
     * @param  array{rows_min?: int, rows_range?: int, rows_max?: int, min_columns?: int, max_columns?: int}  $thresholds
     */
    private function bestFeaturedSnippetTierFromHtml(string $html, array $thresholds): string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $minCols = max(1, (int) ($thresholds['min_columns'] ?? 2));
        $maxCols = max($minCols, (int) ($thresholds['max_columns'] ?? 5));
        $best = self::SNIPPET_TIER_NONE;
        $rank = [
            self::SNIPPET_TIER_NONE => 0,
            self::SNIPPET_TIER_AVERAGE => 1,
            self::SNIPPET_TIER_GOOD => 2,
            self::SNIPPET_TIER_EXCELLENT => 3,
        ];

        foreach ($dom->getElementsByTagName('table') as $table) {
            if (! $table instanceof DOMElement || ! $this->isTopLevelHtmlTable($table)) {
                continue;
            }

            $metrics = $this->htmlTableFeaturedSnippetMetrics($table, $minCols, $maxCols);
            if ($metrics === null) {
                continue;
            }

            $tier = $this->featuredSnippetTierFromDataRows($metrics['data_rows'], $thresholds);

            if ($rank[$tier] > $rank[$best]) {
                $best = $tier;
            }
        }

        return $best;
    }

    /**
     * @return array{data_rows: int, columns: int}|null
     */
    private function htmlTableFeaturedSnippetMetrics(DOMElement $table, int $minCols, int $maxCols): ?array
    {
        $rowColCounts = [];
        $headerRowCount = 0;
        $hasFirstColumnDescriptor = true;

        foreach ($table->getElementsByTagName('tr') as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cellCount = 0;
            $hasTh = false;
            $firstCellText = '';
            $colIndex = 0;
            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }

                $tag = strtolower($cell->tagName);
                if ($tag !== 'td' && $tag !== 'th') {
                    continue;
                }

                $cellCount++;
                $hasTh = $hasTh || $tag === 'th';
                if ($colIndex === 0) {
                    $firstCellText = trim((string) $cell->textContent);
                }
                $colIndex++;
            }

            if ($cellCount === 0) {
                continue;
            }

            $rowColCounts[] = $cellCount;
            if ($hasTh) {
                $headerRowCount++;
            }
            if ($firstCellText === '') {
                $hasFirstColumnDescriptor = false;
            }
        }

        if ($rowColCounts === []) {
            return null;
        }

        $colCount = max($rowColCounts);
        if (! $this->featuredSnippetColumnCountPasses($colCount, $minCols, $maxCols, $hasFirstColumnDescriptor)) {
            return null;
        }

        $dataRowCount = count($rowColCounts) - ($headerRowCount > 0 ? 1 : 0);

        return [
            'data_rows' => max(0, $dataRowCount),
            'columns' => $colCount,
        ];
    }

    private function isTopLevelHtmlTable(DOMElement $table): bool
    {
        $parent = $table->parentNode;
        while ($parent instanceof DOMElement) {
            if (strtolower($parent->tagName) === 'table') {
                return false;
            }

            $parent = $parent->parentNode;
        }

        return true;
    }

    /**
     * Cho phép bảng so sánh có thêm 1 cột đầu làm tiêu chí (vd. STT).
     */
    private function featuredSnippetColumnCountPasses(
        int $colCount,
        int $minCols,
        int $maxCols,
        bool $hasFirstColumnDescriptor,
    ): bool {
        if ($colCount >= $minCols && $colCount <= $maxCols) {
            return true;
        }

        if ($hasFirstColumnDescriptor && $colCount > 1) {
            $effective = $colCount - 1;

            return $effective >= $minCols && $effective <= $maxCols;
        }

        return false;
    }

    /**
     * @param  array{rows_min?: int, rows_range?: int, rows_max?: int}  $thresholds
     */
    private function featuredSnippetTierFromDataRows(int $dataRows, array $thresholds): string
    {
        $rowsMin = (int) ($thresholds['rows_min'] ?? 6);
        $rowsRange = (int) ($thresholds['rows_range'] ?? 8);
        $rowsMax = (int) ($thresholds['rows_max'] ?? 10);

        if ($dataRows >= $rowsMax) {
            return self::SNIPPET_TIER_EXCELLENT;
        }
        if ($dataRows >= $rowsRange) {
            return self::SNIPPET_TIER_GOOD;
        }
        if ($dataRows >= $rowsMin) {
            return self::SNIPPET_TIER_AVERAGE;
        }

        return self::SNIPPET_TIER_NONE;
    }

    /**
     * @param  list<array{question?: string, answer?: string}>  $faqsMeta
     */
    private function hasFaqData(array $faqsMeta, string $html = ''): bool
    {
        foreach ($faqsMeta as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));

            if ($question !== '' && $answer !== '') {
                return true;
            }
        }

        $html = trim($html);
        if ($html === '') {
            return false;
        }

        if (preg_match('/omi-faq-placeholder|\[omi_faq\]/i', $html) === 1) {
            return true;
        }

        if (preg_match('/\bomi-faq-item\b/i', $html) === 1) {
            return true;
        }

        return preg_match('/<h3\b[^>]*>[\s\S]*?<\/h3>\s*<p\b/i', $html) === 1;
    }

    private function hasWikiTrustExternalLink(string $html, string $domain): bool
    {
        $pattern = '/<a\b[^>]*\bhref\s*=\s*(["\'])([^"\']+)\1/iu';
        if (preg_match_all($pattern, $html, $matches) === false) {
            return false;
        }

        foreach ($matches[2] as $href) {
            $href = trim(html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($href === '' || str_starts_with($href, '#') || $this->isSpecialSchemeLink($href)) {
                continue;
            }

            if ($this->isInternalLink($href, $domain)) {
                continue;
            }

            if (SeoLinkMapLinkTypeClassifier::forUnresolvedUrl($href) === SeoLinkMapType::WikiTrust) {
                return true;
            }
        }

        return false;
    }

    private function isInternalLink(string $href, string $domain): bool
    {
        if (str_starts_with($href, '/')) {
            return true;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        $host = parse_url($href, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = SeoLinkMapLinkTypeClassifier::normalizeDomainHost($host);
        $normalizedDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost($domain);

        return $host !== '' && $normalizedDomain !== '' && $host === $normalizedDomain;
    }

    private function isSpecialSchemeLink(string $href): bool
    {
        $lower = strtolower($href);

        if (str_starts_with($lower, 'javascript:')) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if (! is_string($scheme) || $scheme === '') {
            return false;
        }

        return in_array(strtolower($scheme), [
            'tel', 'mailto', 'sms', 'fax', 'callto', 'geo', 'skype', 'whatsapp', 'viber', 'data', 'cid',
        ], true);
    }

    private function countH2Tags(string $html): int
    {
        if (trim($html) === '') {
            return 0;
        }

        if (preg_match_all('/<h2\b[^>]*>/iu', $html, $matches) === false) {
            return 0;
        }

        return count($matches[0] ?? []);
    }

    private function countWords(string $html): int
    {
        $text = trim(strip_tags($html));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        if ($text === '') {
            return 0;
        }

        preg_match_all('/\pL[\pL\pN\-]*/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    private function sliceFirstWords(string $html, int $wordLimit): string
    {
        $text = trim(strip_tags($html));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        if ($text === '') {
            return '';
        }

        preg_match_all('/\pL[\pL\pN\-]*/u', $text, $matches);
        $words = $matches[0] ?? [];

        return implode(' ', array_slice($words, 0, max(1, $wordLimit)));
    }

    private function slugContainsFocusKeyword(string $slug, string $focusKeyword): bool
    {
        $keywordSlug = Str::slug($this->normalizeFocusKeyword($focusKeyword));
        $articleSlug = Str::slug(trim($slug));

        if ($keywordSlug === '' || $articleSlug === '') {
            return false;
        }

        return str_contains($articleSlug, $keywordSlug);
    }

    private function normalizeFocusKeyword(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, ',')) {
            $parts = array_map(static fn (string $part): string => trim($part), explode(',', $raw));

            return $parts[0] ?? '';
        }

        return $raw;
    }
}
