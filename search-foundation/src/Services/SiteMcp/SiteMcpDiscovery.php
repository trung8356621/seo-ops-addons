<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SiteSync\Services\Capability\SiteCapabilityResolver;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure;
use App\Models\Site;
use Throwable;

/**
 * Discover website data for Site MCP draft — product_cat parent=0 first.
 *
 * Read-only against official Knowledge Profile.
 */
final class SiteMcpDiscovery
{
    private const META_KEYS = [
        'seo_focus_keyword',
        'seo_title',
        'wp_permalink',
        'wp_taxonomy',
        'wp_parent_id',
        'wp_term_count',
        ArticleContentClassification::META_CONTENT_TYPE,
        ArticleContentClassification::META_WP_IS_TERM,
        ArticleContentClassification::META_WP_POST_TYPE,
    ];

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly ?SiteCapabilityResolver $capabilities = null,
        private readonly ?SiteMcpProductCatLiveSource $liveProductCats = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function discover(Site $site): array
    {
        $official = $this->promptContext->getRawPayloadForSite($site);
        $shortDescription = trim((string) ($official['short_description'] ?? ''));
        $companyShort = trim((string) ($official['company_short_identity'] ?? ''));
        $tone = trim((string) ($official['tone'] ?? ''));
        $links = is_array($official['links'] ?? null) ? $official['links'] : [];
        $cta = is_array($official['cta'] ?? null) ? $official['cta'] : [];
        $phones = is_array($official['phones'] ?? null) ? $official['phones'] : [];
        $emails = is_array($official['emails'] ?? null) ? $official['emails'] : [];
        $socials = is_array($official['socials'] ?? null) ? $official['socials'] : [];

        $officialExists = $shortDescription !== ''
            || $companyShort !== ''
            || $tone !== ''
            || $links !== []
            || $cta !== []
            || $phones !== []
            || $emails !== []
            || $socials !== []
            || $this->hasContactSlots($official);

        $websiteType = trim((string) ($site->getMeta('seo_domain_type') ?? 'news'));
        if ($websiteType === '') {
            $websiteType = 'news';
        }

        $catalog = $this->discoverCatalog($site);
        $strategy = $this->resolveStrategy($websiteType, $catalog);

        $domain = trim((string) $site->domain);
        $siteTitle = trim((string) ($site->getMeta('seo_site_title') ?? ''));
        if ($siteTitle === '') {
            $siteTitle = $domain;
        }
        $brand = trim((string) ($site->getMeta('seo_brand_name') ?? ''));
        if ($brand === '') {
            $brand = $siteTitle;
        }

        $homepageUrl = $this->baseUrl($domain);
        $homepageHtml = $this->fetchHomepageHtml($homepageUrl);

        return [
            'site_id' => (int) $site->id,
            'domain' => $domain,
            'website_type' => $websiteType,
            'discovery_strategy' => $strategy,
            'site_title' => $siteTitle,
            'brand' => $brand,
            'official' => $official,
            'official_exists' => $officialExists,
            'sync_run_id' => $this->latestSyncRunId((int) $site->id),
            'product_categories' => $catalog['product_categories'],
            'service_categories' => $catalog['service_categories'],
            'products' => $catalog['products'],
            'product_count' => count($catalog['products']),
            'product_category_count' => count($catalog['product_categories']),
            'has_woocommerce_catalog' => $catalog['has_woocommerce_catalog'],
            'manual_link_keywords' => $this->manualLinkKeywords($links),
            'manual_links' => $links,
            'news_candidates' => $catalog['news_candidates'],
            'counts' => $catalog['counts'],
            'availability' => $catalog['availability'],
            'taxonomy_capability' => $catalog['taxonomy_capability'],
            'homepage_url' => $homepageUrl,
            'homepage_html' => $homepageHtml,
        ];
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    public function resolveStrategy(string $websiteType, array $catalog): string
    {
        $websiteType = mb_strtolower(trim($websiteType));

        return match ($websiteType) {
            'e-commerce', 'ecommerce', 'e_commerce' => 'ecommerce_catalog',
            'production' => 'production_catalog',
            default => 'news_manual',
        };
    }

    /**
     * @return array{
     *     product_categories: list<array<string, mixed>>,
     *     service_categories: list<array<string, mixed>>,
     *     products: list<array<string, mixed>>,
     *     news_candidates: list<array<string, mixed>>,
     *     has_woocommerce_catalog: bool,
     *     counts: array<string, int>,
     *     availability: array{product_cat_taxonomy: string},
     *     taxonomy_capability: array{product_category_taxonomy_export: bool, known: bool}
     * }
     */
    private function discoverCatalog(Site $site): array
    {
        $emptyCounts = [
            'post' => 0,
            'page' => 0,
            'product' => 0,
            'product_cat' => 0,
            'product_cat_total' => 0,
            'root_product_cat' => 0,
            'child_product_cat' => 0,
            'incomplete_product_cat' => 0,
            'attachment' => 0,
        ];

        $capState = $this->resolveTaxonomyCapability($site);
        $emptyAvailability = [
            'product_cat_taxonomy' => SiteMcpProductCatIdentity::resolveAvailability(
                $capState['available'],
                $capState['known'],
                0,
                0,
            ),
        ];

        $empty = [
            'product_categories' => [],
            'service_categories' => [],
            'products' => [],
            'news_candidates' => [],
            'has_woocommerce_catalog' => false,
            'counts' => $emptyCounts,
            'availability' => $emptyAvailability,
            'taxonomy_capability' => [
                'product_category_taxonomy_export' => $capState['available'],
                'known' => $capState['known'],
            ],
        ];

        if (! SiteSyncInfrastructure::hasTable('articles')) {
            return $empty;
        }

        try {
            $articles = SeoArticle::query()
                ->where('site_id', (int) $site->id)
                ->with([
                    'articleMetas' => static function ($query): void {
                        $query->whereIn('meta_key', self::META_KEYS);
                    },
                    'wordpressLink:article_id,wp_post_id',
                ])
                ->orderBy('id')
                ->limit(2000)
                ->get(['id', 'title', 'slug', 'site_id']);
        } catch (Throwable) {
            return $empty;
        }

        $baseUrl = $this->baseUrl((string) $site->domain);
        $productCategories = [];
        $serviceCategories = [];
        $products = [];
        $newsCandidates = [];
        $counts = $emptyCounts;
        $incompleteProductCat = 0;
        $incompleteTermIds = [];

        foreach ($articles as $article) {
            $metas = [];
            foreach ($article->articleMetas as $meta) {
                $metas[(string) $meta->meta_key] = (string) $meta->meta_value;
            }

            [$contentType, $isTerm] = $this->classify($metas);
            $type = $this->derivedTypeLabel($contentType, $isTerm);
            $taxonomy = mb_strtolower(trim((string) ($metas['wp_taxonomy'] ?? '')));
            $nativeType = mb_strtolower(trim((string) ($metas[ArticleContentClassification::META_WP_POST_TYPE] ?? '')));
            $slug = trim((string) ($article->slug ?? ''));
            $permalink = trim((string) ($metas['wp_permalink'] ?? ''));
            if ($permalink === '' && $slug !== '' && $baseUrl !== '') {
                $permalink = $baseUrl.'/'.ltrim($slug, '/');
            }

            $this->bumpCount($counts, $type, $taxonomy, $nativeType);

            $row = [
                'article_id' => (int) $article->id,
                'url' => $permalink,
                'title' => trim((string) ($article->title ?? '')),
                'name' => trim((string) ($article->title ?? '')),
                'seo_title' => trim((string) ($article->title ?? '')),
                'page_type' => $type !== '' ? $type : 'article',
                'focus_keyword' => (string) ($metas['seo_focus_keyword'] ?? ''),
                'slug' => $slug,
                'taxonomy' => $taxonomy,
                'term_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0),
                'post_count' => (int) ($metas['wp_term_count'] ?? 0),
                'source' => 'articles',
                'verified' => false,
            ];

            // Keep explicit 0 for root terms — never coerce missing key to null-as-root.
            if (array_key_exists('wp_parent_id', $metas)) {
                $row['parent_term_id'] = (int) $metas['wp_parent_id'];
            }

            $isProductCat = ($contentType === ContentType::Product && $isTerm)
                || $taxonomy === 'product_cat';

            if ($isProductCat && $type === 'product') {
                $products[] = $row;
                continue;
            }

            if ($isProductCat) {
                $payload = $row;
                $payload['taxonomy'] = 'product_cat';
                $payload['wp_taxonomy'] = 'product_cat';
                $payload['type'] = $type;
                $payload['page_type'] = $type;
                if (array_key_exists('parent_term_id', $row)) {
                    $payload['parent_term_id'] = $row['parent_term_id'];
                }

                $verified = SiteMcpProductCatIdentity::normalizeVerified($payload);
                if ($verified === null) {
                    $incompleteProductCat++;
                    if ($row['term_id'] > 0) {
                        $incompleteTermIds[] = $row['term_id'];
                    }
                    continue;
                }

                // Prefer taxonomy term rows (wp_is_term=1) over page-shaped discovery.
                $verified['article_id'] = $row['article_id'];
                $verified['title'] = $row['title'] !== '' ? $row['title'] : $verified['name'];
                $verified['seo_title'] = $row['seo_title'];
                $verified['focus_keyword'] = $row['focus_keyword'];
                $verified['page_type'] = 'taxonomy';
                $verified['source'] = $isTerm ? 'taxonomy_sync' : 'taxonomy_staging';
                $verified['verified'] = true;
                if ($verified['url'] === '' && $permalink !== '') {
                    $verified['url'] = $permalink;
                }
                $productCategories[] = $verified;
                continue;
            }

            if ($type === 'product') {
                $products[] = $row;
                continue;
            }

            if ($type === 'category' || $taxonomy === 'category') {
                $row['page_type'] = 'category';
                $row['taxonomy'] = $taxonomy !== '' ? $taxonomy : 'category';
                $serviceCategories[] = $row;
                $newsCandidates[] = $row;
                continue;
            }

            if (
                in_array($type, ['page', 'post', 'article'], true)
                && ! in_array($nativeType, ['attachment', 'media'], true)
            ) {
                $newsCandidates[] = $row;
            }
        }

        // Priority 1: live WordPress taxonomy export (or per-term refresh for incomplete).
        // Staging children alone cannot invent roots — supersede incomplete with verified parents.
        foreach ($productCategories as $cat) {
            $parentId = (int) ($cat['parent_term_id'] ?? 0);
            if ($parentId > 0) {
                $incompleteTermIds[] = $parentId;
            }
        }
        $liveRows = $this->resolveLiveProductCats($site, $incompleteTermIds);
        if ($liveRows !== []) {
            $this->backfillLiveParents($site, $liveRows);
            // Live wins; staging may enrich URL/focus only via dedupe.
            $productCategories = SiteMcpProductCatIdentity::dedupeByTaxonomyTermId(
                array_merge($liveRows, $productCategories)
            );
            $incompleteProductCat = 0;
        } else {
            $productCategories = SiteMcpProductCatIdentity::dedupeByTaxonomyTermId($productCategories);
        }

        $tree = SiteMcpProductCatIdentity::countTree($productCategories);

        $counts['product'] = max($counts['product'], count($products));
        $counts['product_cat'] = $tree['product_cat_total'];
        $counts['product_cat_total'] = $tree['product_cat_total'];
        $counts['root_product_cat'] = $tree['root_product_cat'];
        $counts['child_product_cat'] = $tree['child_product_cat'];
        $counts['incomplete_product_cat'] = $incompleteProductCat;

        // If live export unavailable and staging has children but zero roots, mark incomplete.
        if (
            $tree['root_product_cat'] === 0
            && $tree['child_product_cat'] > 0
            && $incompleteProductCat === 0
            && $liveRows === []
        ) {
            // Children present without any root identity ⇒ export/staging incomplete for roots.
            $incompleteProductCat = $tree['child_product_cat'];
            $counts['incomplete_product_cat'] = $incompleteProductCat;
        }

        $availabilityStatus = SiteMcpProductCatIdentity::resolveAvailability(
            $capState['available'] || $liveRows !== [],
            $capState['known'] || $liveRows !== [],
            $tree['product_cat_total'],
            $incompleteProductCat,
        );

        return [
            'product_categories' => $productCategories,
            'service_categories' => $serviceCategories,
            'products' => $products,
            'news_candidates' => $newsCandidates,
            'has_woocommerce_catalog' => $productCategories !== [] || $products !== [],
            'counts' => $counts,
            'availability' => [
                'product_cat_taxonomy' => $availabilityStatus,
            ],
            'taxonomy_capability' => [
                'product_category_taxonomy_export' => $capState['available'],
                'known' => $capState['known'],
            ],
        ];
    }

    /**
     * @param  list<int>  $incompleteTermIds
     * @return list<array<string, mixed>>
     */
    private function resolveLiveProductCats(Site $site, array $incompleteTermIds): array
    {
        $source = $this->liveProductCats;
        if ($source === null) {
            try {
                $source = app(SiteMcpProductCatLiveSource::class);
            } catch (Throwable) {
                return [];
            }
        }

        try {
            return $source->fetchVerifiedProductCats($site, $incompleteTermIds);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $liveRows
     */
    private function backfillLiveParents(Site $site, array $liveRows): void
    {
        $source = $this->liveProductCats;
        if ($source === null) {
            try {
                $source = app(SiteMcpProductCatLiveSource::class);
            } catch (Throwable) {
                return;
            }
        }

        try {
            $source->backfillParentMetas($site, $liveRows);
        } catch (Throwable) {
            // Discovery still returns live rows even if meta backfill fails.
        }
    }

    /**
     * @return array{available: bool, known: bool}
     */
    private function resolveTaxonomyCapability(Site $site): array
    {
        $resolver = $this->capabilities;
        if ($resolver === null) {
            try {
                $resolver = app(SiteCapabilityResolver::class);
            } catch (Throwable) {
                return ['available' => false, 'known' => false];
            }
        }

        try {
            $manifest = $resolver->forSite($site);
        } catch (Throwable) {
            return ['available' => false, 'known' => false];
        }

        if ($manifest === null) {
            return ['available' => false, 'known' => false];
        }

        $caps = $manifest->capabilities;
        if (! array_key_exists(SiteMcpProductCatIdentity::CAPABILITY, $caps)) {
            // Old plugin: generic taxonomy cap does not guarantee parent_term_id export.
            return ['available' => false, 'known' => true];
        }

        return [
            'available' => (bool) ($caps[SiteMcpProductCatIdentity::CAPABILITY]['available'] ?? false),
            'known' => true,
        ];
    }

    /**
     * Canonical classification from metas — falls back to wp_post_type/wp_taxonomy for
     * rows the content_type backfill has not reached yet (never reads articles.type).
     *
     * @param  array<string, string>  $metas
     * @return array{0: ContentType, 1: bool}
     */
    private function classify(array $metas): array
    {
        $contentType = ContentType::tryFromString($metas[ArticleContentClassification::META_CONTENT_TYPE] ?? null);

        $termFlag = trim((string) ($metas[ArticleContentClassification::META_WP_IS_TERM] ?? ''));
        $hasTermFlag = $termFlag !== '';
        $isTerm = in_array(mb_strtolower($termFlag), ['1', 'true', 'yes'], true);

        if ($contentType === null || ! $hasTermFlag) {
            $legacy = ArticleContentClassification::fromLegacyRow(
                null,
                $metas[ArticleContentClassification::META_WP_POST_TYPE] ?? null,
                null,
                $metas['wp_taxonomy'] ?? null,
            );

            $contentType ??= $legacy['content_type'];
            if (! $hasTermFlag) {
                $isTerm = $legacy['wp_is_term'];
            }
        }

        return [$contentType, $isTerm];
    }

    private function derivedTypeLabel(ContentType $contentType, bool $isTerm): string
    {
        if ($isTerm) {
            return $contentType === ContentType::Product ? 'product_category' : 'category';
        }

        return $contentType->value;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function bumpCount(array &$counts, string $type, string $taxonomy, string $nativeType = ''): void
    {
        if ($type === 'product_category' || $type === 'product_cat' || $taxonomy === 'product_cat') {
            // Final product_cat totals come from verified tree; bump only tracks raw sightings.
            return;
        }

        // content_type has no attachment value — the raw platform slug still tracks media.
        if ($nativeType === 'attachment' || $nativeType === 'media') {
            $counts['attachment']++;

            return;
        }

        match ($type) {
            'post', 'article' => $counts['post']++,
            'page' => $counts['page']++,
            'product', 'products' => $counts['product']++,
            'attachment', 'media' => $counts['attachment']++,
            default => null,
        };
    }

    private function fetchHomepageHtml(string $url): string
    {
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return '';
        }

        try {
            if (! function_exists('curl_init')) {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout' => 8,
                        'follow_location' => 1,
                        'user_agent' => 'SiteMcpDiscovery/1.0',
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                ]);
                $body = @file_get_contents($url, false, $ctx);

                return is_string($body) ? mb_substr($body, 0, 500_000) : '';
            }

            $ch = curl_init($url);
            if ($ch === false) {
                return '';
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'SiteMcpDiscovery/1.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);

            return is_string($body) ? mb_substr($body, 0, 500_000) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  array<string, mixed>  $official
     */
    private function hasContactSlots(array $official): bool
    {
        foreach (['phone_1', 'phone_2', 'phone_3', 'email_1', 'email_2', 'email_3'] as $key) {
            if (trim((string) ($official[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{keyword?: string, link?: string}>  $links
     * @return list<string>
     */
    private function manualLinkKeywords(array $links): array
    {
        $out = [];
        foreach ($links as $row) {
            $keyword = trim((string) ($row['keyword'] ?? ''));
            if ($keyword !== '') {
                $out[] = $keyword;
            }
        }

        return $out;
    }

    private function latestSyncRunId(int $siteId): ?int
    {
        if (! SiteSyncInfrastructure::hasTable('seo_site_sync_runs')) {
            return null;
        }

        try {
            $id = SeoSiteSyncRun::query()
                ->where('site_id', $siteId)
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->value('id');

            return $id !== null ? (int) $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function baseUrl(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $domain) === 1) {
            return rtrim($domain, '/');
        }

        return 'https://'.ltrim($domain, '/');
    }
}
