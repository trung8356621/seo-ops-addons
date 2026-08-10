<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use App\Models\Site;
use Illuminate\Support\Collection;
use Omnichannel\Addons\WordPress\Services\SitePolylangService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;

final class KeywordLinkTargetResolver
{
    public function __construct(
        private readonly WordPressArticleContentService $wpContent,
        private readonly SitePolylangService $polylang,
    ) {}

    /**
     * @return array{href: string, keyword_id: int, keyword_type: string}|null
     */
    public function resolveForPhraseOnSite(
        int $siteId,
        string $phrase,
        SeoArticle $currentArticle,
        bool $sameLanguageOnly = false,
        bool $internalOnly = false,
    ): ?array {
        $phrase = trim($phrase);
        if ($siteId <= 0 || $phrase === '') {
            return null;
        }

        $normalized = mb_strtolower($phrase);

        /** @var Collection<int, Keyword> $keywords */
        $keywords = Keyword::query()
            ->forSite($siteId)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->with(['linkMaps' => static fn ($query) => $query->whereHas(
                'sourceArticle',
                static fn ($articleQuery) => $articleQuery->where('site_id', $siteId),
            )])
            ->get()
            ->filter(fn (Keyword $keyword): bool => mb_strtolower(trim((string) $keyword->phrase)) === $normalized)
            ->sortBy(fn (Keyword $keyword): int => Keyword::isNormalType($keyword->type) ? 0 : 1)
            ->values();

        foreach ($keywords as $keyword) {
            $href = $this->resolveForKeyword($keyword, $currentArticle, $sameLanguageOnly, $internalOnly);
            if ($href !== null && $href !== '') {
                return [
                    'href' => $href,
                    'keyword_id' => (int) $keyword->id,
                    'keyword_type' => (string) $keyword->type,
                ];
            }
        }

        return null;
    }

    public function resolveForFocusKeyword(Keyword $keyword, SeoArticle $currentArticle): ?string
    {
        return $this->resolveForKeyword($keyword, $currentArticle);
    }

    public function resolveForKeyword(Keyword $keyword, SeoArticle $currentArticle, bool $sameLanguageOnly = false, bool $internalOnly = false): ?string
    {
        $siteId = (int) ($currentArticle->site_id ?? 0);
        $currentLang = $this->articleLanguage($currentArticle);
        $siteDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost(
            (string) ($currentArticle->site?->domain ?? $currentArticle->loadMissing('site')->site?->domain ?? ''),
        );

        $explicit = trim((string) ($keyword->targetUrlForSite($siteId) ?? ''));
        if ($explicit !== '') {
            $explicitAllowed = ! $sameLanguageOnly || $this->urlMatchesArticleLanguage($siteId, $explicit, $currentLang);
            $explicitInternal = $this->isHrefInternalForSite($explicit, $siteDomain, $siteId);
            if ($explicitAllowed && (! $internalOnly || $explicitInternal)) {
                return $explicit;
            }
        }

        if (Keyword::isNormalType($keyword->type)) {
            $fromLinks = $this->resolveFromInternalKeywordLinks($keyword, $currentArticle, $sameLanguageOnly, $internalOnly);
            if ($fromLinks !== null) {
                return $fromLinks;
            }
        }

        $mainArticle = $keyword->mainArticles()
            ->where('articles.id', '!=', (int) $currentArticle->id)
            ->when($sameLanguageOnly, static fn ($query) => $query->where('articles.language', $currentLang))
            ->when($internalOnly, static fn ($query) => $query->where('articles.site_id', $siteId))
            ->first();

        if ($mainArticle instanceof SeoArticle) {
            return $this->resolveArticlePublicUrl($mainArticle);
        }

        return null;
    }

    private function isHrefInternalForSite(string $href, string $siteDomain, int $siteId): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }

        if (str_starts_with($href, '/')) {
            return true;
        }

        $host = SeoLinkMapLinkTypeClassifier::resolveHost($href);
        if ($host !== '' && $siteDomain !== '' && $host === $siteDomain) {
            return true;
        }

        $targetArticle = $this->resolveArticleFromUrl($siteId, $href);

        return $targetArticle instanceof SeoArticle && (int) ($targetArticle->site_id ?? 0) === $siteId;
    }

    public function resolveArticlePublicUrl(SeoArticle $article): ?string
    {
        $permalink = trim($this->wpContent->resolvePermalink($article));
        if ($permalink !== '') {
            return $permalink;
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return null;
        }

        $base = $this->wpContent->getPermalinkBase($site);
        $slug = trim((string) ($article->slug ?? ''));
        if ($base === '' || $slug === '') {
            return null;
        }

        return rtrim($base, '/').'/'.ltrim($slug, '/');
    }

    public function resolveArticleFromUrl(int $siteId, string $url, ?SeoArticle $exclude = null): ?SeoArticle
    {
        $article = $this->findArticleByPublicUrl($siteId, $url);
        if (! $article instanceof SeoArticle) {
            return null;
        }

        if ($exclude !== null && (int) $article->id === (int) $exclude->id) {
            return null;
        }

        return $article;
    }

    /**
     * Resolve a scraped href to a managed article on the source site, then on peer SaaS domains.
     */
    public function resolveTargetArticleForLinkMap(int $sourceSiteId, string $url, ?SeoArticle $exclude = null): ?SeoArticle
    {
        if ($sourceSiteId > 0) {
            $onSourceSite = $this->resolveArticleFromUrl($sourceSiteId, $url, $exclude);
            if ($onSourceSite instanceof SeoArticle) {
                return $onSourceSite;
            }
        }

        $host = $this->resolveHostFromUrl($url);
        if ($host === '') {
            return null;
        }

        $targetSiteId = $this->resolveSiteIdByHost($host);
        if ($targetSiteId === null || $targetSiteId === $sourceSiteId) {
            return null;
        }

        return $this->resolveArticleFromUrl($targetSiteId, $url, $exclude);
    }

    public function resolveSiteIdByHost(string $host): ?int
    {
        $host = SeoLinkMapLinkTypeClassifier::normalizeDomainHost($host);
        if ($host === '') {
            return null;
        }

        $siteId = $this->domainSiteIdMap()->get($host);

        return is_numeric($siteId) && (int) $siteId > 0 ? (int) $siteId : null;
    }

    /**
     * @return Collection<string, int>
     */
    private function domainSiteIdMap(): Collection
    {
        if ($this->domainSiteIdMap instanceof Collection) {
            return $this->domainSiteIdMap;
        }

        $query = Site::query()->select(['id', 'domain']);

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $accessible = SeoAccessControl::accessibleSiteIds();
            if ($accessible !== []) {
                $query->whereIn('id', $accessible);
            }
        }

        $this->domainSiteIdMap = $query
            ->get()
            ->mapWithKeys(function (Site $site): array {
                $normalized = SeoLinkMapLinkTypeClassifier::normalizeDomainHost((string) $site->domain);
                if ($normalized === '') {
                    return [];
                }

                return [$normalized => (int) $site->id];
            });

        return $this->domainSiteIdMap;
    }

    private ?Collection $domainSiteIdMap = null;

    private function resolveHostFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        if (str_starts_with($url, '/')) {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? SeoLinkMapLinkTypeClassifier::normalizeDomainHost($host) : '';
    }

    private function resolveFromInternalKeywordLinks(
        Keyword $keyword,
        SeoArticle $currentArticle,
        bool $sameLanguageOnly = false,
        bool $internalOnly = false,
    ): ?string {
        $siteId = (int) ($currentArticle->site_id ?? 0);
        $currentLang = $this->articleLanguage($currentArticle);
        $siteDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost(
            (string) ($currentArticle->site?->domain ?? ''),
        );
        $currentPermalink = SeoSuggestionUrlNormalizer::normalize(
            $this->resolveArticlePublicUrl($currentArticle) ?? '',
        );

        if ($keyword->relationLoaded('linkMaps')) {
            // Eager load đã whereHas sourceArticle theo site — dùng collection, tránh N+1.
            $maps = $keyword->linkMaps->sortBy('id')->values();
        } else {
            $maps = $keyword->linkMaps()
                ->whereHas('sourceArticle', static fn ($query) => $query->where('site_id', $siteId))
                ->with(['targetArticle:id,site_id,title,slug', 'sourceArticle:id,site_id'])
                ->orderBy('seo_link_maps.id')
                ->get();
        }

        $urls = $maps
            ->map(fn (SeoLinkMap $map): string => $this->resolveLinkMapDestinationUrl($map, $siteId))
            ->filter(static fn (string $url): bool => trim($url) !== '');

        foreach ($urls as $url) {
            $trimmed = trim($url);
            if ($trimmed === '') {
                continue;
            }

            if ($currentPermalink !== '' && SeoSuggestionUrlNormalizer::normalize($trimmed) === $currentPermalink) {
                continue;
            }

            if ($sameLanguageOnly && ! $this->urlMatchesArticleLanguage($siteId, $trimmed, $currentLang)) {
                continue;
            }

            if ($internalOnly && ! $this->isHrefInternalForSite($trimmed, $siteDomain, $siteId)) {
                continue;
            }

            return $trimmed;
        }

        $linkedArticle = SeoArticle::query()
            ->where('site_id', $siteId)
            ->where('id', '!=', (int) $currentArticle->id)
            ->when($sameLanguageOnly, static fn ($query) => $query->where('language', $currentLang))
            ->whereIn('id', function ($query) use ($keyword): void {
                $query->select('source_article_id')
                    ->from('seo_link_maps')
                    ->where('keyword_id', $keyword->id)
                    ->whereNotNull('source_article_id');
            })
            ->orderBy('id')
            ->first();

        if ($linkedArticle instanceof SeoArticle) {
            return $this->resolveArticlePublicUrl($linkedArticle);
        }

        return null;
    }

    private function articleLanguage(SeoArticle $article): string
    {
        $lang = trim((string) ($article->language ?? ''));

        return $lang !== '' ? $lang : 'vi';
    }

    private function urlMatchesArticleLanguage(int $siteId, string $url, string $language): bool
    {
        $site = $siteId > 0 ? Site::query()->find($siteId) : null;

        $article = $this->findArticleByPublicUrl($siteId, $url);
        if ($article instanceof SeoArticle) {
            return $this->articleLanguage($article) === $language;
        }

        $inferredLang = $this->inferLanguageFromUrlPath($site instanceof Site ? $site : null, $url);
        if ($inferredLang !== null) {
            return $inferredLang === $language;
        }

        // Link list / category không có prefix ngôn ngữ — vẫn cho phép.
        return true;
    }

    private function inferLanguageFromUrlPath(?Site $site, string $url): ?string
    {
        if (! $site instanceof Site || ! $this->polylang->isPolylangEnabledForSite($site)) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            if (! str_starts_with(trim($url), '/')) {
                return null;
            }

            $path = trim($url);
        }

        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        $languageSlugs = array_keys($this->polylang->languageOptionsForSite($site));
        if ($languageSlugs === []) {
            return $this->polylang->defaultLanguageSlugForSite($site);
        }

        $defaultLang = $this->polylang->defaultLanguageSlugForSite($site);
        if ($segments === []) {
            return $defaultLang;
        }

        $first = strtolower($segments[0]);
        if (in_array($first, $languageSlugs, true)) {
            return $first;
        }

        return $defaultLang;
    }

    private function findArticleByPublicUrl(int $siteId, string $url): ?SeoArticle
    {
        if ($siteId <= 0) {
            return null;
        }

        $normalizedTarget = SeoSuggestionUrlNormalizer::normalize($url);
        if ($normalizedTarget === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            if (str_starts_with(trim($url), '/')) {
                $path = trim($url);
            } else {
                return null;
            }
        }

        $slug = $this->extractSlugFromPath($path);
        if ($slug === '') {
            return null;
        }

        $site = Site::query()->find($siteId);
        $inferredLang = $this->inferLanguageFromUrlPath($site instanceof Site ? $site : null, $url);

        $candidatesQuery = SeoArticle::query()
            ->where('site_id', $siteId)
            ->where('slug', $slug);

        if ($inferredLang !== null) {
            $candidatesQuery->where('language', $inferredLang);
        }

        $candidates = $candidatesQuery->limit(10)->get();

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof SeoArticle) {
                continue;
            }

            $permalink = $this->resolveArticlePublicUrl($candidate);
            if ($permalink !== null && SeoSuggestionUrlNormalizer::normalize($permalink) === $normalizedTarget) {
                return $candidate;
            }
        }

        /** @var SeoArticle|null $first */
        $first = $candidates->first();

        return $first instanceof SeoArticle ? $first : null;
    }

    private function extractSlugFromPath(string $path): string
    {
        $slug = basename(rtrim($path, '/'));
        if ($slug === '' || $slug === '/') {
            return '';
        }

        return (string) (preg_replace('/\.html?$/i', '', $slug) ?? $slug);
    }

    private function resolveLinkMapDestinationUrl(SeoLinkMap $map, int $siteId): string
    {
        if ((int) ($map->target_article_id ?? 0) > 0) {
            $target = $map->relationLoaded('targetArticle')
                ? $map->targetArticle
                : $map->targetArticle()->first(['id', 'site_id', 'title', 'slug']);

            if ($target instanceof SeoArticle) {
                $url = $this->resolveArticlePublicUrl($target);
                if (is_string($url) && trim($url) !== '') {
                    return trim($url);
                }
            }
        }

        $external = trim((string) ($map->target_external_url ?? ''));

        return $external;
    }
}
