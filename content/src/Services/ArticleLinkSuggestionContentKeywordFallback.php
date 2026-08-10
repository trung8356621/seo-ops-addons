<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;
use Omnichannel\Addons\Seo\Support\LinkSuggestionStopPhraseFilter;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use App\Support\RuntimeLogger;

/**
 * Fallback nhẹ: phrase trong content → search cùng domain (popup service).
 * Chỉ chạy khi primary internal suggestions < target.
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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function lastDebug(): array
    {
        return $this->lastDebug;
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

        $keyword = Keyword::query()
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
