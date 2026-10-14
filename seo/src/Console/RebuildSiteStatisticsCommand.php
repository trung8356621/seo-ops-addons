<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\Seo\Services\SiteStatistics\SiteStatisticsRebuilder;
use Omnichannel\Addons\Seo\Services\SiteStatistics\SiteStatisticsSnapshotStore;

final class RebuildSiteStatisticsCommand extends Command
{
    protected $signature = 'seo:rebuild-site-statistics {site? : Site id} {--all : Rebuild every site}';

    protected $description = 'Rebuild Domain General statistics snapshots. Does not run on page GET.';

    public function handle(SiteStatisticsSnapshotStore $store, SiteStatisticsRebuilder $rebuilder): int
    {
        $all = (bool) $this->option('all');
        $site = $this->argument('site');
        if (! $all && ($site === null || $site === '')) {
            $this->error('Pass a site id or --all.');

            return self::FAILURE;
        }

        $ids = $all
            ? Site::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            : [(int) $site];

        foreach ($ids as $siteId) {
            if ($siteId <= 0) {
                continue;
            }

            $lock = Cache::lock($store->lockKey($siteId), 300);
            $lock->block(10);
            try {
                $generation = $store->bumpGeneration($siteId);
                $payload = $rebuilder->build($siteId, $generation);
                if ($store->generation($siteId) === $generation) {
                    $store->put($siteId, $payload);
                    $this->info("snapshot site {$siteId} generation {$generation}");
                } else {
                    $this->warn("snapshot site {$siteId} skipped; a newer refresh won");
                }
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }
}
