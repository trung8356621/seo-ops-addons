<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;
use Illuminate\Database\Eloquent\Builder;

final class ArticleInternalLinkSearchService
{
    public const REASON_TITLE_EXACT = 'title_exact';

    public const REASON_TITLE_BOUNDARY = 'title_boundary';

    public const REASON_TITLE_CONTAINS = 'title_contains';

    public const REASON_FOCUS_KEYWORD = 'focus_keyword';

    public const REASON_SLUG_MATCH = 'slug_match';

    public const REASON_TOKEN_OVERLAP = 'token_overlap';

    /** Starts/ends with the complete phrase (stronger than mid-title contains). */
    public const SCORE_TITLE_BOUNDARY = 85;

    /** Contiguous token overlap that is not a full-phrase title/slug hit — below fallback min. */
    public const SCORE_WEAK_TOKEN_OVERLAP = 35;

    public function __construct(
        private readonly WordPressInternalLinkTargetPolicy $linkTargetPolicy,
        private readonly ArticleLinkSuggestionCandidateRetriever $candidateRetriever,
    ) {}

    /**
     * Tìm bài cùng site để chèn link nội bộ nhanh trong editor.
     * Ưu tiên relevance score (title/slug/keyword/heading), không sort theo updated_at thuần.
     *
     * @return list<array{id: int, title: string, url: string, label: string, score?: int, match_reason?: string}>
     */
    public function search(int $siteId, int $excludeArticleId, string $query, int $limit = 15): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(30, $limit));

        $current = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereKey($excludeArticleId)
            ->first(['id', 'site_id', 'title', 'slug', 'language']);

        if ($current instanceof SeoArticle) {
            $ranked = $this->candidateRetriever->searchRanked($current, $query, $limit);
            if ($ranked !== []) {
                return array_map(static function (array $row): array {
                    return [
                        'id' => (int) $row['id'],
                        'title' => (string) $row['title'],
                        'url' => (string) $row['url'],
                        'label' => sprintf('#%d · %s', $row['id'], $row['title']),
                        'score' => (int) ($row['score'] ?? 0),
                        'match_reason' => (string) ($row['match_reason'] ?? ''),
                    ];
                }, $ranked);
            }
        }

        // Fallback hẹp: title LIKE + exclude current (khi index rank không có kết quả).
        // Same eligibility as ranked index: must be synced to WordPress with a real permalink.
        // Hard gate: fallback candidates must match the current article's language.
        $currentLanguage = $current instanceof SeoArticle ? ((string) ($current->language ?? '') ?: 'vi') : 'vi';
        $escaped = str_replace(['%', '_'], ['\%', '\_'], $query);
        $poolLimit = min(100, max(40, $limit * 5));
        $builder = ArticleResource::getEloquentQuery()
            ->with(['site', 'articleMetas', 'wordpressLink'])
            ->where('site_id', $siteId)
            ->where('id', '!=', $excludeArticleId)
            ->where('language', $currentLanguage)
            ->notContentArchived()
            ->hasWpPostId()
            ->where(function (Builder $inner) use ($query, $escaped): void {
                $inner->where('title', 'like', '%'.$escaped.'%')
                    ->orWhere('slug', 'like', '%'.$escaped.'%');

                if (ctype_digit($query)) {
                    $inner->orWhere('id', (int) $query);
                }
            });

        $scored = $builder
            ->orderByDesc('updated_at')
            ->limit($poolLimit)
            ->get()
            ->map(fn (SeoArticle $article): ?array => $this->formatResult($article, $query))
            ->filter()
            ->values()
            ->all();

        usort($scored, static function (array $a, array $b): int {
            $scoreCmp = ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        return array_slice($scored, 0, $limit);
    }

    /**
     * Deterministic LIKE-fallback relevance — SSOT for any consumer of unscored SQL LIKE rows.
     * Compatible with LinkSuggestionScoreScale (0..100). Weak token overlap stays below fallbackMinAccept.
     *
     * @return array{score: int, match_reason: string}
     */
    public function scoreLikeFallbackRelevance(
        string $query,
        string $title,
        string $slug = '',
        string $focusKeyword = '',
    ): array {
        $phraseNorm = KeywordPhraseMatcher::normalize($query);
        if ($phraseNorm === '') {
            return ['score' => 0, 'match_reason' => self::REASON_TOKEN_OVERLAP];
        }

        $titleNorm = KeywordPhraseMatcher::normalize($title);
        $slugNorm = KeywordPhraseMatcher::normalize(str_replace(['-', '_'], ' ', $slug));
        $focusNorm = KeywordPhraseMatcher::normalize($focusKeyword);

        if ($titleNorm !== '' && $titleNorm === $phraseNorm) {
            return [
                'score' => LinkSuggestionScoreScale::TITLE_EXACT,
                'match_reason' => self::REASON_TITLE_EXACT,
            ];
        }

        if ($focusNorm !== '' && $focusNorm === $phraseNorm) {
            return [
                'score' => LinkSuggestionScoreScale::FOCUS_KEYWORD,
                'match_reason' => self::REASON_FOCUS_KEYWORD,
            ];
        }

        if ($titleNorm !== '' && $this->normalizedHasPhraseBoundary($titleNorm, $phraseNorm)) {
            return [
                'score' => self::SCORE_TITLE_BOUNDARY,
                'match_reason' => self::REASON_TITLE_BOUNDARY,
            ];
        }

        if ($titleNorm !== '' && str_contains($titleNorm, $phraseNorm)) {
            return [
                'score' => LinkSuggestionScoreScale::TITLE_CONTAINS,
                'match_reason' => self::REASON_TITLE_CONTAINS,
            ];
        }

        if ($slugNorm !== '' && str_contains($slugNorm, $phraseNorm)) {
            return [
                'score' => LinkSuggestionScoreScale::SLUG_MATCH,
                'match_reason' => self::REASON_SLUG_MATCH,
            ];
        }

        $overlapScore = $this->weakTokenOverlapScore($phraseNorm, $titleNorm, $slugNorm);

        return [
            'score' => $overlapScore,
            'match_reason' => self::REASON_TOKEN_OVERLAP,
        ];
    }

    /**
     * @return array{id: int, title: string, url: string, label: string, score: int, match_reason: string}|null
     */
    private function formatResult(SeoArticle $article, string $query): ?array
    {
        $title = trim((string) $article->title);
        if ($title === '') {
            return null;
        }

        $url = $this->resolveArticleUrl($article);
        if ($url === '') {
            return null;
        }

        $slug = trim((string) ($article->slug ?? ''), '/');
        $focus = $this->resolveFocusKeyword($article);
        $scored = $this->scoreLikeFallbackRelevance($query, $title, $slug, $focus);

        return [
            'id' => (int) $article->id,
            'title' => $title,
            'url' => $url,
            'label' => sprintf('#%d · %s', $article->id, $title),
            'score' => LinkSuggestionScoreScale::clamp((int) $scored['score']),
            'match_reason' => (string) $scored['match_reason'],
        ];
    }

    private function resolveFocusKeyword(SeoArticle $article): string
    {
        $metas = $article->articleMetas ?? collect();
        foreach (['seo_focus_keyword', 'rank_math_focus_keyword'] as $key) {
            $value = trim((string) ($metas->firstWhere('meta_key', $key)?->meta_value ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveArticleUrl(SeoArticle $article): string
    {
        return trim((string) ($this->linkTargetPolicy->resolveAuthoritativePermalink($article) ?? ''));
    }

    /**
     * Complete phrase at start or end of normalized title (word-bounded).
     */
    private function normalizedHasPhraseBoundary(string $titleNorm, string $phraseNorm): bool
    {
        if ($titleNorm === '' || $phraseNorm === '' || $titleNorm === $phraseNorm) {
            return false;
        }

        if (str_starts_with($titleNorm, $phraseNorm.' ')) {
            return true;
        }

        if (str_ends_with($titleNorm, ' '.$phraseNorm)) {
            return true;
        }

        return false;
    }

    private function weakTokenOverlapScore(string $phraseNorm, string $titleNorm, string $slugNorm): int
    {
        $phraseTokens = preg_split('/\s+/u', $phraseNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($phraseTokens === []) {
            return 0;
        }

        $haystackTokens = array_values(array_unique(array_merge(
            preg_split('/\s+/u', $titleNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            preg_split('/\s+/u', $slugNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        )));
        if ($haystackTokens === []) {
            return 0;
        }

        $haystackSet = array_fill_keys($haystackTokens, true);
        $overlap = 0;
        foreach ($phraseTokens as $token) {
            if (isset($haystackSet[$token])) {
                $overlap++;
            }
        }

        if ($overlap <= 0) {
            return 0;
        }

        // Partial token hits never clear Advanced fallbackMinAccept (55).
        return min(self::SCORE_WEAK_TOKEN_OVERLAP, 20 + ($overlap * 5));
    }
}
