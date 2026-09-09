<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;

/**
 * Match article plain text against full product_cat catalog for Internal Links.
 *
 * Ranking: exact/normalized category phrase >> article fallback (handled by caller score).
 * Depth is NOT a quality bonus — longer/more specific phrase wins over root partial.
 */
final class ArticleInternalLinkProductCatMatcher
{
    public const REASON_EXACT_NAME = 'product_cat_exact_name';

    public const REASON_NORMALIZED_PHRASE = 'product_cat_normalized_phrase';

    public const REASON_SEO_TITLE = 'product_cat_seo_title';

    public const REASON_SLUG = 'product_cat_slug';

    public const SCORE_EXACT_NAME = 98;

    public const SCORE_NORMALIZED_PHRASE = 96;

    public const SCORE_SEO_TITLE = 92;

    public const SCORE_SLUG = 88;

    public function __construct(
        private readonly ArticleInternalLinkProductCatCatalog $catalog,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $catalogRows
     * @param  array<string, mixed>  $validationContext
     * @param  list<string>  $alreadyLinkedNormalizedUrls
     * @param  list<string>  $alreadyLinkedLabels
     * @return array{
     *     suggestions: list<array<string, mixed>>,
     *     debug: array<string, mixed>
     * }
     */
    public function match(
        string $plainText,
        array $catalogRows,
        array $validationContext,
        array $alreadyLinkedNormalizedUrls = [],
        array $alreadyLinkedLabels = [],
    ): array {
        $plainNorm = KeywordPhraseMatcher::normalize($plainText);
        $debug = [
            'matched_product_cat' => 0,
            'product_cat_after_filter' => count($catalogRows),
            'skipped_already_linked_url' => 0,
            'skipped_already_linked_label' => 0,
            'skipped_validation' => 0,
            'skipped_self' => 0,
        ];

        if ($plainNorm === '' || $catalogRows === []) {
            return ['suggestions' => [], 'debug' => $debug];
        }

        /** @var list<array<string, mixed>> $scored */
        $scored = [];

        foreach ($catalogRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $hit = $this->bestHit($plainNorm, $row);
            if ($hit === null) {
                continue;
            }

            $url = trim((string) ($row['url'] ?? ''));
            $normalizedHref = SeoSuggestionUrlNormalizer::normalize($url);
            if ($normalizedHref !== '' && in_array($normalizedHref, $alreadyLinkedNormalizedUrls, true)) {
                $debug['skipped_already_linked_url']++;
                continue;
            }

            $anchor = (string) $hit['matched_phrase'];
            if ($this->isLabelLinked($anchor, $alreadyLinkedLabels)) {
                $debug['skipped_already_linked_label']++;
                continue;
            }

            $item = [
                'text' => $anchor,
                'href' => $url,
                'target_url' => $url,
                'target_article_id' => ((int) ($row['article_id'] ?? 0)) > 0 ? (int) $row['article_id'] : null,
                'can_insert' => true,
                'is_suggestion' => true,
                'score' => (int) $hit['score'],
                'match_reason' => (string) $hit['match_reason'],
                'source' => 'product_cat',
                'candidate_source' => ArticleInternalLinkProductCatCatalog::SOURCE,
                'taxonomy' => 'product_cat',
                'term_id' => (int) ($row['term_id'] ?? 0),
                'parent_term_id' => (int) ($row['parent_term_id'] ?? 0),
                'depth' => (int) ($row['depth'] ?? 0),
                'matched_phrase' => $anchor,
                'url' => $url,
                'health' => (string) ($row['health'] ?? 'ok'),
                'category_name' => (string) ($row['name'] ?? ''),
                'bucket' => 'internal',
                'provenance' => [
                    'candidate_source' => ArticleInternalLinkProductCatCatalog::SOURCE,
                    'taxonomy' => 'product_cat',
                    'term_id' => (int) ($row['term_id'] ?? 0),
                    'parent_term_id' => (int) ($row['parent_term_id'] ?? 0),
                    'depth' => (int) ($row['depth'] ?? 0),
                    'matched_phrase' => $anchor,
                    'match_reason' => (string) $hit['match_reason'],
                    'url' => $url,
                    'health' => (string) ($row['health'] ?? 'ok'),
                    'category_name' => (string) ($row['name'] ?? ''),
                ],
            ];

            if (! LinkSuggestionValidator::isValidLinkSuggestion($item, $validationContext)) {
                if (LinkSuggestionValidator::isSelfLink($url, $item, $validationContext)) {
                    $debug['skipped_self']++;
                } else {
                    $debug['skipped_validation']++;
                }
                continue;
            }

            unset($item['bucket']);
            $scored[] = $item;
        }

        usort($scored, static function (array $a, array $b): int {
            $scoreCmp = ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            // Prefer longer matched phrase (more specific) — not shallower depth.
            $lenCmp = mb_strlen((string) ($b['matched_phrase'] ?? '')) <=> mb_strlen((string) ($a['matched_phrase'] ?? ''));
            if ($lenCmp !== 0) {
                return $lenCmp;
            }

            return ((int) ($a['term_id'] ?? 0)) <=> ((int) ($b['term_id'] ?? 0));
        });

        // One suggestion per destination URL; keep highest-ranked.
        $deduped = [];
        $seenUrls = [];
        $seenAnchors = [];
        foreach ($scored as $item) {
            $urlKey = SeoSuggestionUrlNormalizer::normalize((string) ($item['href'] ?? ''));
            $anchorKey = KeywordPhraseMatcher::normalize((string) ($item['text'] ?? ''));
            if ($urlKey !== '' && isset($seenUrls[$urlKey])) {
                continue;
            }
            if ($anchorKey !== '' && isset($seenAnchors[$anchorKey])) {
                continue;
            }
            if ($urlKey !== '') {
                $seenUrls[$urlKey] = true;
            }
            if ($anchorKey !== '') {
                $seenAnchors[$anchorKey] = true;
            }
            $deduped[] = $item;
        }

        $debug['matched_product_cat'] = count($deduped);

        return ['suggestions' => $deduped, 'debug' => $debug];
    }

    /**
     * Convenience: load site catalog then match.
     *
     * @param  array<string, mixed>  $validationContext
     * @param  list<string>  $alreadyLinkedNormalizedUrls
     * @param  list<string>  $alreadyLinkedLabels
     * @return array{
     *     suggestions: list<array<string, mixed>>,
     *     debug: array<string, mixed>
     * }
     */
    public function matchForSite(
        int $siteId,
        string $plainText,
        array $validationContext,
        array $alreadyLinkedNormalizedUrls = [],
        array $alreadyLinkedLabels = [],
    ): array {
        $rows = $this->catalog->forSite($siteId);
        $catalogDebug = $this->catalog->lastDebug();
        $result = $this->match(
            $plainText,
            $rows,
            $validationContext,
            $alreadyLinkedNormalizedUrls,
            $alreadyLinkedLabels,
        );

        $result['debug'] = array_merge($catalogDebug, $result['debug']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{matched_phrase: string, score: int, match_reason: string}|null
     */
    private function bestHit(string $plainNorm, array $row): ?array
    {
        $candidates = [];

        $nameNorm = (string) ($row['name_norm'] ?? KeywordPhraseMatcher::normalize((string) ($row['name'] ?? '')));
        $coreNorm = (string) ($row['core_phrase_norm'] ?? $this->catalog->corePhraseNorm((string) ($row['name'] ?? '')));
        $seoNorm = (string) ($row['seo_title_norm'] ?? KeywordPhraseMatcher::normalize((string) ($row['seo_title'] ?? '')));
        $slugNorm = (string) ($row['slug_norm'] ?? '');

        if ($nameNorm !== '' && $this->containsPhrase($plainNorm, $nameNorm)) {
            $candidates[] = [
                'matched_phrase' => (string) ($row['name'] ?? $nameNorm),
                'score' => self::SCORE_EXACT_NAME,
                'match_reason' => self::REASON_EXACT_NAME,
                'needle_len' => mb_strlen($nameNorm),
            ];
        }

        if (
            $coreNorm !== ''
            && $coreNorm !== $nameNorm
            && KeywordPhraseMatcher::countWords($coreNorm) >= 2
            && $this->containsPhrase($plainNorm, $coreNorm)
        ) {
            $candidates[] = [
                'matched_phrase' => $this->displayPhraseFromNorm($coreNorm, (string) ($row['name'] ?? '')),
                'score' => self::SCORE_NORMALIZED_PHRASE,
                'match_reason' => self::REASON_NORMALIZED_PHRASE,
                'needle_len' => mb_strlen($coreNorm),
            ];
        }

        // Same core as full name (no prefix) — still count as strong normalized hit when present.
        if (
            $coreNorm !== ''
            && $coreNorm === $nameNorm
            && KeywordPhraseMatcher::countWords($coreNorm) >= 2
            && $this->containsPhrase($plainNorm, $coreNorm)
            && $candidates === []
        ) {
            $candidates[] = [
                'matched_phrase' => (string) ($row['name'] ?? $coreNorm),
                'score' => self::SCORE_NORMALIZED_PHRASE,
                'match_reason' => self::REASON_NORMALIZED_PHRASE,
                'needle_len' => mb_strlen($coreNorm),
            ];
        }

        if ($seoNorm !== '' && $seoNorm !== $nameNorm && $this->containsPhrase($plainNorm, $seoNorm)) {
            $candidates[] = [
                'matched_phrase' => (string) ($row['seo_title'] ?? $seoNorm),
                'score' => self::SCORE_SEO_TITLE,
                'match_reason' => self::REASON_SEO_TITLE,
                'needle_len' => mb_strlen($seoNorm),
            ];
        }

        if (
            $slugNorm !== ''
            && KeywordPhraseMatcher::countWords($slugNorm) >= 2
            && $this->containsPhrase($plainNorm, $slugNorm)
        ) {
            $candidates[] = [
                'matched_phrase' => $slugNorm,
                'score' => self::SCORE_SLUG,
                'match_reason' => self::REASON_SLUG,
                'needle_len' => mb_strlen($slugNorm),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            $scoreCmp = ((int) $b['score']) <=> ((int) $a['score']);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return ((int) $b['needle_len']) <=> ((int) $a['needle_len']);
        });

        $best = $candidates[0];
        $score = LinkSuggestionScoreScale::clamp((int) $best['score']);

        return [
            'matched_phrase' => (string) $best['matched_phrase'],
            'score' => $score,
            'match_reason' => (string) $best['match_reason'],
        ];
    }

    private function containsPhrase(string $haystackNorm, string $needleNorm): bool
    {
        if ($needleNorm === '' || $haystackNorm === '') {
            return false;
        }

        return mb_strpos($haystackNorm, $needleNorm) !== false;
    }

    /**
     * Prefer a human display form: if core phrase appears as contiguous tokens in name, use that casing.
     */
    private function displayPhraseFromNorm(string $coreNorm, string $fallbackName): string
    {
        $nameNorm = KeywordPhraseMatcher::normalize($fallbackName);
        if ($nameNorm !== '' && str_contains($nameNorm, $coreNorm)) {
            // Rebuild approximate display from original words after prefix strip.
            $words = preg_split('/\s+/u', trim($fallbackName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $coreWords = preg_split('/\s+/u', $coreNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $coreCount = count($coreWords);
            if ($coreCount > 0 && count($words) >= $coreCount) {
                for ($i = 0; $i <= count($words) - $coreCount; $i++) {
                    $slice = array_slice($words, $i, $coreCount);
                    $sliceNorm = KeywordPhraseMatcher::normalize(implode(' ', $slice));
                    if ($sliceNorm === $coreNorm) {
                        return implode(' ', $slice);
                    }
                }
            }
        }

        return $coreNorm;
    }

    /**
     * @param  list<string>  $linkedLabels
     */
    private function isLabelLinked(string $phrase, array $linkedLabels): bool
    {
        $needle = KeywordPhraseMatcher::normalize($phrase);
        if ($needle === '') {
            return false;
        }

        foreach ($linkedLabels as $label) {
            if (KeywordPhraseMatcher::normalize((string) $label) === $needle) {
                return true;
            }
        }

        return false;
    }
}
