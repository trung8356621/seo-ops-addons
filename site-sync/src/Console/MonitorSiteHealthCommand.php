<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\ManagedWordPressSiteSelector;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthMonitor;

final class MonitorSiteHealthCommand extends Command
{
    protected $signature = 'seo:site-health:monitor {--site= : Active managed Site ID} {--domain= : Exact active managed domain} {--limit=100}';

    protected $description = 'Run lightweight managed WordPress site health checks.';

    public function handle(SiteHealthMonitor $monitor, ManagedWordPressSiteSelector $sites): int
    {
        $query = $sites->query();

        if ((int) $this->option('site') > 0) {
            $query->whereKey((int) $this->option('site'));
        }

        if (trim((string) $this->option('domain')) !== '') {
            $query->where('domain', trim((string) $this->option('domain')));
        }

        $checked = 0;
        $query->limit(max(1, min(500, (int) $this->option('limit'))))->get()->each(function (Site $site) use ($monitor, &$checked): void {
            Cache::lock('site-health:site:'.$site->id, 60)->get(fn () => $monitor->check($site));
            $checked++;
        });

        $this->info('Checked '.$checked.' managed site(s).');

        return self::SUCCESS;
    }
}
