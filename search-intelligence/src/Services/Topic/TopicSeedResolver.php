<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use App\Core\Capability\CapabilityRegistry;
use App\Models\Site;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpDiscovery;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpKeywordExtractor;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpProductCatIdentity;
use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SiteSync\Contracts\SiteLinkCatalogCapability;

/**
 * Resolve Topic seed evidence for one site.
 *
 * A) Effective Link List = WordPress ∪ Manual − Excluded (Site Sync catalog SSOT)
 * B) Verified root product_cat for Manufacturer (production) / Ecommerce (e-commerce)
 *
 * Focus keywords / individual products / child categories are NOT seeds.
 */
final class TopicSeedResolver
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly KeywordPersistenceService $keywords,
        private readonly SiteMcpDiscovery $discovery,
        private readonly SiteMcpKeywordExtractor $keywordExtractor,
        private readonly TopicSiteKeywordService $siteKeywords,
    ) {}

    /**
     * @return list<array{
     *     keyword_id: int,
     *     phrase: string,
     *     source: string,
     *     is_seed: true,
     *     confidence: float|null
     * }>
     */
    public function resolve(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        /** @var array<int, array{keyword_id: int, phrase: string, source: string, is_seed: true, confidence: float|null}> $byKeyword */
        $byKeyword = [];

        foreach ($this->linkListSeeds($siteId) as $seed) {
            $byKeyword[$seed['keyword_id']] = $seed;
        }

        foreach ($this->productCatSeeds($siteId) as $seed) {
            // product_cat does not override an existing link_list seed identity;
            // keep first seed source, but keyword still counts as seed.
            if (! isset($byKeyword[$seed['keyword_id']])) {
                $byKeyword[$seed['keyword_id']] = $seed;
            }
        }

        return array_values($byKeyword);
    }

    /**
     * @return list<array{keyword_id: int, phrase: string, source: string, is_seed: true, confidence: float|null}>
     */
    private function linkListSeeds(int $siteId): array
    {
        $catalog = $this->capabilities->getAs(
            SiteLinkCatalogCapability::ID,
            SiteLinkCatalogCapability::class,
        );
        if (! $catalog instanceof SiteLinkCatalogCapability) {
            return [];
        }

        $seeds = [];
        foreach ($catalog->effectiveLinks($siteId) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $phrase = $this->phraseFromLinkRow($row);
            if ($phrase === '') {
                continue;
            }
            $keyword = $this->keywords->upsert($phrase, 'internal', $siteId, $this->urlFromLinkRow($row));
            if (! $keyword instanceof Keyword) {
                continue;
            }
            $this->siteKeywords->upsertClassification($siteId, $keyword, TopicKeywordSource::LINK_LIST);
            $seeds[] = [
                'keyword_id' => (int) $keyword->id,
                'phrase' => (string) $keyword->phrase,
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
            ];
        }

        return $seeds;
    }

    /**
     * @return list<array{keyword_id: int, phrase: string, source: string, is_seed: true, confidence: float|null}>
     */
    private function productCatSeeds(int $siteId): array
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return [];
        }

        $websiteType = mb_strtolower(trim((string) ($site->getMeta('seo_domain_type') ?? '')));
        if (! $this->allowsProductCatSeeds($websiteType)) {
            return [];
        }

        $discovered = $this->discovery->discover($site);
        $productCategories = is_array($discovered['product_categories'] ?? null)
            ? $discovered['product_categories']
            : [];
        $taxonomyCapability = is_array($discovered['taxonomy_capability'] ?? null)
            ? $discovered['taxonomy_capability']
            : [];
        $counts = is_array($discovered['counts'] ?? null) ? $discovered['counts'] : [];
        $availabilityNested = is_array($discovered['availability'] ?? null)
            ? $discovered['availability']
            : [];

        $availabilityResolved = (string) ($availabilityNested['product_cat_taxonomy'] ?? '');
        if ($availabilityResolved === '') {
            $availabilityResolved = SiteMcpProductCatIdentity::resolveAvailability(
                (bool) ($taxonomyCapability['product_category_taxonomy_export'] ?? false),
                (bool) ($taxonomyCapability['known'] ?? false),
                (int) ($counts['product_cat_total'] ?? count($productCategories)),
                (int) ($counts['incomplete_product_cat'] ?? 0),
            );
        }
        if ($availabilityResolved === SiteMcpProductCatIdentity::AVAILABILITY_UNAVAILABLE
            || $availabilityResolved === SiteMcpProductCatIdentity::AVAILABILITY_INCOMPLETE) {
            return [];
        }

        $roots = $this->rootProductCategories($productCategories);
        if ($roots === []) {
            return [];
        }

        $seeds = [];
        foreach ($roots as $category) {
            $extracted = $this->keywordExtractor->extractCategoryTopic($category);
            $phrase = trim((string) ($extracted['keyword'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $url = trim((string) ($category['url'] ?? ''));
            $keyword = $this->keywords->upsert(
                $phrase,
                'internal',
                $siteId,
                $url !== '' ? $url : null,
            );
            if (! $keyword instanceof Keyword) {
                continue;
            }
            $this->siteKeywords->upsertClassification($siteId, $keyword, TopicKeywordSource::PRODUCT_CAT);
            $seeds[] = [
                'keyword_id' => (int) $keyword->id,
                'phrase' => (string) $keyword->phrase,
                'source' => TopicKeywordSource::PRODUCT_CAT,
                'is_seed' => true,
                'confidence' => (float) ($extracted['confidence'] ?? 0.8),
            ];
        }

        return $seeds;
    }

    /**
     * Manufacturer (UI) → production; Ecommerce → e-commerce (+ aliases).
     */
    private function allowsProductCatSeeds(string $websiteType): bool
    {
        $label = DomainListPresentation::websiteTypeLabel($websiteType);
        if ($label === 'Manufacturer' || $label === 'Ecommerce') {
            return true;
        }

        return in_array($websiteType, ['production', 'e-commerce', 'ecommerce', 'e_commerce', 'manufacturer'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $productCategories
     * @return list<array<string, mixed>>
     */
    private function rootProductCategories(array $productCategories): array
    {
        $roots = [];
        foreach ($productCategories as $row) {
            if (! is_array($row)) {
                continue;
            }
            $verified = SiteMcpProductCatIdentity::normalizeVerified($row) ?? (
                (($row['verified'] ?? false) && ($row['taxonomy'] ?? '') === 'product_cat') ? $row : null
            );
            if (! is_array($verified)) {
                continue;
            }
            if ((int) ($verified['term_id'] ?? 0) <= 0) {
                continue;
            }
            if (! array_key_exists('parent_term_id', $verified)) {
                continue;
            }
            if ((int) $verified['parent_term_id'] !== 0) {
                continue;
            }
            $pageType = mb_strtolower(trim((string) ($verified['page_type'] ?? $row['page_type'] ?? '')));
            if (in_array($pageType, ['product', 'products'], true)) {
                continue;
            }
            $roots[] = $verified;
        }

        return $roots;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function phraseFromLinkRow(array $row): string
    {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $candidates = [
            $meta['focus_keyword'] ?? null,
            $meta['anchor'] ?? null,
            $meta['keyword'] ?? null,
            $row['title'] ?? null,
            $row['slug'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $phrase = trim((string) $candidate);
            if ($phrase !== '') {
                return TopicNaming::canonicalName($phrase) ?: $phrase;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function urlFromLinkRow(array $row): ?string
    {
        $url = trim((string) ($row['canonical'] ?? $row['url'] ?? ''));

        return $url !== '' ? $url : null;
    }
}
