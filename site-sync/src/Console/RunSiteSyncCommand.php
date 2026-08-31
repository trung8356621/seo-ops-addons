<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncOrchestrator;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncProtocolRouter;

final class RunSiteSyncCommand extends Command
{
    protected $signature = 'seo:site-sync {site_id : Core sites.id} {--snapshot : Force snapshot mode} {--force-full : Force full V3/V2 re-sync} {--sync : Run steps synchronously} {--v2 : Force V2 orchestrator} {--v3 : Force V3 orchestrator}';

    protected $description = 'Run Site Sync orchestrator (V3 when capable, else V2)';

    public function handle(SiteSyncFeatureFlags $flags, SiteSyncProtocolRouter $router): int
    {
        $siteId = (int) $this->argument('site_id');
        $site = Site::query()->find($siteId);
        if ($site === null) {
            $this->error('Site not found: '.$siteId);

            return self::FAILURE;
        }

        $forceFull = (bool) $this->option('force-full');
        $options = [
            'force_snapshot' => (bool) $this->option('snapshot'),
            'force_full' => $forceFull,
            'mode' => $forceFull
                ? 'force_full'
                : ($this->option('snapshot') ? 'snapshot' : 'delta'),
            'trigger_source' => 'cli',
            'sync' => (bool) $this->option('sync'),
        ];

        $forceV2 = (bool) $this->option('v2');
        $forceV3 = (bool) $this->option('v3');

        if ($forceV3 && $flags->protocolV3Enabled()) {
            $result = app(RunSiteSyncV3Orchestrator::class)->start($site, $options);
            $protocol = 3;
        } elseif ($forceV2 || ! $flags->protocolV3Enabled()) {
            $result = app(RunSiteSyncOrchestrator::class)->start($site, $options);
            $protocol = 2;
        } else {
            $result = $router->start($site, $options);
            $protocol = (int) ($result['protocol'] ?? ($router->shouldUseV3($site) ? 3 : 2));
        }

        if (! ($result['success'] ?? false)) {
            $this->error((string) ($result['message'] ?? 'failed'));

            return self::FAILURE;
        }

        $this->info((string) ($result['message'] ?? 'ok'));
        $this->line('run_id='.($result['run_id'] ?? ''));
        $this->line('public_ref='.($result['public_ref'] ?? ''));
        $this->line('protocol='.($result['protocol'] ?? $protocol));

        return self::SUCCESS;
    }
}
