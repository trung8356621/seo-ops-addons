<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

/**
 * Effective Domain Link List for Article Editor:
 * custom (prompt context) + synced product_cat + 1 main domain link.
 *
 * Priority / merge order: custom > product_cat > main_domain.
 * Does not persist product_cat into manual rows.
 */
final class EffectiveDomainLinkResolver
{
    public const SOURCE_CUSTOM = 'custom';

    public const SOURCE_PRODUCT_CAT = 'product_cat';

    public const SOURCE_MAIN_DOMAIN = 'main_domain';

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
    ) {}

    /**
     * @return list<array{
     *     keyword: string,
     *     link: string,
     *     source: string,
     *     priority: int
     * }>
     */
    public function forSite(Site $site): array
    {
        $payload = $this->promptContext->getRawPayloadForSite($site);

        return $this->merge(
            $this->customLinksFromPayload($payload),
            $this->productCategoryLinks((int) $site->getKey()),
            $this->mainDomainLink($site, $payload),
        );
    }

    /**
     * @param  list<array{keyword?: string, link?: string}>  $custom
     * @param  list<array{keyword?: string, link?: string}>  $productCat
     * @param  array{keyword?: string, link?: string}|null  $main
     * @return list<array{keyword: string, link: string, source: string, priority: int}>
     */
    public function merge(array $custom, array $productCat, ?array $main): array
    {
        $out = [];
        $seen = [];

        $append = static function (array $rows, string $source, int $priority) use (&$out, &$seen): void {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $keyword = trim((string) ($row['keyword'] ?? ''));
                $link = trim((string) ($row['link'] ?? ''));
                if ($keyword === '' || $link === '') {
                    continue;
                }
                $key = self::normalizeKeywordKey($keyword);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'keyword' => $keyword,
                    'link' => $link,
                    'source' => $source,
                    'priority' => $priority,
                ];
            }
        };

        $append($custom, self::SOURCE_CUSTOM, 1);
        $append($productCat, self::SOURCE_PRODUCT_CAT, 2);
        if (is_array($main)) {
            $append([$main], self::SOURCE_MAIN_DOMAIN, 3);
        }

        return $out;
    }

    public static function normalizeKeywordKey(string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return '';
        }

        $keyword = mb_strtolower($keyword, 'UTF-8');
        $keyword = preg_replace('/\s+/u', ' ', $keyword) ?? $keyword;

        return trim($keyword);
    }

    /**
     * @return array{
     *     wordpress_active: int,
     *     manual: int,
     *     product_categories: int,
     *     effective: int,
     *     main_domain: int,
     *     label: string
     * }
     */
    public function catalogSummary(Site $site): array
    {
        $siteId = (int) $site->getKey();
        $payload = $this->promptContext->getRawPayloadForSite($site);
        $custom = $this->customLinksFromPayload($payload);
        $productCat = $this->productCategoryLinks($siteId);
        $main = $this->mainDomainLink($site, $payload);
        $effective = $this->merge($custom, $productCat, $main);

        $wordpressActive = SeoSiteLinkCatalog::query()
            ->forSite($siteId)
            ->where('source', SiteSyncSchema::SOURCE_WORDPRESS)
            ->whereNull('inactive_at')
            ->count();

        $manual = count($custom);
        $productCategories = count($productCat);
        $mainDomain = $main !== null ? 1 : 0;

        return [
            'wordpress_active' => $wordpressActive,
            'manual' => $manual,
            'product_categories' => $productCategories,
            'effective' => count($effective),
            'main_domain' => $mainDomain,
            'label' => sprintf(
                'WordPress active: %d · Manual: %d · Product categories: %d · Effective links: %d.',
                $wordpressActive,
                $manual,
                $productCategories,
                count($effective),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{keyword: string, link: string}>
     */
    private function customLinksFromPayload(array $payload): array
    {
        $links = is_array($payload['links'] ?? null) ? $payload['links'] : [];
        $out = [];
        foreach ($links as $row) {
            if (! is_array($row)) {
                continue;
            }
            $keyword = trim((string) ($row['keyword'] ?? ''));
            $link = trim((string) ($row['link'] ?? ''));
            if ($keyword === '' || $link === '') {
                continue;
            }
            $out[] = ['keyword' => $keyword, 'link' => $link];
        }

        return $out;
    }

    /**
     * Synced WooCommerce product_cat terms only (content_type = product + wp_is_term = 1).
     *
     * @return list<array{keyword: string, link: string}>
     */
    private function productCategoryLinks(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $query = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereNotIn('status', ['trash', 'trashed', 'deleted'])
            ->with(['articleMetas' => static fn ($q) => $q->where('meta_key', 'wp_permalink')])
            ->orderBy('id');

        ArticleContentClassification::scopeContentType($query, ContentType::Product);
        ArticleContentClassification::scopeIsTerm($query, true);

        $articles = $query->get(['id', 'title', 'status', 'site_id']);

        $out = [];
        foreach ($articles as $article) {
            $name = trim((string) ($article->title ?? ''));
            $url = trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));
            if ($name === '' || $url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $out[] = ['keyword' => $name, 'link' => $url];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{keyword: string, link: string}|null
     */
    private function mainDomainLink(Site $site, array $payload): ?array
    {
        $url = $this->canonicalHomeUrl($site);
        if ($url === '') {
            return null;
        }

        $anchor = $this->resolveMainDomainAnchor($site, $payload);
        if ($anchor === null) {
            return null;
        }

        return ['keyword' => $anchor, 'link' => $url];
    }

    private function canonicalHomeUrl(Site $site): string
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain) === 1) {
            return rtrim($domain, '/').'/';
        }

        $scheme = ! empty($site->ssl) ? 'https' : 'http';

        return $scheme.'://'.rtrim($domain, '/').'/';
    }

    /**
     * Prefer configured brand/site name — never hostname or URL as anchor.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveMainDomainAnchor(Site $site, array $payload): ?string
    {
        $candidates = [];

        $companyShort = trim((string) ($payload['company_short_identity'] ?? ''));
        if ($companyShort !== '') {
            $candidates[] = $companyShort;
        }

        foreach ($this->profileNameCandidates($site) as $name) {
            $candidates[] = $name;
        }

        $host = $this->hostnameOf($site);
        foreach ($candidates as $candidate) {
            $anchor = trim($candidate);
            if ($anchor === '') {
                continue;
            }
            if ($this->looksLikeHostnameOrUrl($anchor, $host)) {
                continue;
            }

            return $anchor;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function profileNameCandidates(Site $site): array
    {
        $store = SiteSyncSiteMeta::getJson($site, SiteSyncSchema::META_PROFILE_SUGGESTIONS);
        if (! is_array($store)) {
            return [];
        }

        $names = [];
        foreach (['accepted', 'items'] as $bucket) {
            $rows = is_array($store[$bucket] ?? null) ? $store[$bucket] : [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $field = (string) ($row['field'] ?? '');
                if (! in_array($field, ['site_name', 'organization_name'], true)) {
                    continue;
                }
                $value = trim((string) ($row['value'] ?? ''));
                if ($value !== '') {
                    $names[] = $value;
                }
            }
        }

        return $names;
    }

    private function hostnameOf(Site $site): string
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain) === 1) {
            return mb_strtolower((string) (parse_url($domain, PHP_URL_HOST) ?: ''));
        }

        return mb_strtolower(explode('/', $domain)[0] ?? $domain);
    }

    private function looksLikeHostnameOrUrl(string $anchor, string $host): bool
    {
        $normalized = mb_strtolower(trim($anchor));
        if ($normalized === '') {
            return true;
        }

        if (preg_match('#^https?://#i', $anchor) === 1) {
            return true;
        }

        if ($host !== '' && ($normalized === $host || $normalized === 'www.'.$host)) {
            return true;
        }

        if (str_contains($normalized, '.') && ! str_contains($normalized, ' ')) {
            // Bare hostname-like token (example.com) — reject as brand anchor.
            return (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $normalized);
        }

        return false;
    }
}
