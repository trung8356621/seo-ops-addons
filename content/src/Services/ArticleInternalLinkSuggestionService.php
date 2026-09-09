<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\KeywordMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\LinkSuggestionStopPhraseFilter;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use App\Support\RuntimeLogger;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;

final class ArticleInternalLinkSuggestionService
{
    /**
     * Request-scoped cache: same (article, content, links) collectCandidates() call
     * repeated by suggest()/suggestCatalog()/suggestExternal()/suggestExternalCatalog()
     * within one request only pays the query cost once (Phase 2 perf).
     *
     * @var array<string, array{internal: list<array<string, mixed>>, external: list<array<string, mixed>>}>
     */
    private array $candidatesCache = [];

    /**
     * Request-scoped cache of the site keyword catalog, keyed by site id + excluded
     * keyword ids — avoids re-running the full `Keyword::forSite()` scan per call.
     *
     * @var array<string, \Illuminate\Support\Collection<int, Keyword>>
     */
    private array $keywordsBySite = [];

    /** @var array<string, mixed> */
    private array $lastDebug = [];

    public function __construct(
        private readonly KeywordLinkTargetResolver $linkTargetResolver,
        private readonly ArticleLinkSuggestionCandidateRetriever $candidateRetriever,
        private readonly ArticleLinkSuggestionSearchTermsBuilder $termsBuilder,
        private readonly ArticleLinkSuggestionContentKeywordFallback $contentKeywordFallback,
        private readonly ArticleInternalLinkProductCatMatcher $productCatMatcher,
        private readonly ArticleInternalLinkPriorityMerger $priorityMerger,
        private readonly ArticleInternalLinkTopicMembership $topicMembership,
        private readonly ArticleInternalLinkPipeline $pipeline,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function lastDebug(): array
    {
        return $this->lastDebug;
    }

    /**
     * Chỉ chạy content-keyword fallback (nút debug «Tạo gợi ý bổ sung»).
     *
     * @param  list<array<string, mixed>>  $existingInternal
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return array{
     *     internal: list<array<string, mixed>>,
     *     internal_catalog: list<array<string, mixed>>,
     *     external: list<array<string, mixed>>,
     *     external_catalog: list<array<string, mixed>>,
     *     debug?: array<string, mixed>
     * }
     */
    public function suggestFallbackSupplement(
        SeoArticle $article,
        string $content,
        array $existingInternal,
        array $internalLinks,
        array $externalLinks = [],
    ): array {
        // «Tìm thêm gợi ý» = cùng pipeline staged, loại URL/anchor đã hiện.
        $excludeAsLinks = [];
        foreach ($existingInternal as $row) {
            if (! is_array($row)) {
                continue;
            }
            $href = trim((string) ($row['href'] ?? $row['target_url'] ?? ''));
            $text = trim((string) ($row['text'] ?? ''));
            if ($href === '' && $text === '') {
                continue;
            }
            $excludeAsLinks[] = [
                'href' => $href,
                'target_url' => $href,
                'text' => $text,
            ];
        }

        $mergedLinked = array_merge($internalLinks, $excludeAsLinks);
        $candidates = $this->collectCandidates($article, $content, $mergedLinked, $externalLinks);

        $existingUrlKeys = [];
        foreach ($excludeAsLinks as $row) {
            $norm = SeoSuggestionUrlNormalizer::normalize((string) ($row['href'] ?? ''));
            if ($norm !== '') {
                $existingUrlKeys[$norm] = true;
            }
        }

        $fresh = [];
        foreach ($candidates['internal'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $norm = SeoSuggestionUrlNormalizer::normalize((string) ($row['href'] ?? ''));
            if ($norm !== '' && isset($existingUrlKeys[$norm])) {
                continue;
            }
            $fresh[] = $row;
        }

        $this->lastDebug = array_merge($this->lastDebug, [
            'entry' => 'suggestMoreSamePipeline',
            'existing_excluded' => count($existingUrlKeys),
            'fresh_internal_count' => count($fresh),
        ]);
        $this->logDebug('find_more', $this->lastDebug);

        $maxDisplay = $this->limit('max_display_internal', 10);

        return [
            'internal' => array_slice($fresh, 0, $maxDisplay),
            'internal_catalog' => $fresh,
            'external' => [],
            'external_catalog' => [],
            'internal_link_catalog' => is_array($this->lastDebug['internal_link_catalog'] ?? null)
                ? $this->lastDebug['internal_link_catalog']
                : [],
            'debug' => LinkSuggestionStopPhraseFilter::debugEnabled() ? $this->lastDebug : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return array{
     *     internal: list<array<string, mixed>>,
     *     internal_catalog: list<array<string, mixed>>,
     *     external: list<array<string, mixed>>,
     *     external_catalog: list<array<string, mixed>>
     * }
     */
    public function suggestBundle(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        $candidates = $this->collectCandidates($article, $content, $internalLinks, $externalLinks);

        $internalCatalog = $candidates['internal'];
        $externalCatalog = $candidates['external'];
        $maxInternalLinks = $this->limit('max_internal_links', 10);
        $maxDisplayInternal = $this->limit('max_display_internal', 10);
        $maxDisplayExternal = $this->limit('max_display_external', 10);

        $payload = [
            'internal' => count($internalLinks) >= $maxInternalLinks
                ? []
                : array_slice($internalCatalog, 0, $maxDisplayInternal),
            'internal_catalog' => $internalCatalog,
            'external' => array_slice($externalCatalog, 0, $maxDisplayExternal),
            'external_catalog' => $externalCatalog,
            // Always expose catalog counters — required to verify product_cat coverage.
            'internal_link_catalog' => is_array($this->lastDebug['internal_link_catalog'] ?? null)
                ? $this->lastDebug['internal_link_catalog']
                : [],
        ];

        if (LinkSuggestionStopPhraseFilter::debugEnabled()) {
            $payload['debug'] = $this->lastDebug;
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array<string, mixed>>
     */
    public function suggest(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        if (count($internalLinks) >= $this->limit('max_internal_links', 10)) {
            return [];
        }

        return array_slice(
            $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['internal'],
            0,
            $this->limit('max_display_internal', 10),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array<string, mixed>>
     */
    public function suggestCatalog(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        return $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['internal'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array<string, mixed>>
     */
    public function suggestExternal(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        return array_slice(
            $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['external'],
            0,
            $this->limit('max_display_external', 10),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array<string, mixed>>
     */
    public function suggestExternalCatalog(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        return $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['external'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return array{
     *     internal: list<array<string, mixed>>,
     *     external: list<array<string, mixed>>
     * }
     */
    private function collectCandidates(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        $empty = ['internal' => [], 'external' => []];
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return $empty;
        }

        $plainText = $this->plainTextFromHtml($content);
        if ($plainText === '') {
            $this->lastDebug = [
                'entry' => 'collectCandidates',
                'article_id' => (int) $article->id,
                'skip_reason' => 'empty_plain_text_after_strip',
                'content_length' => mb_strlen($content),
            ];
            $this->logDebug('empty_content', $this->lastDebug);

            return $empty;
        }

        $cacheKey = $this->candidatesCacheKey((int) $article->id, $content, $internalLinks, $externalLinks);
        if (isset($this->candidatesCache[$cacheKey])) {
            return $this->candidatesCache[$cacheKey];
        }

        $result = $this->pipeline->collect($article, $content, $internalLinks, $externalLinks);
        $this->lastDebug = $this->pipeline->lastDebug();
        $this->logDebug('collect_done', $this->lastDebug);

        $this->candidatesCache[$cacheKey] = $result;

        return $result;
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

    /**
     * Secondary keywords gắn bài (main_article_id) nếu xuất hiện trong content.
     *
     * @return list<string>
     */
    private function secondaryKeywordsAppearingInContent(SeoArticle $article, string $plainText): array
    {
        $ids = $this->mainKeywordIdsForArticle((int) $article->id);
        if ($ids === []) {
            return [];
        }

        $phrases = Keyword::query()
            ->whereIn('id', $ids)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->pluck('phrase')
            ->all();

        $out = [];
        foreach ($phrases as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '' || ! $this->textContainsPhrase($plainText, $phrase)) {
                continue;
            }
            $out[] = $phrase;
        }

        return $out;
    }

    private function limit(string $key, int $default): int
    {
        return max(1, (int) config('seo-content-ai.link_suggestions.'.$key, $default));
    }

    /**
     * @return list<int>
     */
    private function mainKeywordIdsForArticle(int $articleId): array
    {
        if ($articleId <= 0) {
            return [];
        }

        return KeywordMeta::query()
            ->where('meta_key', KeywordMetaKey::MainArticleId->value)
            ->where('meta_value', (string) $articleId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
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

        $permalink = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_permalink')
            ?->meta_value ?? ''));
        if ($permalink !== '') {
            $urls[] = $permalink;
        }

        return $urls;
    }

    private function resolveTargetArticleId(int $siteId, string $href, SeoArticle $current): int
    {
        $target = $this->linkTargetResolver->resolveArticleFromUrl($siteId, $href, $current);

        return $target instanceof SeoArticle ? (int) $target->id : 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     */
    private function candidatesCacheKey(int $articleId, string $content, array $internalLinks, array $externalLinks): string
    {
        return $articleId.':'.md5($content).':'.md5(serialize($internalLinks)).':'.md5(serialize($externalLinks));
    }

    /**
     * @param  list<int>  $excludeKeywordIds
     * @return \Illuminate\Support\Collection<int, Keyword>
     */
    private function keywordsForSite(int $siteId, array $excludeKeywordIds): \Illuminate\Support\Collection
    {
        $cacheKey = $siteId.':'.implode(',', $excludeKeywordIds);
        if (isset($this->keywordsBySite[$cacheKey])) {
            return $this->keywordsBySite[$cacheKey];
        }

        $keywordsQuery = Keyword::query()
            ->forSite($siteId)
            ->where('type', Keyword::TYPE_NORMAL)
            ->where('review_status', KeywordReviewStatus::Active->value)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->whereDoesntHave(
                'metas',
                static function ($meta): void {
                    $meta->where(
                        'meta_key',
                        \Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey::SeoHidden->value,
                    )->where('meta_value', '1');
                },
            )
            ->orderByRaw('CHAR_LENGTH(phrase) DESC')
            // Cap hot-path scan — long-tail phrases beyond this rarely match content.
            ->limit(800);

        if ($excludeKeywordIds !== []) {
            $keywordsQuery->whereNotIn('id', $excludeKeywordIds);
        }

        // Eager linkMaps để resolveForKeyword không N+1 theo keyword.
        $keywords = $keywordsQuery
            ->with([
                'linkMaps' => static fn ($query) => $query->whereHas(
                    'sourceArticle',
                    static fn ($articleQuery) => $articleQuery->where('site_id', $siteId),
                )->with(['targetArticle:id,site_id,title,slug', 'sourceArticle:id,site_id']),
            ])
            ->get(['id', 'phrase', 'type']);

        $this->keywordsBySite[$cacheKey] = $keywords;

        return $keywords;
    }

    /**
     * @return 'internal'|'external'|null null = bỏ (tel/mail/… hoặc thiếu URL)
     */
    private function suggestionBucketForHref(string $href, string $siteDomain, int $siteId): ?string
    {
        if ($href === '' || SeoSuggestionUrlNormalizer::isPlaceholder($href)) {
            // Không còn gợi ý keyword-only / placeholder.
            return null;
        }

        if ($this->isSpecialSchemeOrContactHref($href)) {
            return null;
        }

        if ($this->isInternalHrefForSite($href, $siteDomain, $siteId)) {
            return 'internal';
        }

        return 'external';
    }

    private function isInternalHrefForSite(string $href, string $siteDomain, int $siteId): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }

        if (str_starts_with($href, '/')) {
            return true;
        }

        $host = SeoSuggestionUrlNormalizer::host($href);
        if ($host !== '' && $siteDomain !== '' && $host === $siteDomain) {
            return true;
        }

        $targetArticle = $this->linkTargetResolver->resolveArticleFromUrl($siteId, $href);
        if ($targetArticle instanceof SeoArticle && (int) ($targetArticle->site_id ?? 0) === $siteId) {
            return true;
        }

        return false;
    }

    private function isSpecialSchemeOrContactHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }

        $lower = mb_strtolower($href);
        if (str_starts_with($lower, 'javascript:')) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if (is_string($scheme) && $scheme !== '') {
            return in_array(strtolower($scheme), [
                'tel',
                'mailto',
                'sms',
                'fax',
                'callto',
                'geo',
                'skype',
                'whatsapp',
                'viber',
                'data',
                'cid',
            ], true);
        }

        if (preg_match('/^[+]?[\d\s().-]{6,}$/u', $href) === 1) {
            return true;
        }

        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $href) === 1) {
            return true;
        }

        return false;
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @return array{labels: list<string>, hrefs: list<string>}
     */
    private function collectLinkedContext(array $internalLinks): array
    {
        $labels = [];
        $hrefs = [];

        foreach ($internalLinks as $link) {
            $text = trim((string) ($link['text'] ?? ''));
            if ($text !== '') {
                $labels[] = mb_strtolower($text);
            }

            $href = trim((string) ($link['href'] ?? ''));
            if ($href === '') {
                continue;
            }

            $normalizedHref = SeoSuggestionUrlNormalizer::normalize($href);
            if ($normalizedHref !== '') {
                $hrefs[] = $normalizedHref;
            }

            $path = parse_url($href, PHP_URL_PATH);
            if (! is_string($path) || $path === '') {
                continue;
            }

            $slug = basename($path);
            if ($slug !== '' && $slug !== '/') {
                $labels[] = mb_strtolower(str_replace(['-', '_'], ' ', $slug));
            }
        }

        return [
            'labels' => array_values(array_unique($labels)),
            'hrefs' => array_values(array_unique($hrefs)),
        ];
    }

    /**
     * @param  list<string>  $linkedHrefs
     */
    private function isHrefAlreadyLinked(string $href, array $linkedHrefs): bool
    {
        $normalized = SeoSuggestionUrlNormalizer::normalize($href);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $linkedHrefs, true);
    }

    /**
     * @param  list<string>  $linkedLabels
     */
    private function isAlreadyLinked(string $phrase, array $linkedLabels): bool
    {
        $phraseLower = mb_strtolower(trim($phrase));
        if ($phraseLower === '') {
            return true;
        }

        foreach ($linkedLabels as $label) {
            if ($label === $phraseLower) {
                return true;
            }

            if (mb_stripos($label, $phraseLower) !== false || mb_stripos($phraseLower, $label) !== false) {
                return true;
            }
        }

        return false;
    }

    private function textContainsPhrase(string $text, string $phrase): bool
    {
        return KeywordPhraseMatcher::contains($text, $phrase);
    }

    /**
     * @return list<string>
     */
    private function ownArticlePhraseBlocklist(SeoArticle $article): array
    {
        $phrases = [];

        $focus = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article);
        if ($focus !== null) {
            $normalized = $this->normalizePhrase($focus);
            if ($normalized !== '') {
                $phrases[] = $normalized;
            }
        }

        $title = $this->normalizePhrase((string) ($article->title ?? ''));
        if ($title !== '') {
            $phrases[] = $title;
        }

        return array_values(array_unique($phrases));
    }

    /**
     * @param  list<string>  $ownArticlePhrases
     */
    private function isOwnArticlePhrase(string $phrase, array $ownArticlePhrases): bool
    {
        $normalized = $this->normalizePhrase($phrase);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $ownArticlePhrases, true);
    }

    private function normalizePhrase(string $phrase): string
    {
        $phrase = mb_strtolower(trim($phrase));
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? '';

        return trim($phrase);
    }
}
