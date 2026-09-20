<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\KeywordMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use Omnichannel\Addons\Content\Support\InternalLinkDestinationGate;
use Illuminate\Support\Str;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;

/**
 * Thu thập + chấm điểm article candidates cho internal link suggestions.
 * Không N+1: load index site 1 lần / request, score trong memory.
 */
final class ArticleLinkSuggestionCandidateRetriever
{
    public const REASON_TITLE_EXACT = 'title_exact';

    public const REASON_TITLE_MATCH = 'title_match';

    public const REASON_KEYWORD_MATCH = 'keyword_match';

    public const REASON_FOCUS_KEYWORD = 'focus_keyword';

    public const REASON_SLUG_MATCH = 'slug_match';

    public const REASON_HEADING_MATCH = 'heading_match';

    public const REASON_META_MATCH = 'meta_match';

    public const REASON_CONTENT_MATCH = 'content_match';

    public const REASON_SAME_CLUSTER = 'same_cluster';

    public const REASON_CONTEXT_MATCH = 'context_match';

    /** @var array<string, list<array<string, mixed>>> */
    private array $siteIndexCache = [];

    public function __construct(
        private readonly KeywordLinkTargetResolver $linkTargetResolver,
        private readonly ArticleLinkSuggestionSearchTermsBuilder $termsBuilder,
    ) {}

    /**
     * @param  list<array{keyword_id: int, phrase: string, context?: array<string, mixed>}>  $anchors
     * @param  list<string>  $alreadyLinkedNormalizedUrls
     * @return array<int, array{
     *     keyword_id: int,
     *     phrase: string,
     *     target_article_id: int,
     *     href: string,
     *     url: ?string,
     *     destination_resolved: bool,
     *     score: int,
     *     match_reason: string,
     *     candidates_count: int
     * }>
     */
    public function resolveBestForAnchors(
        SeoArticle $currentArticle,
        array $anchors,
        array $alreadyLinkedNormalizedUrls = [],
    ): array {
        if ($anchors === []) {
            return [];
        }

        $siteId = (int) ($currentArticle->site_id ?? 0);
        if ($siteId <= 0) {
            return [];
        }

        $maxCandidates = (int) config('seo-content-ai.link_suggestions.max_candidates', 50);
        $minScore = LinkSuggestionScoreScale::primaryMinAccept();
        $language = (string) ($currentArticle->language ?? '') ?: 'vi';
        $index = $this->siteArticleIndex($siteId, (int) $currentArticle->id, $language);

        $currentUrls = $this->currentArticleUrls($currentArticle);
        $validationContext = [
            'site_domain' => (string) ($currentArticle->site?->domain ?? ''),
            'site_id' => $siteId,
            'current_article_id' => (int) $currentArticle->id,
            'current_urls' => $currentUrls,
            'current_slug' => trim((string) ($currentArticle->slug ?? ''), '/'),
        ];

        $out = [];
        foreach ($anchors as $anchor) {
            $keywordId = (int) ($anchor['keyword_id'] ?? 0);
            $phrase = trim((string) ($anchor['phrase'] ?? ''));
            if ($keywordId <= 0 || $phrase === '') {
                continue;
            }

            if (! LinkSuggestionValidator::isUsableAnchorCandidate(['text' => $phrase])) {
                continue;
            }

            $terms = $this->termsBuilder->build($phrase, $anchor['context'] ?? []);
            if ($terms === []) {
                continue;
            }

            $scored = [];
            foreach ($index as $candidate) {
                $scorePayload = $this->scoreCandidate($candidate, $phrase, $terms, $anchor['context'] ?? []);
                if ($scorePayload['score'] < $minScore) {
                    continue;
                }

                $url = trim((string) ($candidate['url'] ?? ''));
                $gate = InternalLinkDestinationGate::evaluate(
                    $url,
                    (bool) ($candidate['destination_resolved'] ?? false),
                    [
                        'text' => $phrase,
                        'target_article_id' => (int) $candidate['id'],
                        'bucket' => 'internal',
                    ],
                    $validationContext,
                    $alreadyLinkedNormalizedUrls,
                );

                $row = [
                    'keyword_id' => $keywordId,
                    'phrase' => $phrase,
                    'target_article_id' => (int) $candidate['id'],
                    'href' => $gate['href'],
                    'url' => $gate['url'],
                    'destination_resolved' => $gate['destination_resolved'],
                    'score' => $scorePayload['score'],
                    'match_reason' => $scorePayload['reason'],
                ];
                if ($gate['destination_reject_reason'] !== null) {
                    $row['destination_reject_reason'] = $gate['destination_reject_reason'];
                }
                $scored[] = $row;
            }

            usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $scored = array_slice($scored, 0, max(1, $maxCandidates));
            if ($scored === []) {
                continue;
            }

            $best = $scored[0];
            $best['candidates_count'] = count($scored);
            $out[$keywordId] = $best;
        }

        return $out;
    }

    /**
     * Placeable / insert-now search — only resolved authoritative destinations.
     *
     * @return list<array{id: int, title: string, url: string, score: int, match_reason: string}>
     */
    public function searchRanked(
        SeoArticle $currentArticle,
        string $query,
        int $limit = 15,
    ): array {
        $siteId = (int) ($currentArticle->site_id ?? 0);
        if ($siteId <= 0) {
            return [];
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $terms = $this->termsBuilder->build($query, [
            'title' => (string) ($currentArticle->title ?? ''),
            'focus_keyword' => '',
            'paragraph_context' => $query,
        ]);
        $language = (string) ($currentArticle->language ?? '') ?: 'vi';
        $index = $this->siteArticleIndex($siteId, (int) $currentArticle->id, $language);
        $minScore = LinkSuggestionScoreScale::primaryMinAccept();
        $limit = max(1, min(30, $limit));

        $scored = [];
        foreach ($index as $candidate) {
            if (! (bool) ($candidate['destination_resolved'] ?? false)) {
                continue;
            }

            $scorePayload = $this->scoreCandidate($candidate, $query, $terms, []);
            if ($scorePayload['score'] < $minScore) {
                continue;
            }

            $url = trim((string) ($candidate['url'] ?? ''));
            if ($url === '' || ! SeoSuggestionUrlNormalizer::isParsableTarget($url)) {
                continue;
            }

            $scored[] = [
                'id' => (int) $candidate['id'],
                'title' => (string) $candidate['title'],
                'url' => $url,
                'score' => $scorePayload['score'],
                'match_reason' => $scorePayload['reason'],
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Semantic article index for Link Assistant scoring.
     * Unsynced articles stay in the pool with url=null / destination_resolved=false.
     * Placeable searchRanked() filters to destination_resolved=true.
     *
     * @return list<array{
     *     id: int,
     *     title: string,
     *     title_norm: string,
     *     title_ascii: string,
     *     slug: string,
     *     slug_norm: string,
     *     focus_norm: string,
     *     secondary_norms: list<string>,
     *     heading_norms: list<string>,
     *     meta_title_norm: string,
     *     meta_desc_norm: string,
     *     tag_norms: list<string>,
     *     url: ?string,
     *     destination_resolved: bool
     * }>
     */
    private function siteArticleIndex(int $siteId, int $excludeArticleId, string $language): array
    {
        $cacheKey = $siteId.':'.$excludeArticleId.':'.$language;
        if (isset($this->siteIndexCache[$cacheKey])) {
            return $this->siteIndexCache[$cacheKey];
        }

        // Language must be in the SQL pool — a shared 600-row all-language cache lets EN
        // crowd out VI destinations on bilingual sites (e.g. site 6: 526 EN vs 74 VI in top 600).
        $persistentKey = WordPressInternalLinkTargetPolicy::siteIndexCacheKey($siteId, $language);
        /** @var list<array<string, mixed>> $fullIndex */
        $fullIndex = Cache::remember($persistentKey, 90, function () use ($siteId, $language): array {
            $articles = SeoArticle::query()
                ->where('site_id', $siteId)
                ->where('language', $language)
                ->notContentArchived()
                ->orderByDesc('id')
                ->limit(600)
                ->with([
                    'site:id,domain',
                    'wordpressLink',
                    'articleMetas' => static function ($query): void {
                        $query->whereIn('meta_key', [
                            'wp_permalink',
                            'seo_focus_keyword',
                            'seo_meta_description',
                            'seo_meta_title',
                            '_yoast_wpseo_title',
                            '_yoast_wpseo_metadesc',
                            'rank_math_title',
                            'rank_math_description',
                            'rank_math_focus_keyword',
                        ]);
                    },
                    'headings:id,article_id,heading_text,level',
                ])
                ->get(['id', 'title', 'slug', 'site_id', 'language']);

            $articleIds = $articles->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $keywordPhrasesByArticle = $this->keywordPhrasesByArticleId($articleIds);

            $index = [];
            foreach ($articles as $article) {
                if (! $article instanceof SeoArticle) {
                    continue;
                }

                $title = trim((string) ($article->title ?? ''));
                if ($title === '') {
                    continue;
                }

                $url = trim((string) ($this->linkTargetResolver->resolveArticlePublicUrl($article) ?? ''));
                $destinationResolved = $url !== '' && SeoSuggestionUrlNormalizer::isParsableTarget($url);
                // Never store "#" / synthetic URLs in the index — null when unresolved.
                $canonicalUrl = $destinationResolved ? $url : null;

                $metas = $article->articleMetas ?? collect();
                $focus = trim((string) (
                    $metas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value
                    ?? $metas->firstWhere('meta_key', 'rank_math_focus_keyword')?->meta_value
                    ?? ''
                ));
                $metaTitle = trim((string) (
                    $metas->firstWhere('meta_key', 'seo_meta_title')?->meta_value
                    ?? $metas->firstWhere('meta_key', '_yoast_wpseo_title')?->meta_value
                    ?? $metas->firstWhere('meta_key', 'rank_math_title')?->meta_value
                    ?? ''
                ));
                $metaDesc = trim((string) (
                    $metas->firstWhere('meta_key', 'seo_meta_description')?->meta_value
                    ?? $metas->firstWhere('meta_key', '_yoast_wpseo_metadesc')?->meta_value
                    ?? $metas->firstWhere('meta_key', 'rank_math_description')?->meta_value
                    ?? ''
                ));

                $headingNorms = [];
                foreach ($article->headings ?? [] as $heading) {
                    if (! $heading instanceof SeoArticleHeading) {
                        continue;
                    }
                    $norm = KeywordPhraseMatcher::normalize((string) ($heading->heading_text ?? ''));
                    if ($norm !== '') {
                        $headingNorms[] = $norm;
                    }
                }

                $secondary = $keywordPhrasesByArticle[(int) $article->id] ?? [];
                $slug = trim((string) ($article->slug ?? ''), '/');

                $index[] = [
                    'id' => (int) $article->id,
                    'title' => $title,
                    'title_norm' => KeywordPhraseMatcher::normalize($title),
                    'title_ascii' => $this->toAscii($title),
                    'slug' => $slug,
                    'slug_norm' => KeywordPhraseMatcher::normalize(str_replace(['-', '_'], ' ', $slug)),
                    'focus_norm' => KeywordPhraseMatcher::normalize($focus),
                    'secondary_norms' => array_values(array_unique(array_map(
                        static fn (string $p): string => KeywordPhraseMatcher::normalize($p),
                        $secondary,
                    ))),
                    'meta_title_norm' => KeywordPhraseMatcher::normalize($metaTitle),
                    'meta_desc_norm' => KeywordPhraseMatcher::normalize($metaDesc),
                    'heading_norms' => $headingNorms,
                    'tag_norms' => [],
                    'url' => $canonicalUrl,
                    'destination_resolved' => $destinationResolved,
                    'language' => (string) ($article->language ?? ''),
                ];
            }

            return $index;
        });

        // Hard gate: candidate pool is site + language scoped, never cross-language.
        $index = array_values(array_filter(
            $fullIndex,
            static fn (array $row): bool => (int) ($row['id'] ?? 0) !== $excludeArticleId
                && (string) ($row['language'] ?? '') === $language,
        ));

        $this->siteIndexCache[$cacheKey] = $index;

        return $index;
    }

    /**
     * @param  list<int>  $articleIds
     * @return array<int, list<string>>
     */
    private function keywordPhrasesByArticleId(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        $rows = KeywordMeta::query()
            ->where('meta_key', KeywordMetaKey::MainArticleId->value)
            ->whereIn('meta_value', array_map(static fn (int $id): string => (string) $id, $articleIds))
            ->get(['keyword_id', 'meta_value']);

        if ($rows->isEmpty()) {
            return [];
        }

        $keywordIds = $rows->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->unique()->all();
        $phrases = Keyword::query()
            ->whereIn('id', $keywordIds)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->pluck('phrase', 'id');

        $map = [];
        foreach ($rows as $row) {
            $articleId = (int) $row->meta_value;
            $phrase = trim((string) ($phrases[(int) $row->keyword_id] ?? ''));
            if ($articleId <= 0 || $phrase === '') {
                continue;
            }
            $map[$articleId] ??= [];
            $map[$articleId][] = $phrase;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<string>  $terms
     * @param  array<string, mixed>  $context
     * @return array{score: int, reason: string}
     */
    private function scoreCandidate(array $candidate, string $phrase, array $terms, array $context): array
    {
        $phraseNorm = KeywordPhraseMatcher::normalize($phrase);
        $phraseAscii = $this->toAscii($phrase);
        $best = ['score' => 0, 'reason' => self::REASON_CONTENT_MATCH];

        $titleNorm = (string) ($candidate['title_norm'] ?? '');
        $titleAscii = (string) ($candidate['title_ascii'] ?? '');
        $focusNorm = (string) ($candidate['focus_norm'] ?? '');
        $slugNorm = (string) ($candidate['slug_norm'] ?? '');

        if ($phraseNorm !== '' && ($titleNorm === $phraseNorm || $titleAscii === $phraseAscii)) {
            return ['score' => 100, 'reason' => self::REASON_TITLE_EXACT];
        }

        if ($phraseNorm !== '' && $focusNorm === $phraseNorm) {
            $this->bump($best, 95, self::REASON_FOCUS_KEYWORD);
        }

        if ($phraseNorm !== '' && $titleNorm !== '' && str_contains($titleNorm, $phraseNorm)) {
            $this->bump($best, 80, self::REASON_TITLE_MATCH);
        }

        if ($phraseNorm !== '' && $slugNorm !== '') {
            if (str_contains($slugNorm, $phraseNorm)) {
                $this->bump($best, 75, self::REASON_SLUG_MATCH);
            } elseif (str_contains($phraseNorm, $slugNorm) && $this->isMeaningfulSubphrase($slugNorm)) {
                // Anchor-contains-slug direction only counts when the slug itself is a real
                // phrase — a short generic slug segment must not "match" any longer anchor.
                $this->bump($best, 75, self::REASON_SLUG_MATCH);
            }
        }

        foreach (($candidate['secondary_norms'] ?? []) as $secondary) {
            if ($secondary === '') {
                continue;
            }
            if ($secondary === $phraseNorm || str_contains($secondary, $phraseNorm)) {
                $this->bump($best, 70, self::REASON_KEYWORD_MATCH);
                break;
            }
            // Anchor-contains-keyword direction only counts when the keyword itself is a real
            // phrase — a short generic single word must not "match" any longer anchor.
            if (str_contains($phraseNorm, $secondary) && $this->isMeaningfulSubphrase($secondary)) {
                $this->bump($best, 70, self::REASON_KEYWORD_MATCH);
                break;
            }
        }


        foreach (($candidate['heading_norms'] ?? []) as $heading) {
            if ($phraseNorm !== '' && $heading !== '' && str_contains($heading, $phraseNorm)) {
                $this->bump($best, 65, self::REASON_HEADING_MATCH);
                break;
            }
        }

        $metaBlob = trim(($candidate['meta_title_norm'] ?? '').' '.($candidate['meta_desc_norm'] ?? ''));
        if ($phraseNorm !== '' && $metaBlob !== '' && str_contains($metaBlob, $phraseNorm)) {
            $this->bump($best, 35, self::REASON_META_MATCH);
        }

        $contextTerms = [];
        $paragraph = KeywordPhraseMatcher::normalize((string) ($context['paragraph_context'] ?? ''));
        if ($paragraph !== '') {
            foreach ($terms as $term) {
                if (str_contains($term, ' ') && str_contains($paragraph, $term)) {
                    $contextTerms[] = $term;
                }
            }
        }

        foreach ($terms as $term) {
            if ($term === '' || $term === $phraseNorm) {
                continue;
            }

            $termScore = str_contains($term, ' ') ? 48 : 28;
            $reason = in_array($term, $contextTerms, true) ? self::REASON_CONTEXT_MATCH : self::REASON_CONTENT_MATCH;

            if ($titleNorm !== '' && str_contains($titleNorm, $term)) {
                $this->bump($best, $termScore + 10, $reason === self::REASON_CONTEXT_MATCH ? self::REASON_CONTEXT_MATCH : self::REASON_TITLE_MATCH);
            }
            if ($focusNorm !== '' && str_contains($focusNorm, $term)) {
                $this->bump($best, $termScore + 8, self::REASON_FOCUS_KEYWORD);
            }
            if ($slugNorm !== '' && str_contains($slugNorm, $term)) {
                $this->bump($best, $termScore + 5, self::REASON_SLUG_MATCH);
            }
            foreach (($candidate['heading_norms'] ?? []) as $heading) {
                if ($heading !== '' && str_contains($heading, $term)) {
                    $this->bump($best, $termScore, self::REASON_HEADING_MATCH);
                    break;
                }
            }
            foreach (($candidate['secondary_norms'] ?? []) as $secondary) {
                if ($secondary !== '' && str_contains($secondary, $term)) {
                    $this->bump($best, $termScore + 6, self::REASON_KEYWORD_MATCH);
                    break;
                }
            }
        }

        return $best;
    }

    /**
     * @param  array{score: int, reason: string}  $best
     */
    private function bump(array &$best, int $score, string $reason): void
    {
        if ($score > $best['score']) {
            $best = ['score' => $score, 'reason' => $reason];
        }
    }

    /**
     * A short generic single word (e.g. "vải") must not qualify as a strong match just
     * because it happens to be a substring of a longer anchor phrase. Require the field
     * to be a real multi-word phrase, or long enough to not be a common bare noun.
     */
    private function isMeaningfulSubphrase(string $normalized): bool
    {
        if (str_contains($normalized, ' ')) {
            return true;
        }

        return mb_strlen($normalized) >= 6;
    }

    /**
     * @return list<string>
     */
    private function currentArticleUrls(SeoArticle $article): array
    {
        $urls = [];
        $public = trim((string) ($this->linkTargetResolver->resolveArticlePublicUrl($article) ?? ''));
        if ($public !== '') {
            $urls[] = $public;
        }

        $article->loadMissing('articleMetas');
        $permalink = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_permalink')
            ?->meta_value ?? ''));
        if ($permalink !== '') {
            $urls[] = $permalink;
        }

        return $urls;
    }

    private function toAscii(string $text): string
    {
        $ascii = Str::ascii(KeywordPhraseMatcher::normalize($text), 'vi');
        $ascii = mb_strtolower(trim($ascii), 'UTF-8');
        $ascii = preg_replace('/[^a-z0-9\s]+/u', ' ', $ascii) ?? $ascii;

        return trim(preg_replace('/\s+/u', ' ', $ascii) ?? $ascii);
    }
}
