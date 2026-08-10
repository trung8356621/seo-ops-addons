<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Console;

use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncOrchestrator;
use App\Models\Site;
use Illuminate\Console\Command;

final class RunSiteSyncCommand extends Command
{
    protected $signature = 'seo:site-sync {site_id : Core sites.id} {--snapshot : Force snapshot mode} {--sync : Run steps synchronously}';

    protected $description = 'Run Site Sync V2 orchestrator for a site';

    public function handle(RunSiteSyncOrchestrator $orchestrator): int
    {
        $siteId = (int) $this->argument('site_id');
        $site = Site::query()->find($siteId);
        if ($site === null) {
            $this->error('Site not found: '.$siteId);

            return self::FAILURE;
        }

        $result = $orchestrator->start($site, [
            'force_snapshot' => (bool) $this->option('snapshot'),
            'mode' => $this->option('snapshot') ? 'snapshot' : 'delta',
            'trigger_source' => 'cli',
            'sync' => (bool) $this->option('sync'),
        ]);

        if (! ($result['success'] ?? false)) {
            $this->error((string) ($result['message'] ?? 'failed'));

            return self::FAILURE;
        }

        $this->info((string) ($result['message'] ?? 'ok'));
        $this->line('run_id='.($result['run_id'] ?? ''));
        $this->line('public_ref='.($result['public_ref'] ?? ''));

        return self::SUCCESS;
    }
}
