<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

use App\Models\Site;
use App\Support\RuntimeLogger;

/**
 * Generate Site MCP Knowledge Profile draft.
 *
 * Hard rules:
 * - product_cat parent=0 → Main Topics (production / e-commerce)
 * - product → never Main Topic / never important_pages
 * - news → Main Topics remain manual (empty)
 * - never overwrite official Site MCP fields
 */
final class SiteMcpGenerator
{
    private const MAX_IMPORTANT_PAGES = 60;

    private const MAX_MAIN_TOPICS = 60;

    public function __construct(
        private readonly SiteMcpDiscovery $discovery,
        private readonly SiteMcpDraft $draftStore,
        private readonly SiteMcpKeywordExtractor $keywords,
        private readonly SiteMcpContactDiscovery $contactDiscovery,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generateDraft(Site $site): array
    {
        $discovered = $this->discovery->discover($site);
        $draft = $this->buildFromDiscovery($discovered);
        $this->draftStore->put($site, $draft);

        RuntimeLogger::info('seo.site_mcp.draft_generated', [
            'site_id' => (int) $site->id,
            'website_type' => (string) ($draft['site']['website_type'] ?? ''),
            'discovery_strategy' => (string) ($draft['site']['discovery_strategy'] ?? ''),
            'important_pages' => count($draft['important_pages'] ?? []),
            'main_topics' => count($draft['keyword_context']['main_topics'] ?? []),
            'product_categories' => (int) ($draft['counts']['product_cat'] ?? 0),
            'products_seen' => (int) ($draft['counts']['product'] ?? 0),
            'official_exists' => (bool) ($draft['generation']['official_site_mcp_exists'] ?? false),
            'official_fields_modified' => false,
        ]);

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $discovered
     * @return array<string, mixed>
     */
    public function buildFromDiscovery(array $discovered): array
    {
        $draft = SiteMcpDraft::empty();
        $websiteType = $this->normalizeWebsiteType((string) ($discovered['website_type'] ?? 'news'));
        $strategy = (string) ($discovered['discovery_strategy'] ?? $this->defaultStrategy($websiteType));
        $official = is_array($discovered['official'] ?? null) ? $discovered['official'] : [];
        $officialExists = (bool) ($discovered['official_exists'] ?? false);

        $shortDescription = trim((string) ($official['short_description'] ?? ''));
        $companyShort = trim((string) ($official['company_short_identity'] ?? ''));
        $tone = trim((string) ($official['tone'] ?? ''));
        $ctaInstructions = trim((string) ($official['cta_intro'] ?? ''));

        $productCategories = is_array($discovered['product_categories'] ?? null)
            ? $discovered['product_categories']
            : [];
        $serviceCategories = is_array($discovered['service_categories'] ?? null)
            ? $discovered['service_categories']
            : [];
        $products = is_array($discovered['products'] ?? null) ? $discovered['products'] : [];
        $newsCandidates = is_array($discovered['news_candidates'] ?? null)
            ? $discovered['news_candidates']
            : [];
        $stats = is_array($discovered['counts'] ?? null) ? $discovered['counts'] : [];

        $draft['site'] = [
            'domain' => (string) ($discovered['domain'] ?? ''),
            'website_type' => $websiteType,
            'discovery_strategy' => $strategy,
            'site_title' => (string) ($discovered['site_title'] ?? ''),
            'brand' => (string) ($discovered['brand'] ?? ''),
            'company_short_identity' => $this->clampCompanyShortIdentity(
                $companyShort !== '' ? $companyShort : (string) ($discovered['brand'] ?? '')
            ),
            'short_description' => $shortDescription,
        ];

        $productCatCount = (int) ($stats['product_cat_total'] ?? $stats['product_cat'] ?? count($productCategories));
        $productCount = (int) ($stats['product'] ?? count($products));
        $rootCategoriesPreview = $this->rootProductCategories($productCategories);
        $rootProductCatCount = (int) ($stats['root_product_cat'] ?? count($rootCategoriesPreview));
        $childProductCatCount = (int) ($stats['child_product_cat'] ?? max(0, $productCatCount - $rootProductCatCount));

        $availability = is_array($discovered['availability'] ?? null) ? $discovered['availability'] : [];
        $taxonomyAvailability = (string) ($availability['product_cat_taxonomy'] ?? SiteMcpProductCatIdentity::AVAILABILITY_AVAILABLE);
        $taxonomyCapability = is_array($discovered['taxonomy_capability'] ?? null)
            ? $discovered['taxonomy_capability']
            : [];

        $draft['counts'] = [
            'post' => (int) ($stats['post'] ?? 0),
            'page' => (int) ($stats['page'] ?? 0),
            'product' => $productCount,
            'product_cat' => $productCatCount,
            'product_cat_total' => $productCatCount,
            'root_product_cat' => $rootProductCatCount,
            'child_product_cat' => $childProductCatCount,
            'incomplete_product_cat' => (int) ($stats['incomplete_product_cat'] ?? 0),
            'attachment' => (int) ($stats['attachment'] ?? 0),
            'product_categories' => $productCatCount,
            'products' => $productCount,
            'service_categories' => count($serviceCategories),
            'excluded' => [
                'product' => $productCount,
                'child_product_cat' => $childProductCatCount,
            ],
        ];
        $draft['availability'] = [
            'product_cat_taxonomy' => $taxonomyAvailability,
        ];

        $warnings = [];
        if ($officialExists) {
            $warnings[] = 'Official Site MCP exists — draft only; official fields not modified.';
        }

        $mainTopicRecords = [];
        $importantPages = [];
        $discoveryCandidates = [];

        if ($strategy === 'news_manual') {
            $warnings[] = 'News site: Main Topics remain MANUAL — generator did not auto-select.';
            $warnings[] = 'News site: important pages stay manual — generator did not auto-select pages.';
            foreach ($newsCandidates as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $discoveryCandidates[] = $this->toCandidateRow($candidate, selected: false);
            }
            $mainTopicRecords = [];
            $importantPages = [];
        } else {
            if ($taxonomyAvailability === SiteMcpProductCatIdentity::AVAILABILITY_UNAVAILABLE
                || (($taxonomyCapability['known'] ?? false) && ! ($taxonomyCapability['product_category_taxonomy_export'] ?? false)
                    && $rootCategoriesPreview === [])) {
                $warnings[] = SiteMcpProductCatIdentity::WARNING_CAPABILITY_MISSING;
            }

            if ($strategy === 'production_catalog' && (bool) ($discovered['has_woocommerce_catalog'] ?? false)) {
                $warnings[] = 'Production WooCommerce catalog detected — using verified product_cat parent=0 only, not individual products.';
            }
            if ($strategy === 'ecommerce_catalog') {
                $warnings[] = 'E-commerce: individual products excluded; verified product_cat parent=0 only.';
            }

            $rootCategories = $rootCategoriesPreview;
            if ($rootCategories === []) {
                if ($taxonomyAvailability === SiteMcpProductCatIdentity::AVAILABILITY_UNAVAILABLE) {
                    // Do not claim the catalog has no roots when export is unsupported.
                } elseif ($taxonomyAvailability === SiteMcpProductCatIdentity::AVAILABILITY_INCOMPLETE) {
                    $warnings[] = 'PRODUCT_CATEGORY_TAXONOMY_INCOMPLETE';
                } else {
                    $warnings[] = 'ROOT_PRODUCT_CATEGORIES_NOT_AVAILABLE';
                }
                $mainTopicRecords = [];
                $importantPages = [];
            } else {
                [$mainTopicRecords, $importantPages] = $this->buildMainTopicsFromCategories($rootCategories);
            }

            if ($productCount > 0) {
                $warnings[] = 'product: '.$productCount.' (excluded) from Main Topics / Important Pages.';
            }

            // Hard exclude individual product posts only.
            $importantPages = array_values(array_filter(
                $importantPages,
                fn (array $page): bool => ! $this->isProductPageType((string) ($page['type'] ?? $page['page_type'] ?? '')),
            ));
            $mainTopicRecords = array_values(array_filter(
                $mainTopicRecords,
                fn (array $record): bool => ($record['source_type'] ?? '') !== 'product'
                    && ($record['taxonomy'] ?? '') === 'product_cat'
                    && (int) ($record['parent_term_id'] ?? -1) === 0,
            ));
        }

        $mainTopics = $this->keywords->uniqueTopics(array_map(
            static fn (array $r): string => (string) ($r['keyword'] ?? ''),
            $mainTopicRecords,
        ));

        $draft['content_context'] = [
            'tone' => $tone,
            'business_summary' => $shortDescription,
            'cta_instructions' => $ctaInstructions,
        ];
        $draft['contact'] = $this->buildContact($discovered, $official);
        $draft['important_pages'] = array_slice($importantPages, 0, self::MAX_IMPORTANT_PAGES);
        $draft['discovery_candidates'] = $discoveryCandidates;
        $draft['keyword_context'] = [
            'main_topics' => $mainTopics,
            'main_topic_records' => $mainTopicRecords,
            'warnings' => array_values(array_unique($warnings)),
        ];
        $draft['generation'] = [
            'generated_at' => gmdate('c'),
            'source' => SiteMcpDraft::SOURCE,
            'sync_run' => $discovered['sync_run_id'] ?? null,
            'version' => SiteMcpDraft::VERSION,
            'official_site_mcp_exists' => $officialExists,
            'official_fields_modified' => false,
        ];

        return $draft;
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

            if (($row['taxonomy'] ?? null) !== 'product_cat') {
                continue;
            }

            if (! array_key_exists('parent_term_id', $row)) {
                continue;
            }

            if ((int) $row['parent_term_id'] !== 0) {
                continue;
            }

            if (! isset($row['term_id']) || (int) $row['term_id'] <= 0) {
                continue;
            }

            // Individual product posts never qualify — even if mis-bucketed.
            if ($this->isProductPageType((string) ($row['page_type'] ?? ''))) {
                continue;
            }

            $roots[] = $row;
        }

        return $roots;
    }

    /**
     * @param  list<array<string, mixed>>  $categoryPool
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function buildMainTopicsFromCategories(array $categoryPool): array
    {
        $records = [];
        $importantPages = [];
        $seenTerm = [];
        $seenKeyword = [];

        foreach ($categoryPool as $category) {
            if (! is_array($category)) {
                continue;
            }

            $termId = (int) ($category['term_id'] ?? 0);
            $termKey = 'product_cat:'.$termId;
            if ($termId > 0 && isset($seenTerm[$termKey])) {
                continue;
            }

            // Pool already fail-closed; do NOT reject by product-title heuristic.
            $extracted = $this->keywords->extractCategoryTopic($category);
            $keyword = $extracted['keyword'];
            if ($keyword === '') {
                continue;
            }

            $key = mb_strtolower($keyword);
            if (isset($seenKeyword[$key])) {
                continue;
            }
            if ($termId > 0) {
                $seenTerm[$termKey] = true;
            }
            $seenKeyword[$key] = true;

            $record = $this->topicRecord($category, $extracted);
            $records[] = $record;
            $importantPages[] = [
                'url' => (string) ($category['url'] ?? ''),
                'title' => (string) ($category['title'] ?? $category['name'] ?? ''),
                'seo_title' => (string) ($category['seo_title'] ?? ''),
                'type' => (string) ($record['source_type'] ?? 'product_category'),
                'page_type' => (string) ($record['source_type'] ?? 'product_category'),
                'keyword' => $keyword,
                'source' => $extracted['source'],
                'confidence' => $extracted['confidence'],
                'taxonomy' => 'product_cat',
                'term_id' => $record['term_id'] ?? null,
                'parent_term_id' => 0,
            ];

            if (count($records) >= self::MAX_MAIN_TOPICS) {
                break;
            }
        }

        return [$records, $importantPages];
    }

    /**
     * @param  array<string, mixed>  $category
     * @param  array{keyword: string, source: string, confidence: float}  $extracted
     * @return array<string, mixed>
     */
    private function topicRecord(array $category, array $extracted): array
    {
        return [
            'keyword' => $extracted['keyword'],
            'source_type' => 'product_category',
            'taxonomy' => 'product_cat',
            'term_id' => (int) ($category['term_id'] ?? 0),
            'url' => (string) ($category['url'] ?? ''),
            'parent_term_id' => 0,
            'confidence' => $extracted['confidence'],
            'extract_source' => $extracted['source'],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function toCandidateRow(array $candidate, bool $selected): array
    {
        $extracted = $this->keywords->extractCategoryTopic($candidate);

        return [
            'url' => (string) ($candidate['url'] ?? ''),
            'title' => (string) ($candidate['title'] ?? ''),
            'page_type' => (string) ($candidate['page_type'] ?? ''),
            'keyword' => $extracted['keyword'],
            'selected' => $selected,
            'note' => 'Reference only — not auto-selected for news.',
        ];
    }

    private function isProductPageType(string $type): bool
    {
        $type = mb_strtolower(trim($type));

        return in_array($type, ['product', 'products'], true);
    }

    /**
     * @param  array<string, mixed>  $discovered
     * @param  array<string, mixed>  $official
     * @return array{phones: list<array<string, mixed>>, emails: list<array<string, mixed>>, socials: list<array<string, mixed>>}
     */
    private function buildContact(array $discovered, array $official): array
    {
        $html = (string) ($discovered['homepage_html'] ?? '');
        $sourceUrl = (string) ($discovered['homepage_url'] ?? $discovered['domain'] ?? '');
        $parsed = $this->contactDiscovery->parse($html, $sourceUrl);

        $phones = $parsed['phones'];
        $emails = $parsed['emails'];
        $socials = $parsed['socials'];

        // Official contacts as reference candidates (draft only — not applied).
        foreach (['phone_1', 'phone_2', 'phone_3'] as $key) {
            $value = trim((string) ($official[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $phones = $this->mergeContactValue($phones, [
                'value' => $value,
                'source_url' => $sourceUrl,
                'source_method' => 'official_slot',
                'confidence' => 0.5,
            ]);
        }

        $officialPhones = is_array($official['phones'] ?? null) ? $official['phones'] : [];
        foreach ($officialPhones as $row) {
            $value = is_array($row) ? trim((string) ($row['value'] ?? '')) : trim((string) $row);
            if ($value === '') {
                continue;
            }
            $phones = $this->mergeContactValue($phones, [
                'value' => $value,
                'source_url' => $sourceUrl,
                'source_method' => 'official_list',
                'confidence' => 0.5,
            ]);
        }

        foreach (['email_1', 'email_2', 'email_3'] as $key) {
            $value = trim((string) ($official[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $emails = $this->mergeContactValue($emails, [
                'value' => $value,
                'source_url' => $sourceUrl,
                'source_method' => 'official_slot',
                'confidence' => 0.5,
            ]);
        }

        $officialEmails = is_array($official['emails'] ?? null) ? $official['emails'] : [];
        foreach ($officialEmails as $row) {
            $value = is_array($row) ? trim((string) ($row['value'] ?? '')) : trim((string) $row);
            if ($value === '') {
                continue;
            }
            $emails = $this->mergeContactValue($emails, [
                'value' => $value,
                'source_url' => $sourceUrl,
                'source_method' => 'official_list',
                'confidence' => 0.5,
            ]);
        }

        $officialSocials = is_array($official['socials'] ?? null) ? $official['socials'] : [];
        foreach ($officialSocials as $row) {
            if (! is_array($row)) {
                continue;
            }
            $network = mb_strtolower(trim((string) ($row['network'] ?? $row['type'] ?? '')));
            $url = trim((string) ($row['url'] ?? $row['value'] ?? ''));
            if ($network === '' || $url === '') {
                continue;
            }
            $socials[] = [
                'network' => $network,
                'url' => $url,
                'value' => $url,
                'source_url' => $sourceUrl,
                'source_method' => 'official_list',
                'confidence' => 0.5,
            ];
        }

        // Legacy CTA social types.
        foreach (is_array($official['cta'] ?? null) ? $official['cta'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if (in_array($type, SiteMcpContactDiscovery::SOCIAL_NETWORKS, true) || $type === 'facebook' || $type === 'zalo') {
                $socials[] = [
                    'network' => $type === 'twitter' ? 'x' : $type,
                    'url' => $value,
                    'value' => $value,
                    'source_url' => $sourceUrl,
                    'source_method' => 'official_cta',
                    'confidence' => 0.5,
                ];
            }
        }

        return [
            'phones' => array_values($phones),
            'emails' => array_values($emails),
            'socials' => array_values($this->uniqueSocials($socials)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $list
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function mergeContactValue(array $list, array $row): array
    {
        $value = mb_strtolower(trim((string) ($row['value'] ?? '')));
        if ($value === '') {
            return $list;
        }
        foreach ($list as $existing) {
            if (mb_strtolower(trim((string) ($existing['value'] ?? ''))) === $value) {
                return $list;
            }
        }
        $list[] = $row;

        return $list;
    }

    /**
     * @param  list<array<string, mixed>>  $socials
     * @return list<array<string, mixed>>
     */
    private function uniqueSocials(array $socials): array
    {
        $seen = [];
        $out = [];
        foreach ($socials as $row) {
            $key = mb_strtolower(trim((string) ($row['network'] ?? ''))).'|'
                .mb_strtolower(trim((string) ($row['url'] ?? $row['value'] ?? '')));
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    private function clampCompanyShortIdentity(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (mb_strlen($value) <= SiteMcpDraft::COMPANY_SHORT_IDENTITY_MAX) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, SiteMcpDraft::COMPANY_SHORT_IDENTITY_MAX));
    }

    private function normalizeWebsiteType(string $type): string
    {
        $type = mb_strtolower(trim($type));

        return match ($type) {
            'production' => 'production',
            'e-commerce', 'ecommerce', 'e_commerce' => 'e-commerce',
            default => 'news',
        };
    }

    private function defaultStrategy(string $websiteType): string
    {
        return match ($websiteType) {
            'production' => 'production_catalog',
            'e-commerce' => 'ecommerce_catalog',
            default => 'news_manual',
        };
    }
}
