<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReconcileFocusArticleTopicsService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReclusterTopicClustersService;

/**
 * Repair Focus Article ⇒ Topic invariant for a site.
 * Prefer --recluster for full rebuild; --reconcile-only for safe orphan attach/singleton.
 */
final class ReconcileFocusArticleTopicsCommand extends Command
{
    protected $signature = 'seo:topics:reconcile-focus
        {--site= : Site ID (required)}
        {--recluster : Run full-domain Recluster (includes Focus reconcile)}
        {--reconcile-only : Only reconcile Focus Article orphans (no full wipe)}';

    protected $description = 'Ensure every SEO keyword with a Focus Article belongs to a Topic';

    public function handle(
        ReclusterTopicClustersService $recluster,
        ReconcileFocusArticleTopicsService $reconciler,
    ): int {
        $siteId = (int) $this->option('site');
        if ($siteId <= 0) {
            $this->error('--site is required');

            return self::FAILURE;
        }

        $doRecluster = (bool) $this->option('recluster');
        $reconcileOnly = (bool) $this->option('reconcile-only');
        if (! $doRecluster && ! $reconcileOnly) {
            $reconcileOnly = true;
        }

        if ($doRecluster) {
            $this->info("Reclustering site {$siteId}…");
            $result = $recluster->recluster($siteId);
            if (! $result->success) {
                $this->error('Recluster failed: '.((string) ($result->error ?? 'unknown')));

                return self::FAILURE;
            }
            $this->line(json_encode($result->metrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');

            return self::SUCCESS;
        }

        $this->info("Reconciling Focus Article orphans for site {$siteId}…");
        $metrics = $reconciler->reconcile($siteId);
        $this->line(json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');

        if ((int) ($metrics['orphans_after'] ?? 0) > 0) {
            $this->warn('orphans_after > 0 — inspect classifications / SEO eligibility');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
