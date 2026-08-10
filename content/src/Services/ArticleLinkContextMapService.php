<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use App\Models\Site;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

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

        $article->loadMissing('site');
        $content = $contentOverride ?? $this->resolveArticleContent($article);
        if (trim($content) === '') {
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

            [$linkType, $targetArticleId, $targetExternalUrl] = $this->classifyAndResolveTarget($article, $href, $siteId);

            $linkMap = SeoLinkMap::query()->create([
                'keyword_id' => (int) $keyword->id,
                'source_article_id' => (int) $article->id,
                'target_article_id' => $targetArticleId,
                'target_external_url' => $targetExternalUrl,
                'anchor_text' => $anchorText,
                'context_before' => $anchor['context_before'] ?? null,
                'context_after' => $anchor['context_after'] ?? null,
                'link_type' => $linkType,
                'status' => SeoLinkMapStatus::Active,
            ]);

            $resolvedTargetUrl = trim((string) ($targetExternalUrl ?? ''));
            if ($resolvedTargetUrl === '' && $targetArticleId !== null) {
                $target = SeoArticle::query()->find($targetArticleId);
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
        $body = trim((string) ($article->body ?? ''));
        if ($body !== '') {
            return $body;
        }

        $meta = $article->articleMetas()
            ->where('meta_key', 'wp_post_content')
            ->value('meta_value');

        return is_string($meta) ? trim($meta) : '';
    }

    /**
     * @return array{0: SeoLinkMapType, 1: int|null, 2: string|null}
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
        $linkType = SeoLinkMapLinkTypeClassifier::forUnresolvedUrl($absoluteUrl !== '' ? $absoluteUrl : $href);

        return [$linkType, null, $absoluteUrl !== '' ? $absoluteUrl : $href];
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
