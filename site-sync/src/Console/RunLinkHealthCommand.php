<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Omnichannel\Addons\SiteSync\Services\LinkHealth\LinkHealthRunService;

final class RunLinkHealthCommand extends Command
{
    protected $signature = 'seo:link-health {siteId : Site ID}';

    protected $description = 'Start a WordPress-local link-health run (separate from Site Sync).';

    public function handle(LinkHealthRunService $service): int
    {
        $site = Site::query()->find((int) $this->argument('siteId'));
        if (! $site instanceof Site) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $run = $service->start($site);
        $this->info('LinkHealthRun #'.$run->id.' queued for site '.$site->id);

        return self::SUCCESS;
    }
}
