<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

/**
 * Backend-owned watchdog for abrupt worker loss.
 * Never calls AI providers — only inspects leases and transitions WORKER_LOST.
 */
final class ContentProjectWorkerLostWatchdogCommand extends Command
{
    protected $signature = 'seo:content-project-run:watchdog-worker-lost
        {--limit=50 : Max non-terminal runs to inspect}
        {--site= : Optional site_id to bootstrap SEO DB}';

    protected $description = 'Declare confirmed WORKER_LOST on PHP-engine runs whose hard lease expired';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ContentProjectRunEngine $engine,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();

        $siteId = (int) ($this->option('site') ?? 0);
        if ($siteId > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection($siteId);
        }

        $result = $engine->recoverLostWorkers((int) ($this->option('limit') ?? 50));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');

        if (($result['recovered'] ?? 0) > 0) {
            $this->info('Declared WORKER_LOST on '.count($result['run_ids']).' run(s). No next-article auto-dispatch.');
        } else {
            $this->info('No confirmed worker-death leases.');
        }

        return self::SUCCESS;
    }
}
