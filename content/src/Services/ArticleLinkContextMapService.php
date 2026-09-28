<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapDestinationKind;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Omnichannel\Addons\Seo\Support\LinkDestinationClassifier;
use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
use Omnichannel\Addons\SearchFoundation\Support\SeoLinkMapExternalUrlNormalizer;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use App\Models\Site;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\SearchFoundation\Services\KeywordQualityFlagService;
use Omnichannel\Addons\Seo\Services\LinkMapStatusAuditService;

/**
 * Trích xuất link trong HTML bài viết kèm ngữ cảnh, lưu vào seo_link_maps.
 */
final class ArticleLinkContextMapService
{
    public function __construct(
        private readonly CtaKeywordBlacklistFilter $ctaKeywordBlacklistFilter,
        private readonly KeywordLinkTargetResolver $linkTargetResolver,
        private readonly LinkMapStatusAuditService $linkStatusAudit,
        private readonly KeywordQualityFlagService $qualityFlags,
    ) {}

    /**
     * @param  array<int, string>  $excludeAnchorPhrases
     */
    public function resyncArticle(
        SeoArticle $article,
        ?string $contentOverride = null,
        array $excludeAnchorPhrases = [],
    ): int {
        if (! $article->countsTowardSeoScore()) {
            return 0;
        }

        $article->loadMissing(['site', 'wordpressLink']);
        $content = $contentOverride ?? $this->resolveArticleContent($article);
        if (trim($content) === '') {
            // WP-backed + body null = WP is canonical; do not wipe existing link maps.
            if ($contentOverride === null && (int) ($article->wordpressLink?->wp_post_id ?? 0) > 0) {
                return 0;
            }

            SeoLinkMap::query()->where('source_article_id', $article->id)->delete();

            return 0;
        }

        $anchors = $this->extractAnchorsWithContext($content);
        $siteId = (int) ($article->site_id ?? 0);

        SeoLinkMap::query()->where('source_article_id', $article->id)->delete();

        $saved = 0;
        $touchedKeywordIds = [];

        foreach ($anchors as $anchor) {
            $anchorText = Keyword::preparePhraseForStorage((string) ($anchor['anchor_text'] ?? ''));
            $href = trim((string) ($anchor['href'] ?? ''));

            if ($anchorText === '' || $href === '') {
                continue;
            }

            if ($this->shouldExcludeAnchorPhrase($anchorText, $excludeAnchorPhrases)) {
                continue;
            }

            // PHASE 1B: Classify destination BEFORE anchor filter and Keyword creation.
            // This prevents CTA/social/contact destinations from ever creating Keywords.
            $targetArticle = $this->linkTargetResolver->resolveTargetArticleForLinkMap(
                $siteId,
                $href,
                $article,
            );
            $classification = $this->classifyDestinationEarly($href, $siteId, $targetArticle);

            // CTA/social/contact destinations must NOT create semantic Keywords.
            // Persist the raw link fact only (keyword_id=null is allowed by migration).
            if ($classification['is_cta']) {
                $this->persistNonSemanticLinkFact($article, $href, $anchorText, $anchor, $classification);
                $saved++;
                continue;
            }

            // Anchor text quality filter (applies only to semantic-eligible links).
            if (
                ! InternalAnchorKeywordFilter::isUsableAnchorPhrase($anchorText, $href)
                || $this->ctaKeywordBlacklistFilter->isBlocked($anchorText)
            ) {
                continue;
            }

            $keyword = Keyword::query()
                ->whereRaw('LOWER(phrase) = ?', [mb_strtolower($anchorText)])
                ->first();

            if (! $keyword instanceof Keyword) {
                $keyword = Keyword::query()->create([
                    'phrase' => $anchorText,
                    'type' => Keyword::TYPE_NORMAL,
                    'parent_id' => null,
                ]);
            }

            $linkMap = SeoLinkMap::query()->create([
                'keyword_id' => (int) $keyword->id,
                'source_article_id' => (int) $article->id,
                'target_article_id' => $classification['target_article_id'],
                'target_external_url' => $classification['target_external_url'],
                'anchor_text' => $anchorText,
                'context_before' => $anchor['context_before'] ?? null,
                'context_after' => $anchor['context_after'] ?? null,
                'link_type' => $classification['link_type']->value,
                'destination_kind' => $classification['destination_kind']->value ?? null,
                'target_site_id' => $classification['target_site_id'],
                'is_semantic_eligible' => true,
                'status' => SeoLinkMapStatus::Active,
            ]);

            $resolvedTargetUrl = trim((string) ($classification['target_external_url'] ?? ''));
            if ($resolvedTargetUrl === '' && $classification['target_article_id'] !== null) {
                $target = SeoArticle::query()->find($classification['target_article_id']);
                if ($target instanceof SeoArticle) {
                    $resolvedTargetUrl = trim((string) ($this->linkTargetResolver->resolveArticlePublicUrl($target) ?? ''));
                }
            }
            if ($resolvedTargetUrl === '') {
                $resolvedTargetUrl = trim($this->resolveAbsoluteExternalUrl($href, $siteId));
            }

            $this->linkStatusAudit->queueLinkMap($linkMap, $siteId, $resolvedTargetUrl !== '' ? $resolvedTargetUrl : null);

            $touchedKeywordIds[(int) $keyword->id] = true;
            $saved++;
        }

        foreach (array_keys($touchedKeywordIds) as $keywordId) {
            $this->qualityFlags->recomputeForKeywordFromMaps((int) $keywordId);
        }

        return $saved;
    }

    /**
     * @return list<array{
     *     href: string,
     *     anchor_text: string,
     *     context_before: string|null,
     *     context_after: string|null
     * }>
     */
    public function extractAnchorsWithContext(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"><html><body><div id="seo-link-map-root">'.$html.'</div></body></html>',
            LIBXML_NOWARNING | LIBXML_NOERROR,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $results = [];

        foreach ($xpath->query('//div[@id="seo-link-map-root"]//a[@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = trim(html_entity_decode($node->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($href === '' || str_starts_with($href, '#') || $this->isSpecialSchemeLink($href)) {
                continue;
            }

            $anchorText = Keyword::decodePhrase(
                Str::limit(trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? ''), 255, ''),
            );

            if ($anchorText === '') {
                continue;
            }

            $results[] = [
                'href' => $href,
                'anchor_text' => $anchorText,
                'context_before' => $this->collectAdjacentText($node, before: true, limit: 100),
                'context_after' => $this->collectAdjacentText($node, before: false, limit: 100),
            ];
        }

        return $this->deduplicateAnchors($results);
    }

    private function resolveArticleContent(SeoArticle $article): string
    {
        return trim((string) ($article->body ?? ''));
    }

    /**
     * Phase 1B: Classify destination early (before Keyword creation).
     *
     * @return array{
     *   link_type: SeoLinkMapType,
     *   destination_kind: SeoLinkMapDestinationKind,
     *   target_site_id: int|null,
     *   target_article_id: int|null,
     *   target_external_url: string|null,
     *   is_cta: bool,
     *   is_semantic_eligible: bool,
     *   host: string
     * }
     */
    private function classifyDestinationEarly(string $href, int $sourceSiteId, ?SeoArticle $resolvedTargetArticle): array
    {
        $absoluteUrl = $this->resolveAbsoluteExternalUrl($href, $sourceSiteId);
        $normalizedHref = $absoluteUrl !== '' ? $absoluteUrl : $href;

        $isTrustHost = false;
        if ($resolvedTargetArticle === null) {
            $storageUrl = SeoLinkMapExternalUrlNormalizer::forStorage($normalizedHref);
            $resolved = $storageUrl ?? $normalizedHref;
            $host = SeoLinkMapLinkTypeClassifier::resolveHost($resolved);
            $isTrustHost = SeoLinkMapLinkTypeClassifier::isWikiTrustHost($host);
        }

        $result = LinkDestinationClassifier::classify(
            $normalizedHref,
            $sourceSiteId,
            $resolvedTargetArticle,
            $isTrustHost,
        );

        // Resolve target article/url for persistence.
        $targetArticleId = null;
        $targetExternalUrl = null;

        if ($resolvedTargetArticle instanceof SeoArticle) {
            $targetArticleId = (int) $resolvedTargetArticle->id;
        } else {
            $storageUrl = SeoLinkMapExternalUrlNormalizer::forStorage($normalizedHref);
            $resolved = $storageUrl ?? $normalizedHref;
            $targetExternalUrl = $resolved !== '' ? $resolved : null;
        }

        return [
            'link_type' => $result['link_type'],
            'destination_kind' => $result['destination_kind'],
            'target_site_id' => $result['target_site_id'],
            'target_article_id' => $targetArticleId,
            'target_external_url' => $targetExternalUrl,
            'is_cta' => $result['is_cta'],
            'is_semantic_eligible' => $result['is_semantic_eligible'],
            'host' => $result['host'],
        ];
    }

    /**
     * Persist a raw link fact without creating a Keyword record.
     * Used for CTA/social/contact destinations that must not pollute keyword topology.
     *
     * @param  array{href: string, anchor_text: string, context_before: string|null, context_after: string|null}  $anchor
     * @param  array{link_type: SeoLinkMapType, destination_kind: SeoLinkMapDestinationKind, target_site_id: int|null, target_article_id: int|null, target_external_url: string|null, is_cta: bool, is_semantic_eligible: bool, host: string}  $classification
     */
    private function persistNonSemanticLinkFact(
        SeoArticle $article,
        string $href,
        string $anchorText,
        array $anchor,
        array $classification,
    ): void {
        SeoLinkMap::query()->create([
            'keyword_id' => null,
            'source_article_id' => (int) $article->id,
            'target_article_id' => $classification['target_article_id'],
            'target_external_url' => $classification['target_external_url'] ?? (trim($href) ?: null),
            'anchor_text' => $anchorText,
            'context_before' => $anchor['context_before'] ?? null,
            'context_after' => $anchor['context_after'] ?? null,
            'link_type' => $classification['link_type']->value,
            'destination_kind' => $classification['destination_kind']->value,
            'target_site_id' => $classification['target_site_id'],
            'is_semantic_eligible' => false,
            'status' => SeoLinkMapStatus::Active,
        ]);
    }

    /**
     * @return array{0: SeoLinkMapType, 1: int|null, 2: string|null}
     * @deprecated Use classifyDestinationEarly() for new code.
     */
    private function classifyAndResolveTarget(SeoArticle $sourceArticle, string $href, int $sourceSiteId): array
    {
        $targetArticle = $this->linkTargetResolver->resolveTargetArticleForLinkMap(
            $sourceSiteId,
            $href,
            $sourceArticle,
        );

        if ($targetArticle instanceof SeoArticle) {
            $linkType = SeoLinkMapLinkTypeClassifier::forManagedArticle($sourceSiteId, $targetArticle);

            return [$linkType, (int) $targetArticle->id, null];
        }

        $absoluteUrl = $this->resolveAbsoluteExternalUrl($href, $sourceSiteId);
        $storageUrl = SeoLinkMapExternalUrlNormalizer::forStorage(
            $absoluteUrl !== '' ? $absoluteUrl : $href,
        );
        $resolved = $storageUrl ?? ($absoluteUrl !== '' ? $absoluteUrl : $href);
        $linkType = SeoLinkMapLinkTypeClassifier::forUnresolvedUrl($resolved);

        return [$linkType, null, $resolved !== '' ? $resolved : null];
    }

    private function resolveAbsoluteExternalUrl(string $href, int $sourceSiteId): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }

        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        return $this->buildAbsoluteUrl($href, $sourceSiteId);
    }

    private function buildAbsoluteUrl(string $url, int $siteId): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $domain = Site::query()->whereKey($siteId)->value('domain');
        if (! is_string($domain) || trim($domain) === '') {
            return $url;
        }

        $domain = rtrim(trim($domain), '/');
        if (! str_starts_with($domain, 'http')) {
            $domain = 'https://'.$domain;
        }

        return str_starts_with($url, '/') ? $domain.$url : $domain.'/'.$url;
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
            'tel',
            'mailto',
            'sms',
            'whatsapp',
            'viber',
            'data',
            'cid',
        ], true);
    }

    private function collectAdjacentText(DOMElement $node, bool $before, int $limit): ?string
    {
        $chunks = [];
        $length = 0;
        $cursor = $before ? $node->previousSibling : $node->nextSibling;

        while ($cursor !== null && $length < $limit) {
            $text = $this->normalizeWhitespace($this->nodeTextContent($cursor));
            if ($text !== '') {
                if ($before) {
                    array_unshift($chunks, $text);
                } else {
                    $chunks[] = $text;
                }
                $length += mb_strlen($text);
            }

            $cursor = $before ? $cursor->previousSibling : $cursor->nextSibling;
        }

        if ($chunks === []) {
            return null;
        }

        $combined = implode(' ', $chunks);

        if ($before) {
            if (mb_strlen($combined) <= $limit) {
                return $combined !== '' ? $combined : null;
            }

            $slice = mb_substr($combined, -$limit);

            return $this->trimAtWordBoundaryStart($slice);
        }

        $slice = mb_substr($combined, 0, $limit);

        return $this->trimAtWordBoundaryEnd($slice);
    }

    private function nodeTextContent(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return (string) $node->textContent;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        return (string) $node->textContent;
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function trimAtWordBoundaryStart(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/\s(\S.*)$/u', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return $text;
    }

    private function trimAtWordBoundaryEnd(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^(.*?)\s/u', $text, $matches) === 1 && mb_strlen($matches[1]) >= 20) {
            return trim($matches[1]);
        }

        return $text;
    }

    /**
     * @param  list<array{href: string, anchor_text: string, context_before: string|null, context_after: string|null}>  $anchors
     * @return list<array{href: string, anchor_text: string, context_before: string|null, context_after: string|null}>
     */
    private function deduplicateAnchors(array $anchors): array
    {
        $seen = [];
        $unique = [];

        foreach ($anchors as $anchor) {
            $key = mb_strtolower((string) $anchor['href'])."\0".mb_strtolower((string) $anchor['anchor_text']);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $anchor;
        }

        return $unique;
    }

    /**
     * @param  array<int, string>  $excludeAnchorPhrases
     */
    private function shouldExcludeAnchorPhrase(string $anchorText, array $excludeAnchorPhrases): bool
    {
        if ($excludeAnchorPhrases === []) {
            return false;
        }

        $anchorNorm = mb_strtolower(Keyword::decodePhrase($anchorText));
        if ($anchorNorm === '') {
            return false;
        }

        foreach ($excludeAnchorPhrases as $phrase) {
            if ($anchorNorm === mb_strtolower(Keyword::decodePhrase((string) $phrase))) {
                return true;
            }
        }

        return false;
    }
}
