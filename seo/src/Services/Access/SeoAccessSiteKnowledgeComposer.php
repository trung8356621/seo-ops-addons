<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Access;

use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpDraft;
use Omnichannel\Addons\SiteSync\Services\Capability\SiteCapabilityResolver;

/**
 * Curated website knowledge for SEO Access /site.
 *
 * Official Knowledge Profile (seo_domain_prompt_context + site metas) wins.
 * Draft Site MCP fills gaps only when official values are empty.
 */
class SeoAccessSiteKnowledgeComposer
{
    public const SCHEMA = 'seo.access.site.v1';

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly SiteMcpDraft $draftStore,
        private readonly SiteCapabilityResolver $capabilities,
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
                'important_pages' => [],
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

        $tone = $this->firstNonEmpty(
            trim((string) ($official['tone'] ?? '')),
            trim((string) ($draftContent['tone'] ?? '')),
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
        $importantPages = is_array($draft['important_pages'] ?? null) ? $draft['important_pages'] : [];

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
                'tone' => $tone !== '' ? $tone : null,
                'business_summary' => $businessSummary !== '' ? $businessSummary : null,
                'cta_instructions' => $cta !== '' ? $cta : null,
            ],
            'contact' => $contact,
            'important_pages' => $importantPages,
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
