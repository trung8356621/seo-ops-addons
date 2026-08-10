<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Console;

use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteLinkCatalogReconciler;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Backfill seo_domain_prompt_context.links[] into seo_site_manual_links / catalog.
 */
final class BackfillSiteManualLinksCommand extends Command
{
    protected $signature = 'seo:site-sync-backfill-manual-links {site_id? : Optional core sites.id}';

    protected $description = 'Backfill manual domain links JSON into SiteLinkCatalog tables';

    public function handle(
        SiteDomainPromptContextService $promptContext,
        SiteLinkCatalogReconciler $catalog,
    ): int {
        $siteId = $this->argument('site_id');
        $query = Site::query()->with('metas');
        if ($siteId !== null && $siteId !== '') {
            $query->whereKey((int) $siteId);
        }

        $count = 0;
        foreach ($query->cursor() as $site) {
            $payload = $promptContext->getRawPayloadForSite($site);
            $links = is_array($payload['links'] ?? null) ? $payload['links'] : [];
            if ($links === []) {
                continue;
            }
            $n = $catalog->syncManualLinksFromSettings($site, $links);
            $this->line("site {$site->id}: {$n} manual links");
            $count += $n;
        }

        $this->info("Backfilled {$count} manual links.");

        return self::SUCCESS;
    }
}
