<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Access;

use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpDraft;
use Omnichannel\Addons\Seo\Services\SiteContext\Aggregators\SiteContentDistributionAggregator;
use Omnichannel\Addons\SiteSync\Services\Capability\SiteCapabilityResolver;

/**
 * Curated website knowledge for SEO Access /site.
 *
 * Official Knowledge Profile (seo_domain_prompt_context + site metas) wins.
 * Draft Site MCP fills gaps only when official values are empty.
 *
 * Site/domain tone is retired from AI writing resolution and is never exposed here.
 */
class SeoAccessSiteKnowledgeComposer
{
    public const SCHEMA = 'seo.access.site.v2';

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly SiteMcpDraft $draftStore,
        private readonly SiteCapabilityResolver $capabilities,
        private readonly SiteContentDistributionAggregator $distribution,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compose(int $siteId): array
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return [
                'schema' => self::SCHEMA,
                'site_ref' => 'site:'.$siteId,
                'identity' => null,
                'writing_context' => null,
                'contact' => null,
                'important_pages' => [
                    'total' => null,
                    'returned' => 0,
                    'truncated' => false,
                    'items' => [],
                ],
                'content_distribution' => [
                    'posts' => null,
                    'pages' => null,
                    'categories' => null,
                    'products' => null,
                    'product_categories' => null,
                    'other' => null,
                    'available' => false,
                ],
                'sitemaps' => [
                    'available' => false,
                    'urls' => [],
                ],
                'available' => false,
            ];
        }

        return $this->composeForSite($site);
    }

    /**
     * @return array<string, mixed>
     */
    public function composeForSite(Site $site): array
    {
        $siteId = (int) $site->id;
        $official = $this->promptContext->getRawPayloadForSite($site);
        $draft = $this->draftStore->get($site) ?? [];
        $draftSite = is_array($draft['site'] ?? null) ? $draft['site'] : [];
        $draftContent = is_array($draft['content_context'] ?? null) ? $draft['content_context'] : [];
        $draftContact = is_array($draft['contact'] ?? null) ? $draft['contact'] : [];
        $draftCounts = is_array($draft['counts'] ?? null) ? $draft['counts'] : [];

        $domain = trim((string) $site->domain);
        $websiteType = $this->firstNonEmpty(
            trim((string) ($site->getMeta('seo_domain_type') ?? '')),
            trim((string) ($draftSite['website_type'] ?? '')),
        );
        $siteTitle = $this->firstNonEmpty(
            trim((string) ($site->getMeta('seo_site_title') ?? '')),
            trim((string) ($draftSite['site_title'] ?? '')),
        );
        $brand = $this->firstNonEmpty(
            trim((string) ($site->getMeta('seo_brand_name') ?? '')),
            trim((string) ($draftSite['brand'] ?? '')),
        );
        $company = $this->firstNonEmpty(
            trim((string) ($official['company_short_identity'] ?? '')),
            trim((string) ($draftSite['company_short_identity'] ?? '')),
        );
        $shortDescription = $this->firstNonEmpty(
            trim((string) ($official['short_description'] ?? '')),
            trim((string) ($draftSite['short_description'] ?? '')),
            trim((string) ($draftContent['business_summary'] ?? '')),
        );
        $discoveryStrategy = $this->firstNonEmpty(
            trim((string) ($draftSite['discovery_strategy'] ?? '')),
        );

        $businessSummary = $this->firstNonEmpty(
            trim((string) ($draftContent['business_summary'] ?? '')),
            $shortDescription,
        );
        $cta = $this->firstNonEmpty(
            trim((string) ($official['cta_intro'] ?? '')),
            trim((string) ($draftContent['cta_instructions'] ?? '')),
        );

        $contact = $this->composeContact($official, $draftContact);
        $rawImportantPages = is_array($draft['important_pages'] ?? null) ? $draft['important_pages'] : [];
        $importantPages = $this->composeImportantPages(
            $rawImportantPages,
            $discoveryStrategy,
            $websiteType,
            $draftCounts,
        );

        return [
            'schema' => self::SCHEMA,
            'site_ref' => 'site:'.$siteId,
            'identity' => [
                'domain' => $domain !== '' ? $domain : null,
                'site_title' => $siteTitle !== '' ? $siteTitle : null,
                'website_type' => $websiteType !== '' ? $websiteType : null,
                'discovery_strategy' => $discoveryStrategy !== '' ? $discoveryStrategy : null,
                'brand' => $brand !== '' ? $brand : null,
                'company_short_identity' => $company !== '' ? $company : null,
                'short_description' => $shortDescription !== '' ? $shortDescription : null,
                'cms' => $this->resolveCms($site),
            ],
            'writing_context' => [
                'business_summary' => $businessSummary !== '' ? $businessSummary : null,
                'cta_instructions' => $cta !== '' ? $cta : null,
            ],
            'contact' => $contact,
            'important_pages' => $importantPages,
            'content_distribution' => $this->composeDistribution($siteId),
            'sitemaps' => [
                'available' => false,
                'urls' => [],
            ],
            'available' => true,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Compact site row for service-level access index.
     *
     * @return array{site_ref: string, domain: string|null, title: string|null}
     */
    public function listRow(Site $site): array
    {
        $domain = trim((string) $site->domain);
        $title = trim((string) ($site->getMeta('seo_site_title') ?? ''));
        if ($title === '') {
            $draft = $this->draftStore->get($site);
            $draftSite = is_array($draft['site'] ?? null) ? $draft['site'] : [];
            $title = trim((string) ($draftSite['site_title'] ?? ''));
        }

        return [
            'site_ref' => 'site:'.(int) $site->id,
            'domain' => $domain !== '' ? $domain : null,
            'title' => $title !== '' ? $title : null,
        ];
    }

    private function resolveCms(Site $site): ?string
    {
        $manifest = $this->capabilities->forSite($site);
        if ($manifest === null) {
            return null;
        }

        // Capability manifest is WordPress-bridge sourced when present.
        return 'wordpress';
    }

    /**
     * @param  list<mixed>  $rawPages
     * @param  array<string, mixed>  $draftCounts
     * @return array{total: int|null, returned: int, truncated: bool, items: list<array<string, mixed>>}
     */
    private function composeImportantPages(
        array $rawPages,
        string $discoveryStrategy,
        string $websiteType,
        array $draftCounts,
    ): array {
        $items = [];
        foreach ($rawPages as $page) {
            if (! is_array($page)) {
                continue;
            }
            $items[] = $this->compactImportantPage($page);
        }

        $returned = count($items);
        $total = $this->resolveImportantPagesTotal($discoveryStrategy, $websiteType, $draftCounts, $returned);
        $truncated = $total !== null && $returned < $total;

        return [
            'total' => $total,
            'returned' => $returned,
            'truncated' => $truncated,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $draftCounts
     */
    private function resolveImportantPagesTotal(
        string $discoveryStrategy,
        string $websiteType,
        array $draftCounts,
        int $returned,
    ): ?int {
        $catalogStrategies = ['production_catalog', 'ecommerce_catalog'];
        $catalogTypes = ['production', 'e-commerce'];

        $isCatalog = in_array($discoveryStrategy, $catalogStrategies, true)
            || ($discoveryStrategy === '' && in_array($websiteType, $catalogTypes, true));

        if (! $isCatalog) {
            // News / manual (and unknown strategies): no reliable verified total.
            return null;
        }

        if (array_key_exists('root_product_cat', $draftCounts)) {
            return max(0, (int) $draftCounts['root_product_cat']);
        }

        // Prefer not to fabricate; fall back to returned when count missing.
        return $returned > 0 ? $returned : null;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function compactImportantPage(array $page): array
    {
        $type = trim((string) ($page['page_type'] ?? $page['type'] ?? ''));
        $out = [
            'url' => $this->nullableString($page['url'] ?? null),
            'title' => $this->nullableString($page['title'] ?? null),
            'seo_title' => $this->nullableString($page['seo_title'] ?? null),
            'page_type' => $type !== '' ? $type : null,
            'type' => $type !== '' ? $type : null,
            'keyword' => $this->nullableString($page['keyword'] ?? null),
            'taxonomy' => $this->nullableString($page['taxonomy'] ?? null),
        ];

        if (array_key_exists('term_id', $page) && $page['term_id'] !== null && $page['term_id'] !== '') {
            $out['term_id'] = (int) $page['term_id'];
        }
        if (array_key_exists('parent_term_id', $page) && $page['parent_term_id'] !== null && $page['parent_term_id'] !== '') {
            $out['parent_term_id'] = (int) $page['parent_term_id'];
        }

        return $out;
    }

    /**
     * @return array{
     *   posts: ?int,
     *   pages: ?int,
     *   categories: ?int,
     *   products: ?int,
     *   product_categories: ?int,
     *   other: ?int,
     *   available: bool
     * }
     */
    private function composeDistribution(int $siteId): array
    {
        $raw = $this->distribution->aggregate($siteId);

        return [
            'posts' => $raw['posts'] ?? null,
            'pages' => $raw['pages'] ?? null,
            'categories' => $raw['categories'] ?? null,
            'products' => $raw['products'] ?? null,
            'product_categories' => $raw['product_categories'] ?? null,
            'other' => $raw['other'] ?? null,
            'available' => (bool) ($raw['available'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $official
     * @param  array<string, mixed>  $draftContact
     * @return array<string, mixed>
     */
    private function composeContact(array $official, array $draftContact): array
    {
        $phones = is_array($official['phones'] ?? null) && $official['phones'] !== []
            ? $official['phones']
            : (is_array($draftContact['phones'] ?? null) ? $draftContact['phones'] : []);
        $emails = is_array($official['emails'] ?? null) && $official['emails'] !== []
            ? $official['emails']
            : (is_array($draftContact['emails'] ?? null) ? $draftContact['emails'] : []);
        $socials = is_array($official['socials'] ?? null) && $official['socials'] !== []
            ? $official['socials']
            : (is_array($draftContact['socials'] ?? null) ? $draftContact['socials'] : []);
        $address = $this->firstNonEmpty(
            trim((string) ($official['address'] ?? '')),
            trim((string) ($draftContact['address'] ?? '')),
        );

        return [
            'phones' => $phones,
            'emails' => $emails,
            'socials' => $socials,
            'address' => $address !== '' ? $address : null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
