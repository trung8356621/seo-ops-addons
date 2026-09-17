<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteLink;

use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;

/**
 * Read-only consumer policy for site links.
 *
 * Owns: source selection, consumer filters, composition, keyword-key dedupe.
 * Does NOT own: storage, WP sync, catalog inventory, Keyword/Topic persistence.
 *
 * Domain Link List (prompt curated) ≠ Site Sync Link Catalog (WP ∪ Manual − Excluded).
 */
final class SiteLinkPolicyResolver
{
    public const SOURCE_DOMAIN_LINK_LIST = 'domain_link_list';

    public const SOURCE_PRODUCT_CAT = 'product_cat';

    public const SOURCE_MAIN_DOMAIN = 'main_domain';

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly VerifiedProductCatLinkSource $productCats,
    ) {}

    /**
     * Effective links for Keyword materialization.
     *
     * production / e-commerce: Domain Link List + all verified product_cat.
     * other site types: Domain Link List only (preserve prior behavior).
     *
     * @return list<array{
     *     keyword: string,
     *     url: string,
     *     source: string,
     *     taxonomy: ?string,
     *     term_id: ?int,
     *     parent_term_id: ?int
     * }>
     */
    public function forKeyword(Site $site): array
    {
        $manual = $this->manualDomainLinks($site);
        $productCat = $this->allowsProductCatComposition($site)
            ? $this->productCatRecords($site)
            : [];

        return $this->compose($manual, $productCat, null);
    }

    /**
     * Effective links for Article Editor Domain Link List.
     *
     * Always: Domain Link List + all verified product_cat + main domain.
     *
     * @return list<array{
     *     keyword: string,
     *     url: string,
     *     source: string,
     *     taxonomy: ?string,
     *     term_id: ?int,
     *     parent_term_id: ?int
     * }>
     */
    public function forArticleEditor(Site $site): array
    {
        return $this->compose(
            $this->manualDomainLinks($site),
            $this->productCatRecords($site),
            $this->mainDomainRecord($site),
        );
    }

    /**
     * Merge with precedence: domain_link_list > product_cat > main_domain.
     * Dedupe identity: normalized keyword (not URL).
     *
     * @param  list<array{keyword: string, url: string, taxonomy?: ?string, term_id?: ?int, parent_term_id?: ?int}>  $manual
     * @param  list<array{keyword: string, url: string, taxonomy?: ?string, term_id?: ?int, parent_term_id?: ?int}>  $productCat
     * @param  array{keyword: string, url: string}|null  $main
     * @return list<array{
     *     keyword: string,
     *     url: string,
     *     source: string,
     *     taxonomy: ?string,
     *     term_id: ?int,
     *     parent_term_id: ?int
     * }>
     */
    public function compose(array $manual, array $productCat, ?array $main): array
    {
        $out = [];
        $seen = [];

        $append = static function (
            array $rows,
            string $source,
        ) use (&$out, &$seen): void {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $keyword = trim((string) ($row['keyword'] ?? ''));
                $url = trim((string) ($row['url'] ?? $row['link'] ?? ''));
                if ($keyword === '' || $url === '') {
                    continue;
                }
                $key = self::normalizeKeywordKey($keyword);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'keyword' => $keyword,
                    'url' => $url,
                    'source' => $source,
                    'taxonomy' => isset($row['taxonomy']) ? (string) $row['taxonomy'] : null,
                    'term_id' => array_key_exists('term_id', $row) && $row['term_id'] !== null
                        ? (int) $row['term_id']
                        : null,
                    'parent_term_id' => array_key_exists('parent_term_id', $row) && $row['parent_term_id'] !== null
                        ? (int) $row['parent_term_id']
                        : null,
                ];
            }
        };

        $append($manual, self::SOURCE_DOMAIN_LINK_LIST);
        $append($productCat, self::SOURCE_PRODUCT_CAT);
        if (is_array($main)) {
            $append([$main], self::SOURCE_MAIN_DOMAIN);
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

    private function allowsProductCatComposition(Site $site): bool
    {
        $websiteType = mb_strtolower(trim((string) ($site->getMeta('seo_domain_type') ?? '')));
        $label = DomainListPresentation::websiteTypeLabel($websiteType);
        if ($label === 'Manufacturer' || $label === 'Ecommerce') {
            return true;
        }

        return in_array($websiteType, ['production', 'e-commerce', 'ecommerce', 'e_commerce', 'manufacturer'], true);
    }

    /**
     * @return list<array{keyword: string, url: string}>
     */
    private function manualDomainLinks(Site $site): array
    {
        $payload = $this->promptContext->getRawPayloadForSite($site);
        $links = is_array($payload['links'] ?? null) ? $payload['links'] : [];
        $out = [];
        foreach ($links as $row) {
            if (! is_array($row)) {
                continue;
            }
            $keyword = trim((string) ($row['keyword'] ?? ''));
            $url = trim((string) ($row['link'] ?? ''));
            if ($keyword === '' || $url === '') {
                continue;
            }
            $out[] = ['keyword' => $keyword, 'url' => $url];
        }

        return $out;
    }

    /**
     * @return list<array{
     *     keyword: string,
     *     url: string,
     *     taxonomy: string,
     *     term_id: int,
     *     parent_term_id: int
     * }>
     */
    private function productCatRecords(Site $site): array
    {
        return $this->productCats->forSite($site);
    }

    /**
     * @return array{keyword: string, url: string}|null
     */
    private function mainDomainRecord(Site $site): ?array
    {
        $url = $this->canonicalHomeUrl($site);
        if ($url === '') {
            return null;
        }

        $payload = $this->promptContext->getRawPayloadForSite($site);
        $anchor = $this->resolveMainDomainAnchor($site, $payload);
        if ($anchor === null) {
            return null;
        }

        return ['keyword' => $anchor, 'url' => $url];
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
            return (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $normalized);
        }

        return false;
    }
}
