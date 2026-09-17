<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;

/**
 * Explicit site Topic recluster — never auto-run from migration.
 */
final class ReclusterSiteTopicsCommand extends Command
{
    protected $signature = 'seo:topics-recluster
        {site_id : Site id to recluster}
        {--sync : Run inline instead of queue}';

    protected $description = 'Rebuild site-scoped Topics from curated Domain Link List + verified product_cat seeds (all levels)';

    public function handle(TopicReclusterService $recluster): int
    {
        $siteId = (int) $this->argument('site_id');
        if ($siteId <= 0) {
            $this->error('site_id required');

            return self::FAILURE;
        }

        if (! TopicReclusterService::tablesReady()) {
            $this->error('Topic Core tables missing — run migrations first.');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            $result = $recluster->recluster($siteId);
            if (! $result->ok) {
                $this->error('Recluster failed: '.($result->error ?? 'unknown'));

                return self::FAILURE;
            }
            $this->info('Recluster ok for site '.$siteId);
            foreach ($result->metrics as $key => $value) {
                $this->line(sprintf('  %s: %s', $key, $value));
            }

            return self::SUCCESS;
        }

        ReclusterSiteTopicsJob::dispatch($siteId);
        $this->info('Recluster queued for site '.$siteId);

        return self::SUCCESS;
    }
}
