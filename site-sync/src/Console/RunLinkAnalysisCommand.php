<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Omnichannel\Addons\SiteSync\Services\LinkAnalysis\LinkAnalysisRunService;

final class RunLinkAnalysisCommand extends Command
{
    protected $signature = 'seo:link-analysis {siteId : Site ID}';

    protected $description = 'Start a WordPress-local link/anchor analysis run (separate from Site Sync).';

    public function handle(LinkAnalysisRunService $service): int
    {
        $site = Site::query()->find((int) $this->argument('siteId'));
        if (! $site instanceof Site) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $run = $service->start($site);
        $this->info('LinkAnalysisRun #'.$run->id.' queued for site '.$site->id);

        return self::SUCCESS;
    }
}
