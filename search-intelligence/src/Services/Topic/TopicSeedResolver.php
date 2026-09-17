<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchFoundation\Services\SiteLink\VerifiedProductCatLinkSource;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpProductCatIdentity;
use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

/**
 * Resolve Topic seed evidence for one site.
 *
 * A) Curated Domain Link List = seo_domain_prompt_context.links (keyword → URL)
 * B) Verified product_cat at any hierarchy depth for Manufacturer (production) /
 *    Ecommerce (e-commerce) via {@see VerifiedProductCatLinkSource}
 *
 * Site Sync catalog (WordPress ∪ Manual − Excluded) is inventory — NOT Topic seed evidence.
 * Manual Topics persist via seo_topics.source=manual (not keyword seeds).
 * Focus keywords / individual products are NOT seeds.
 *
 * Precedence when the same keyword_id appears in multiple sources:
 * link_list > product_cat (first wins; no duplicate seed).
 *
 * Does not call SiteLinkPolicyResolver Keyword/Editor consumer methods —
 * Topic seed semantics stay independent of Keyword policy composition.
 */
final class TopicSeedResolver
{
    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly VerifiedProductCatLinkSource $productCats,
        private readonly KeywordPersistenceService $keywords,
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
     * Read-only seed-source impact preview — does not upsert Keywords or mutate Topics.
     *
     * @return array{
     *     site_id: int,
     *     current_catalog_link_seeds: int,
     *     new_curated_link_seeds: int,
     *     product_cat_seeds: int,
     *     estimated_automatic_seed_set: int,
     *     existing_topics: int,
     *     existing_auto_topics: int,
     *     existing_manual_topics: int,
     *     existing_locked_topics: int,
     *     auto_topics_with_link_list_seed: int,
     *     auto_topics_with_product_cat_seed: int,
     *     estimated_stale_auto_topics: int|null,
     *     note: string
     * }
     */
    public function previewSeedEvidence(int $siteId): array
    {
        $empty = [
            'site_id' => $siteId,
            'current_catalog_link_seeds' => 0,
            'new_curated_link_seeds' => 0,
            'product_cat_seeds' => 0,
            'estimated_automatic_seed_set' => 0,
            'existing_topics' => 0,
            'existing_auto_topics' => 0,
            'existing_manual_topics' => 0,
            'existing_locked_topics' => 0,
            'auto_topics_with_link_list_seed' => 0,
            'auto_topics_with_product_cat_seed' => 0,
            'estimated_stale_auto_topics' => null,
            'note' => 'invalid_site',
        ];
        if ($siteId <= 0) {
            return $empty;
        }

        $curated = $this->curatedDomainLinkRows($siteId);
        $productCat = $this->productCatSeedRows($siteId);

        /** @var array<string, true> $seedKeys */
        $seedKeys = [];
        foreach ($curated as $row) {
            $key = mb_strtolower(trim($row['keyword']));
            if ($key !== '') {
                $seedKeys[$key] = true;
            }
        }
        foreach ($productCat as $row) {
            $key = mb_strtolower(trim($row['keyword']));
            if ($key !== '') {
                $seedKeys[$key] = true;
            }
        }

        $catalogLinkSeeds = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_seed', true)
            ->where('source', TopicKeywordSource::LINK_LIST)
            ->count();

        $existingTopics = SeoTopic::query()->where('site_id', $siteId)->count();
        $existingManual = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('source', 'manual')
            ->count();
        $existingLocked = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->count();
        $existingAuto = max(0, $existingTopics - $existingManual);

        $autoWithLinkList = (int) SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_seed', true)
            ->where('source', TopicKeywordSource::LINK_LIST)
            ->distinct()
            ->count('topic_id');
        $autoWithProductCat = (int) SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_seed', true)
            ->where('source', TopicKeywordSource::PRODUCT_CAT)
            ->distinct()
            ->count('topic_id');

        // Estimate: auto Topics whose current seed phrase is not in the new seed key set.
        $staleEstimate = null;
        $seedMemberships = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_seed', true)
            ->whereIn('source', [TopicKeywordSource::LINK_LIST, TopicKeywordSource::PRODUCT_CAT])
            ->get(['topic_id', 'keyword_id']);
        if ($seedMemberships->isNotEmpty()) {
            $keywordIds = $seedMemberships->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->unique()->all();
            $phrases = Keyword::query()
                ->whereIn('id', $keywordIds)
                ->pluck('phrase', 'id');
            /** @var array<int, true> $validTopicIds */
            $validTopicIds = [];
            foreach ($seedMemberships as $row) {
                $phrase = mb_strtolower(trim((string) ($phrases[(int) $row->keyword_id] ?? '')));
                if ($phrase !== '' && isset($seedKeys[$phrase])) {
                    $validTopicIds[(int) $row->topic_id] = true;
                }
            }
            $topicsWithAnySeed = $seedMemberships->pluck('topic_id')->map(static fn ($id): int => (int) $id)->unique()->count();
            $staleEstimate = max(0, $topicsWithAnySeed - count($validTopicIds));
        }

        return [
            'site_id' => $siteId,
            'current_catalog_link_seeds' => $catalogLinkSeeds,
            'new_curated_link_seeds' => count($curated),
            'product_cat_seeds' => count($productCat),
            'estimated_automatic_seed_set' => count($seedKeys),
            'existing_topics' => $existingTopics,
            'existing_auto_topics' => $existingAuto,
            'existing_manual_topics' => $existingManual,
            'existing_locked_topics' => $existingLocked,
            'auto_topics_with_link_list_seed' => $autoWithLinkList,
            'auto_topics_with_product_cat_seed' => $autoWithProductCat,
            'estimated_stale_auto_topics' => $staleEstimate,
            'note' => 'read_only_preview_no_topic_mutation',
        ];
    }

    /**
     * @return list<array{keyword_id: int, phrase: string, source: string, is_seed: true, confidence: float|null}>
     */
    private function linkListSeeds(int $siteId): array
    {
        $seeds = [];
        foreach ($this->curatedDomainLinkRows($siteId) as $row) {
            $keyword = $this->keywords->upsert(
                $row['keyword'],
                'internal',
                $siteId,
                $row['url'],
            );
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
        $seeds = [];
        foreach ($this->productCatSeedRows($siteId) as $row) {
            $keyword = $this->keywords->upsert(
                $row['keyword'],
                'internal',
                $siteId,
                $row['url'],
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
                'confidence' => 0.8,
            ];
        }

        return $seeds;
    }

    /**
     * Curated Domain Link List rows only (keyword + URL required). No title/slug fallback.
     *
     * @return list<array{keyword: string, url: string}>
     */
    private function curatedDomainLinkRows(int $siteId): array
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return [];
        }

        $payload = $this->promptContext->getRawPayloadForSite($site);
        $links = is_array($payload['links'] ?? null) ? $payload['links'] : [];

        return self::normalizeCuratedLinkRows($links);
    }

    /**
     * Normalize curated Domain Link List payload rows for Topic seeds.
     * Requires explicit keyword + URL — never invents seeds from WP titles/slugs.
     *
     * @param  list<mixed>  $links
     * @return list<array{keyword: string, url: string}>
     */
    public static function normalizeCuratedLinkRows(array $links): array
    {
        $out = [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($links as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rawKeyword = trim((string) ($row['keyword'] ?? ''));
            $url = trim((string) ($row['link'] ?? $row['url'] ?? ''));
            if ($rawKeyword === '' || $url === '') {
                continue;
            }
            $phrase = TopicNaming::canonicalName($rawKeyword) ?: $rawKeyword;
            $dedupe = mb_strtolower($phrase);
            if ($dedupe === '' || isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $out[] = [
                'keyword' => $phrase,
                'url' => $url,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{keyword: string, url: string, term_id: int, parent_term_id: int}>
     */
    private function productCatSeedRows(int $siteId): array
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return [];
        }

        $websiteType = mb_strtolower(trim((string) ($site->getMeta('seo_domain_type') ?? '')));
        if (! $this->allowsProductCatSeeds($websiteType)) {
            return [];
        }

        $rows = $this->productCats->forSite($site);
        $out = [];
        foreach ($rows as $row) {
            $keyword = trim((string) ($row['keyword'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            $termId = (int) ($row['term_id'] ?? 0);
            if ($keyword === '' || $url === '' || $termId <= 0) {
                continue;
            }
            if (! array_key_exists('parent_term_id', $row)) {
                continue;
            }
            $out[] = [
                'keyword' => TopicNaming::canonicalName($keyword) ?: $keyword,
                'url' => $url,
                'term_id' => $termId,
                'parent_term_id' => (int) $row['parent_term_id'],
            ];
        }

        return $out;
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
     * All verified product_cat terms at any depth (parent_term_id = 0 or > 0).
     *
     * Rejects individual products, non-product_cat taxonomies, invalid term_id,
     * and rows that fail SiteMcpProductCatIdentity::normalizeVerified().
     *
     * @param  list<array<string, mixed>>  $productCategories
     * @return list<array<string, mixed>>
     */
    public static function verifiedProductCategories(array $productCategories): array
    {
        $verifiedRows = [];
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
            // parent_term_id must be present (0 = root, >0 = nested) — never invent hierarchy.
            if (! array_key_exists('parent_term_id', $verified)) {
                continue;
            }
            $pageType = mb_strtolower(trim((string) ($verified['page_type'] ?? $row['page_type'] ?? '')));
            if (in_array($pageType, ['product', 'products'], true)) {
                continue;
            }
            $verifiedRows[] = $verified;
        }

        return $verifiedRows;
    }
}
