<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteContext;

use App\Models\Site;
use Omnichannel\Addons\Seo\Services\SiteContext\Dto\SiteContext;

/**
 * Canonical application boundary for site-level runtime / intelligence context.
 *
 * Preset composition of site/content/seo/publishing slices (not the only read path —
 * prefer ContextRegistry for individual slices).
 *
 * Distinct from Site Knowledge Profile (prompt tone/CTA/links — search-foundation SiteMcp*).
 *
 * Schema id `site.mcp.v1` is a stable compatibility contract id.
 * Future HTTP wraps this class or ContextRegistry (no HTTP loopback internally).
 */
final class SiteContextGateway
{
    public const SCHEMA = SiteContext::SCHEMA;

    public function __construct(
        private readonly SiteContextAssembler $assembler,
    ) {}

    public function forSite(Site $site, string $periodKey): SiteContext
    {
        return $this->assembler->assemble($site, $periodKey);
    }

    public function forSiteId(int $siteId, string $periodKey): ?SiteContext
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return null;
        }

        return $this->forSite($site, $periodKey);
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        return $this->assembler->sourceUpdatedAt($site);
    }

    /**
     * @return array<string, mixed>
     */
    public function envelope(Site $site, string $periodKey): array
    {
        return $this->forSite($site, $periodKey)->toEnvelopeArray();
    }
}
