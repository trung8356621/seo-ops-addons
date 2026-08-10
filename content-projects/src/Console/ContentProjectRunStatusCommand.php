<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

/**
 * Read-only ops snapshot for Content Project PHP engine runs (Phase 1.5).
 * Never writes DB.
 */
final class ContentProjectRunStatusCommand extends Command
{
    protected $signature = 'seo:content-project-run:status
        {runId : seo_project_runs.id}
        {--site= : Optional site_id to bootstrap SEO DB connection}';

    protected $description = 'Read-only status + health of a Content Project run (PHP engine Phase 1.5)';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ContentProjectRunEngine $engine,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();

        $siteId = (int) ($this->option('site') ?? 0);
        if ($siteId > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection($siteId);
        }

        $runId = (int) $this->argument('runId');
        $run = SeoProjectRun::query()->find($runId);
        if (! $run instanceof SeoProjectRun) {
            $this->error('Run #'.$runId.' not found on current SEO connection.');

            return self::FAILURE;
        }

        if ($siteId <= 0) {
            $run->loadMissing('project');
            $projectSiteId = (int) ($run->project?->site_id ?? 0);
            if ($projectSiteId > 0) {
                $databaseConnection->bootstrapSeoDatabaseConnection($projectSiteId);
                $run = SeoProjectRun::query()->find($runId) ?? $run;
            }
        }

        $snapshot = $engine->statusSnapshot($run);
        $health = $engine->healthCheck($run);

        $this->line('=== RUN ===');
        $this->line(json_encode($snapshot['run'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->line('=== FEATURE FLAG ===');
        $this->line(json_encode($snapshot['feature_flag'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->line('=== COUNTS ===');
        $this->line(json_encode($snapshot['counts'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->line('=== DISPATCH / HEARTBEAT / JOB / STEP ===');
        $this->line(json_encode([
            'dispatch' => $snapshot['dispatch'] ?? null,
            'heartbeat' => $snapshot['heartbeat'] ?? null,
            'current_job' => $snapshot['current_job'] ?? null,
            'current_step' => $snapshot['current_step'] ?? null,
            'dispatch_age_seconds' => $snapshot['dispatch_age_seconds'] ?? null,
            'heartbeat_age_seconds' => $snapshot['heartbeat_age_seconds'] ?? null,
            'ttl' => $snapshot['ttl'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->line('=== STOP / NEXT / HEALTH ===');
        $this->line(json_encode([
            'stop_requested' => $snapshot['stop_requested'] ?? false,
            'stop_reason' => $snapshot['stop_reason'] ?? null,
            'outstanding_pending' => $snapshot['outstanding_pending'] ?? null,
            'current_processing' => $snapshot['current_processing'] ?? null,
            'next_candidate' => $snapshot['next_candidate'] ?? null,
            'last_transition' => $snapshot['last_transition'] ?? null,
            'health' => $health->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->line('=== FULL SNAPSHOT ===');
        $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');

        if (! $health->ok) {
            $this->error('Health NOT ok — see health.errors');
        } elseif ($health->warnings !== []) {
            $this->warn('Health ok with warnings — see health.warnings');
        }

        return self::SUCCESS;
    }
}
