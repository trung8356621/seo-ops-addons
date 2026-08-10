<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Console;

use Omnichannel\Addons\SiteSync\Services\Backfill\SiteSyncV2BackfillService;
use App\Models\Site;
use Illuminate\Console\Command;

final class BackfillSiteSyncV2Command extends Command
{
    protected $signature = 'seo:site-sync-v2-backfill
        {site_id? : Core sites.id}
        {--dry-run : Report only (default true unless --execute)}
        {--execute : Persist changes}
        {--batch=200 : Chunk size}
        {--resume= : Resume keyword id}
        {--only=all : profile,links,keywords,scores,articles,all}
        {--force : Admin-only force (reserved)}';

    protected $description = 'Backfill legacy Domain/SEO data into Site Sync V2 sources (safe, no legacy delete)';

    public function handle(SiteSyncV2BackfillService $backfill): int
    {
        $siteId = (int) ($this->argument('site_id') ?? 0);
        if ($siteId <= 0) {
            $this->error('site_id required');

            return self::FAILURE;
        }
        $site = Site::query()->find($siteId);
        if ($site === null) {
            $this->error('Site not found');

            return self::FAILURE;
        }

        $onlyRaw = (string) $this->option('only');
        $only = array_values(array_filter(array_map('trim', explode(',', $onlyRaw))));
        $dryRun = ! (bool) $this->option('execute');
        if ($this->option('dry-run')) {
            $dryRun = true;
        }

        $report = $backfill->run(
            $site,
            $only !== [] ? $only : ['all'],
            $dryRun,
            (int) $this->option('batch'),
            $this->option('resume') !== null && $this->option('resume') !== ''
                ? (int) $this->option('resume')
                : null,
        );

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
