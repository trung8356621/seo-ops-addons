<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\CtaManager;

use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\PromptLanguageVariableService;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpContextAssembler;

/**
 * Builds a short, sourced Site Context snapshot for CTA template generation.
 * Never invents prices, certifications, or unverified advantages.
 */
class SiteCtaTemplateContextBuilder
{
    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly SiteMcpContextAssembler $assembler,
        private readonly PromptLanguageVariableService $languageVariable,
    ) {}

    /**
     * @return array{
     *     language: string,
     *     site_short_description: string,
     *     site_context: string,
     *     warnings: list<string>,
     *     available_contact_types: list<string>
     * }
     */
    public function build(Site $site): array
    {
        $payload = $this->promptContext->getForSite($site);
        $websiteType = trim((string) ($site->getMeta('seo_domain_type') ?? ''));
        $company = trim((string) ($payload['company_short_identity'] ?? ''));
        $short = trim((string) ($payload['short_description'] ?? ''));
        $domain = trim((string) $site->domain);

        $contactTypes = [];
        foreach (is_array($payload['cta'] ?? null) ? $payload['cta'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }
            $contactTypes[$type] = true;
        }
        $availableTypes = array_keys($contactTypes);

        $draft = [
            'site' => [
                'domain' => $domain,
                'company_short_identity' => $company,
                'short_description' => $short,
                'website_type' => $websiteType,
            ],
            'content_context' => [
                'business_summary' => $short,
                'cta_instructions' => '',
            ],
        ];

        $overlay = [
            'company_short_identity' => $company,
            'short_description' => $short,
            'cta_instructions' => '',
            'resolved_contacts' => [],
        ];

        // Do not pass raw destinations into the AI prompt — only channel availability.
        $availabilityLines = [];
        foreach ($availableTypes as $type) {
            $availabilityLines[$type] = 'available';
        }

        $preview = $this->assembler->articleContext($draft, array_merge($overlay, [
            'resolved_contacts' => $availabilityLines,
        ]));

        $warnings = [];
        if ($short === '') {
            $warnings[] = 'Thiếu mô tả ngắn Site (short_description).';
        }
        if ($company === '') {
            $warnings[] = 'Thiếu định danh công ty ngắn.';
        }
        if ($websiteType === '') {
            $warnings[] = 'Thiếu website type.';
        }
        if ($availableTypes === []) {
            $warnings[] = 'Chưa có kênh liên hệ đã cấu hình — CTA chỉ dùng token khi Site có contact.';
        }
        if ($preview['has_unresolved']) {
            $warnings[] = 'Site context còn placeholder chưa resolve: '.implode(', ', $preview['unresolved']);
        }

        $contextLines = [
            'Domain: '.($domain !== '' ? $domain : '(unknown)'),
            'Company: '.($company !== '' ? $company : '(empty)'),
            'Website type: '.($websiteType !== '' ? $websiteType : '(empty)'),
            'Business description: '.($short !== '' ? $short : '(empty)'),
            'Verified contact channels: '.($availableTypes !== [] ? implode(', ', $availableTypes) : '(none)'),
            'Allowed dynamic tokens: '.($availableTypes !== []
                ? implode(', ', array_map(static fn (string $t): string => '[[cta:'.$t.']]', $availableTypes))
                : '(none — do not invent contact details)'),
            'Source: SiteDomainPromptContext + SiteMcpContextAssembler (verified fields only).',
        ];

        return [
            'language' => $this->languageVariable->resolve($site, null),
            'site_short_description' => $short,
            'site_context' => implode("\n", $contextLines),
            'warnings' => $warnings,
            'available_contact_types' => $availableTypes,
        ];
    }
}
