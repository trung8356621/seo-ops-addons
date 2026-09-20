<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;
use Omnichannel\Addons\Seo\Support\LinkSuggestionStopPhraseFilter;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use App\Support\RuntimeLogger;

/**
 * Fallback nhẹ: phrase trong content → search cùng domain (popup service).
 * Chỉ chạy khi primary internal suggestions < target.
 * Advanced deep discovery also lives here (extractDeep + keyword target / article search).
 */
final class ArticleLinkSuggestionContentKeywordFallback
{
    /** @var array<string, mixed> */
    private array $lastDebug = [];

    /** @var array<string, int> */
    private array $phraseKeywordIdCache = [];

    public function __construct(
        private readonly ArticleLinkSuggestionContentPhraseExtractor $phraseExtractor,
        private readonly ArticleInternalLinkSearchService $searchService,
        private readonly KeywordLinkTargetResolver $linkTargetResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function lastDebug(): array
    {
        return $this->lastDebug;
    }

    /**
     * Advanced content-driven discovery — shorter phrases, keyword target first, then article search.
     *
     * @param  list<string>  $occupiedLabels
     * @param  list<string>  $occupiedNormalizedUrls
     * @param  array<int, true>  $seenTargetArticleIds
     * @param  array<string, mixed>  $validationContext
     * @param  list<string>  $processedPhraseKeys
     * @param  list<string>  $priorityPhrases
     * @return array{
     *     suggestions: list<array<string, mixed>>,
     *     next_offset: int,
     *     exhausted: bool,
     *     processed_keys: list<string>,
     *     failed_keys: list<string>,
     *     debug: array<string, mixed>
     * }
     */
    public function discoverAdvancedContentBatch(
        SeoArticle $article,
        string $htmlContent,
        array $occupiedLabels,
        array $occupiedNormalizedUrls,
        array $seenTargetArticleIds,
        array $validationContext,
        array $processedPhraseKeys = [],
        int $phraseOffset = 0,
        int $targetCount = 5,
        array $priorityPhrases = [],
    ): array {
        $targetCount = max(1, min(5, $targetCount));
        $siteId = (int) ($article->site_id ?? 0);
        $excludeId = (int) ($article->id ?? 0);
        $processed = [];
        foreach ($processedPhraseKeys as $key) {
            $normalized = KeywordPhraseMatcher::normalize((string) $key);
            if ($normalized !== '') {
                $processed[$normalized] = true;
            }
        }
        $newProcessed = [];
        $newFailed = [];
        $added = [];

        $this->lastDebug = [
            'entry' => 'advanced_content_deep',
            'article_id' => $excludeId,
            'site_id' => $siteId,
            'phrase_offset' => $phraseOffset,
            'target' => $targetCount,
            'extracted_phrase_count' => 0,
            'skipped_empty' => 0,
            'skipped_already_processed' => 0,
            'skipped_stop_phrase' => 0,
            'skipped_occupied_anchor' => 0,
            'phrases_with_keyword_target' => 0,
            'phrases_searched_index' => 0,
            'destination_candidates' => 0,
            'destination_candidates_passing_min_score' => 0,
            'rejected_below_min_score' => 0,
            'rejected_unresolved_or_policy' => 0,
            // valid_candidate = accepted after destination/validator (still before FE occurrence filter).
            'valid_candidate' => 0,
            'valid_after_policy' => 0,
            // fresh_after_occupied_dedupe = batch rows after occupied label/URL dedupe.
            // Not "NEW actionable" — empty existing_internal overstates novelty vs UI.
            'fresh_after_occupied_dedupe' => 0,
            'fresh_count' => 0,
            'no_candidate' => 0,
            'phrase_trace' => [],
            'metric_note' => 'fresh_after_occupied_dedupe ≠ actionable_after_FE; do not label empty-occupied fresh as NEW actionable',
        ];

        if ($siteId <= 0 || $excludeId <= 0 || trim($htmlContent) === '') {
            $this->lastDebug['skip_reason'] = 'invalid_context';

            return [
                'suggestions' => [],
                'next_offset' => $phraseOffset,
                'exhausted' => true,
                'processed_keys' => [],
                'failed_keys' => [],
                'debug' => $this->lastDebug,
            ];
        }

        $excludePhrases = $occupiedLabels;
        $phrases = $this->phraseExtractor->extractDeepAdvanced($htmlContent, $excludePhrases, $priorityPhrases);
        $this->lastDebug['extracted_phrase_count'] = count($phrases);

        $siteDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost(
            (string) ($validationContext['site_domain'] ?? $article->site?->domain ?? ''),
        );
        $minScore = LinkSuggestionScoreScale::fallbackMinAccept();
        $candidateLimit = max(1, (int) config('seo-content-ai.link_suggestions.fallback_candidate_limit', 20));

        $i = max(0, $phraseOffset);
        $total = count($phrases);
        for (; $i < $total; $i++) {
            if (count($added) >= $targetCount) {
                break;
            }

            $row = $phrases[$i];
            $phrase = trim((string) ($row['phrase'] ?? ''));
            $phraseKey = KeywordPhraseMatcher::normalize($phrase);
            if ($phrase === '' || $phraseKey === '') {
                $this->lastDebug['skipped_empty']++;
                continue;
            }
            if (isset($processed[$phraseKey])) {
                $this->lastDebug['skipped_already_processed']++;
                continue;
            }
            if (LinkSuggestionStopPhraseFilter::isStopPhrase($phrase)) {
                $this->lastDebug['skipped_stop_phrase']++;
                $processed[$phraseKey] = true;
                $newProcessed[] = $phraseKey;
                $newFailed[] = 'deep|'.$phraseKey.'|stop';
                continue;
            }
            if ($this->labelOccupied($phrase, $occupiedLabels)) {
                $this->lastDebug['skipped_occupied_anchor']++;
                $processed[$phraseKey] = true;
                $newProcessed[] = $phraseKey;
                continue;
            }

            $trace = [
                'phrase' => $phrase,
                'source' => (string) ($row['source'] ?? ''),
                'path' => null,
                'reject' => null,
            ];

            $resolved = $this->resolveDeepDestination(
                $article,
                $phrase,
                $siteId,
                $excludeId,
                $siteDomain,
                $validationContext,
                $occupiedNormalizedUrls,
                $seenTargetArticleIds,
                $candidateLimit,
                $minScore,
                $trace,
            );

            $processed[$phraseKey] = true;
            $newProcessed[] = $phraseKey;

            if ($resolved === null) {
                $newFailed[] = 'deep|'.$phraseKey.'|'.($trace['reject'] ?? 'no_candidate');
                if (($trace['reject'] ?? '') === 'no_candidate' || ($trace['reject'] ?? '') === 'no_candidates') {
                    $this->lastDebug['no_candidate']++;
                }
                $this->lastDebug['phrase_trace'][] = $trace;
                continue;
            }

            $added[] = $resolved;
            $norm = SeoSuggestionUrlNormalizer::normalize((string) ($resolved['href'] ?? ''));
            if ($norm !== '') {
                $occupiedNormalizedUrls[] = $norm;
            }
            $tid = (int) ($resolved['target_article_id'] ?? 0);
            if ($tid > 0) {
                $seenTargetArticleIds[$tid] = true;
            }
            $occupiedLabels[] = mb_strtolower($phrase);
            $this->lastDebug['valid_after_policy']++;
            $this->lastDebug['valid_candidate']++;
            $this->lastDebug['phrase_trace'][] = $trace;
        }

        $exhausted = $i >= $total;
        $this->lastDebug['next_offset'] = $i;
        $this->lastDebug['exhausted'] = $exhausted;
        $this->lastDebug['fresh_count'] = count($added);
        $this->lastDebug['fresh_after_occupied_dedupe'] = count($added);
        $this->logDebug('advanced_content_deep', $this->lastDebug);

        return [
            'suggestions' => $added,
            'next_offset' => $i,
            'exhausted' => $exhausted,
            'processed_keys' => array_values(array_unique($newProcessed)),
            'failed_keys' => array_values(array_unique($newFailed)),
            'debug' => $this->lastDebug,
        ];
    }

    /**
     * @param  list<string>  $occupiedNormalizedUrls
     * @param  array<int, true>  $seenTargetArticleIds
     * @param  array<string, mixed>  $validationContext
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>|null
     */
    private function resolveDeepDestination(
        SeoArticle $article,
        string $phrase,
        int $siteId,
        int $excludeId,
        string $siteDomain,
        array $validationContext,
        array $occupiedNormalizedUrls,
        array $seenTargetArticleIds,
        int $candidateLimit,
        int $minScore,
        array &$trace,
    ): ?array {
        // 1) Keyword inventory target (same site, active).
        $keyword = Keyword::query()
            ->forSite($siteId)
            ->where('type', Keyword::TYPE_NORMAL)
            ->where('review_status', KeywordReviewStatus::Active->value)
            ->whereRaw('LOWER(TRIM(phrase)) = ?', [mb_strtolower(trim($phrase))])
            ->first();

        if ($keyword instanceof Keyword) {
            $this->lastDebug['phrases_with_keyword_target']++;
            $href = $this->linkTargetResolver->resolveForKeyword(
                $keyword,
                $article,
                sameLanguageOnly: true,
                internalOnly: true,
            );
            $href = is_string($href) ? trim($href) : '';
            if ($href !== '' && ! SeoSuggestionUrlNormalizer::isPlaceholder($href)) {
                $trace['path'] = 'keyword_target';
                $item = $this->buildDeepSuggestionItem(
                    $phrase,
                    $href,
                    (int) $keyword->id,
                    $article,
                    $siteId,
                    $siteDomain,
                    $validationContext,
                    $occupiedNormalizedUrls,
                    $seenTargetArticleIds,
                    90,
                    'advanced_keyword_target',
                    $trace,
                );
                if ($item !== null) {
                    return $item;
                }
            }
        }

        // 2) Site article index search.
        $this->lastDebug['phrases_searched_index']++;
        $results = $this->searchService->search($siteId, $excludeId, $phrase, $candidateLimit);
        $this->lastDebug['destination_candidates'] += count($results);
        $trace['path'] = 'article_index';
        if ($results === []) {
            $trace['reject'] = 'no_candidates';

            return null;
        }

        foreach ($results as $hit) {
            $score = LinkSuggestionScoreScale::clamp((int) ($hit['score'] ?? 0));
            if ($score < $minScore) {
                $trace['reject'] = 'below_min_score';
                $this->lastDebug['rejected_below_min_score']++;

                continue;
            }
            $this->lastDebug['destination_candidates_passing_min_score']++;
            $href = trim((string) ($hit['url'] ?? ''));
            $targetId = (int) ($hit['id'] ?? 0);
            if ($targetId <= 0 || $href === '' || SeoSuggestionUrlNormalizer::isPlaceholder($href)) {
                $trace['reject'] = 'missing_url';
                $this->lastDebug['rejected_unresolved_or_policy']++;

                continue;
            }
            if ($targetId === $excludeId || isset($seenTargetArticleIds[$targetId])) {
                $trace['reject'] = 'self_or_duplicate_target';
                $this->lastDebug['rejected_unresolved_or_policy']++;

                continue;
            }
            $item = $this->buildDeepSuggestionItem(
                $phrase,
                $href,
                $this->resolveKeywordIdForPhrase($phrase, $siteId),
                $article,
                $siteId,
                $siteDomain,
                $validationContext,
                $occupiedNormalizedUrls,
                $seenTargetArticleIds,
                $score,
                (string) ($hit['match_reason'] ?? 'advanced_article_index'),
                $trace,
                $targetId,
            );
            if ($item !== null) {
                return $item;
            }
            $this->lastDebug['rejected_unresolved_or_policy']++;
        }

        if (($trace['reject'] ?? null) === null) {
            $trace['reject'] = 'no_candidate_passed';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $validationContext
     * @param  list<string>  $occupiedNormalizedUrls
     * @param  array<int, true>  $seenTargetArticleIds
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>|null
     */
    private function buildDeepSuggestionItem(
        string $phrase,
        string $href,
        ?int $keywordId,
        SeoArticle $article,
        int $siteId,
        string $siteDomain,
        array $validationContext,
        array $occupiedNormalizedUrls,
        array $seenTargetArticleIds,
        int $score,
        string $matchReason,
        array &$trace,
        int $targetArticleId = 0,
    ): ?array {
        $norm = SeoSuggestionUrlNormalizer::normalize($href);
        if ($norm === '' || in_array($norm, $occupiedNormalizedUrls, true)) {
            $trace['reject'] = 'duplicate_url';

            return null;
        }

        $host = SeoSuggestionUrlNormalizer::host($href);
        if ($host !== '' && $siteDomain !== '' && $host !== $siteDomain && ! str_starts_with($href, '/')) {
            $trace['reject'] = 'not_same_domain';

            return null;
        }

        if ($targetArticleId <= 0) {
            $found = $this->linkTargetResolver->resolveArticleFromUrl($siteId, $href, $article);
            $targetArticleId = $found instanceof SeoArticle ? (int) $found->id : 0;
        }
        if ($targetArticleId > 0 && isset($seenTargetArticleIds[$targetArticleId])) {
            $trace['reject'] = 'duplicate_target';

            return null;
        }

        $item = [
            'text' => $phrase,
            'keyword_id' => $keywordId,
            'href' => $href,
            'target_url' => $href,
            'target_article_id' => $targetArticleId > 0 ? $targetArticleId : null,
            'destination_resolved' => true,
            'can_insert' => true,
            'is_suggestion' => true,
            'score' => $score,
            'match_reason' => $matchReason,
            'source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
            'candidate_source' => 'content_deep',
            'bucket' => 'internal',
            'provenance' => [
                'candidate_source' => 'content_deep',
                'match_reason' => $matchReason,
                'url' => $href,
                'destination_resolved' => true,
            ],
        ];

        if (! LinkSuggestionValidator::isValidLinkSuggestion($item, $validationContext)) {
            $trace['reject'] = 'failed_validator';

            return null;
        }
        unset($item['bucket']);
        $trace['reject'] = null;
        $trace['accepted'] = $href;

        return $item;
    }

    /**
     * @param  list<string>  $occupiedLabels
     */
    private function labelOccupied(string $phrase, array $occupiedLabels): bool
    {
        $needle = KeywordPhraseMatcher::normalize($phrase);
        if ($needle === '') {
            return false;
        }
        foreach ($occupiedLabels as $label) {
            if (KeywordPhraseMatcher::normalize((string) $label) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function shouldRun(int $currentInternalCount): bool
    {
        if (! (bool) config('seo-content-ai.link_suggestions.fallback_enabled', true)) {
            return false;
        }

        return $currentInternalCount < $this->targetCount();
    }

    public function skipReason(int $currentInternalCount): ?string
    {
        if (! (bool) config('seo-content-ai.link_suggestions.fallback_enabled', true)) {
            return 'fallback_disabled';
        }

        $target = $this->targetCount();
        if ($currentInternalCount >= $target) {
            return 'primary_already_at_or_above_target';
        }

        return null;
    }

    public function targetCount(): int
    {
        return max(1, (int) config('seo-content-ai.link_suggestions.target_internal_suggestions', 5));
    }

    /**
     * @param  list<array<string, mixed>>  $existingInternal
     * @param  list<string>  $linkedLabels
     * @param  list<string>  $linkedNormalizedUrls
     * @param  array<int, true>  $seenTargetArticleIds
     * @param  array{
     *     site_domain: string,
     *     site_id: int,
     *     current_article_id: int,
     *     current_urls: list<string>,
     *     current_slug: string
     * }  $validationContext
     * @param  list<string>  $priorityPhrases
     * @return list<array<string, mixed>>
     */
    public function supplement(
        SeoArticle $article,
        string $htmlContent,
        array $existingInternal,
        array $linkedLabels,
        array $linkedNormalizedUrls,
        array $seenTargetArticleIds,
        array $validationContext,
        array $priorityPhrases = [],
        bool $forceRun = false,
    ): array {
        $primaryCount = count($existingInternal);
        $target = $this->targetCount();
        $minScore = LinkSuggestionScoreScale::fallbackMinAccept();
        $candidateLimit = max(1, (int) config('seo-content-ai.link_suggestions.fallback_candidate_limit', 20));
        $contentLen = mb_strlen($htmlContent);

        $this->lastDebug = [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0),
            'entry' => 'content_keyword_fallback.supplement',
            'primary_valid_count' => $primaryCount,
            'target_internal_suggestions' => $target,
            'fallback_min_score' => $minScore,
            'score_scale' => '0-100',
            'content_length' => $contentLen,
            'force_run' => $forceRun,
            'fallback_triggered' => false,
            'skip_reason' => null,
            'extracted_phrase_count' => 0,
            'phrases' => [],
            'phrase_searches' => [],
            'fallback_valid_count' => 0,
            'rejected_reason_counts' => [],
        ];

        $skip = $forceRun ? null : $this->skipReason($primaryCount);
        if ($skip !== null) {
            $this->lastDebug['skip_reason'] = $skip;
            $this->logDebug('skip', $this->lastDebug);

            return [];
        }

        $this->phraseKeywordIdCache = [];
        $needed = max(1, $target - $primaryCount);
        if ($forceRun && $primaryCount >= $target) {
            $needed = max(1, (int) config('seo-content-ai.link_suggestions.fallback_phrase_limit', 10));
        }

        $siteId = (int) ($article->site_id ?? 0);
        $excludeId = (int) ($article->id ?? 0);
        if ($siteId <= 0 || $excludeId <= 0) {
            $this->lastDebug['skip_reason'] = 'invalid_site_or_article';
            $this->logDebug('skip', $this->lastDebug);

            return [];
        }

        if ($contentLen < 20) {
            $this->lastDebug['skip_reason'] = 'content_too_short_or_empty';
            $this->logDebug('skip', $this->lastDebug);

            return [];
        }

        $this->lastDebug['fallback_triggered'] = true;

        $excludePhrases = $linkedLabels;
        foreach ($existingInternal as $row) {
            $excludePhrases[] = (string) ($row['text'] ?? '');
        }

        $phrases = $this->phraseExtractor->extract($htmlContent, $excludePhrases, $priorityPhrases);
        $this->lastDebug['extracted_phrase_count'] = count($phrases);
        $this->lastDebug['phrases'] = array_map(
            static fn (array $row): array => [
                'phrase' => (string) ($row['phrase'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
            ],
            $phrases,
        );

        if ($phrases === []) {
            $this->lastDebug['skip_reason'] = 'no_phrases_extracted';
            $this->logDebug('no_phrases', $this->lastDebug);

            return [];
        }

        $siteDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost(
            (string) ($validationContext['site_domain'] ?? $article->site?->domain ?? ''),
        );

        $added = [];
        $seenUrls = $linkedNormalizedUrls;
        $seenTargets = $seenTargetArticleIds;
        $usedOffsets = [];
        $rejectCounts = [];

        $bumpReject = static function (string $reason) use (&$rejectCounts): void {
            $rejectCounts[$reason] = ($rejectCounts[$reason] ?? 0) + 1;
        };

        foreach ($phrases as $phraseRow) {
            if (count($added) >= $needed) {
                break;
            }

            $phrase = trim((string) ($phraseRow['phrase'] ?? ''));
            if ($phrase === '' || LinkSuggestionStopPhraseFilter::isStopPhrase($phrase)) {
                $bumpReject('stop_phrase');
                continue;
            }

            $occurrence = $this->phraseExtractor->findVerbatimOccurrence($htmlContent, $phrase);
            if ($occurrence === null) {
                $bumpReject('anchor_not_in_content');
                continue;
            }

            $offset = (int) ($occurrence['offset'] ?? -1);
            if ($offset >= 0 && isset($usedOffsets[$offset])) {
                $bumpReject('offset_already_used');
                continue;
            }

            $results = $this->searchService->search($siteId, $excludeId, $phrase, $candidateLimit);
            $top = [];
            foreach (array_slice($results, 0, 3) as $hit) {
                $top[] = [
                    'id' => (int) ($hit['id'] ?? 0),
                    'title' => (string) ($hit['title'] ?? ''),
                    'url' => (string) ($hit['url'] ?? ''),
                    'score' => (int) ($hit['score'] ?? 0),
                    'match_reason' => (string) ($hit['match_reason'] ?? ''),
                ];
            }

            $phraseDebug = [
                'phrase' => $phrase,
                'candidates' => count($results),
                'top' => $top,
                'accepted' => null,
                'reject' => null,
            ];

            $accepted = false;
            foreach ($results as $hit) {
                if (count($added) >= $needed) {
                    break;
                }

                $score = LinkSuggestionScoreScale::clamp((int) ($hit['score'] ?? 0));
                if ($score < $minScore) {
                    $bumpReject('below_fallback_min_score');
                    $phraseDebug['reject'] = 'below_fallback_min_score:'.$score;
                    continue;
                }

                $targetId = (int) ($hit['id'] ?? 0);
                $href = trim((string) ($hit['url'] ?? ''));
                if ($targetId <= 0 || $href === '' || SeoSuggestionUrlNormalizer::isPlaceholder($href)) {
                    $bumpReject('missing_or_placeholder_url');
                    $phraseDebug['reject'] = 'missing_or_placeholder_url';
                    continue;
                }

                if ($targetId === $excludeId || isset($seenTargets[$targetId])) {
                    $bumpReject('duplicate_or_self_article');
                    $phraseDebug['reject'] = 'duplicate_or_self_article';
                    continue;
                }

                $normalizedHref = SeoSuggestionUrlNormalizer::normalize($href);
                if ($normalizedHref === '' || in_array($normalizedHref, $seenUrls, true)) {
                    $bumpReject('duplicate_url');
                    $phraseDebug['reject'] = 'duplicate_url';
                    continue;
                }

                $keywordId = $this->resolveKeywordIdForPhrase($phrase, $siteId);

                $item = [
                    'text' => $phrase,
                    'keyword_id' => $keywordId,
                    'href' => $href,
                    'target_url' => $href,
                    'target_article_id' => $targetId,
                    'can_insert' => true,
                    'is_suggestion' => true,
                    'score' => $score,
                    'match_reason' => (string) ($hit['match_reason'] ?? 'content_fallback'),
                    'source' => 'content_keyword_fallback',
                    'offset' => $offset >= 0 ? $offset : null,
                    'phrase_source' => (string) ($phraseRow['source'] ?? 'content'),
                    'bucket' => 'internal',
                ];

                if (! LinkSuggestionValidator::isValidLinkSuggestion($item, $validationContext)) {
                    $bumpReject('failed_validator');
                    $phraseDebug['reject'] = 'failed_validator';
                    continue;
                }

                $host = SeoSuggestionUrlNormalizer::host($href);
                if ($host !== '' && $siteDomain !== '' && $host !== $siteDomain) {
                    $bumpReject('not_same_domain');
                    $phraseDebug['reject'] = 'not_same_domain';
                    continue;
                }

                unset($item['bucket']);
                $added[] = $item;
                $seenTargets[$targetId] = true;
                $seenUrls[] = $normalizedHref;
                if ($offset >= 0) {
                    $usedOffsets[$offset] = true;
                }

                $phraseDebug['accepted'] = [
                    'id' => $targetId,
                    'title' => (string) ($hit['title'] ?? ''),
                    'url' => $href,
                    'score' => $score,
                ];
                $accepted = true;
                break;
            }

            if (! $accepted && $phraseDebug['reject'] === null) {
                $phraseDebug['reject'] = $results === [] ? 'no_candidates' : 'no_candidate_passed';
                $bumpReject((string) $phraseDebug['reject']);
            }

            $this->lastDebug['phrase_searches'][] = $phraseDebug;
        }

        $this->lastDebug['fallback_valid_count'] = count($added);
        $this->lastDebug['rejected_reason_counts'] = $rejectCounts;
        $this->lastDebug['final_suggestions'] = array_map(
            static fn (array $row): array => [
                'text' => (string) ($row['text'] ?? ''),
                'target_article_id' => (int) ($row['target_article_id'] ?? 0),
                'href' => (string) ($row['href'] ?? ''),
                'score' => (int) ($row['score'] ?? 0),
            ],
            $added,
        );
        $this->logDebug('done', $this->lastDebug);

        return $added;
    }

    private function resolveKeywordIdForPhrase(string $phrase, int $siteId): ?int
    {
        if ($siteId <= 0) {
            return null;
        }

        $prepared = Keyword::preparePhraseForStorage($phrase);
        if ($prepared === '') {
            return null;
        }

        $cacheKey = mb_strtolower($prepared);
        if (isset($this->phraseKeywordIdCache[$cacheKey])) {
            return $this->phraseKeywordIdCache[$cacheKey];
        }

        $driver = Keyword::query()->getConnection()->getDriverName();
        $keyword = $driver === 'sqlite'
            ? Keyword::query()
                ->whereRaw('LOWER(TRIM(phrase)) = ?', [mb_strtolower($prepared)])
                ->first()
            : Keyword::query()
                ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$prepared])
                ->first();

        if (! $keyword instanceof Keyword) {
            return null;
        }

        $keywordId = (int) $keyword->id;
        if ($keywordId > 0) {
            $this->phraseKeywordIdCache[$cacheKey] = $keywordId;
        }

        return $keywordId > 0 ? $keywordId : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logDebug(string $step, array $payload): void
    {
        if (! LinkSuggestionStopPhraseFilter::debugEnabled()) {
            return;
        }

        RuntimeLogger::info('[LINK_FALLBACK_DEBUG] '.$step, $payload);
    }
}
