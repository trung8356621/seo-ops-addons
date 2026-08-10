<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

/**
 * Safe recovery for stuck PHP-engine runs.
 * Default = dry-run (no DB writes).
 *
 * Apply modes:
 * - --apply --token=… → release stale active_dispatch
 * - --apply --action=normalize-terminal-helpers → skipped pending helper/step on terminal run
 */
final class ContentProjectRunRecoverCommand extends Command
{
    protected $signature = 'seo:content-project-run:recover
        {runId : seo_project_runs.id}
        {--site= : Optional site_id to bootstrap SEO DB}
        {--apply : Apply inspected recovery action}
        {--action= : Optional apply action: normalize-terminal-helpers (default: stale-dispatch when --token set)}
        {--token= : Required for stale-dispatch apply — must match inspected active_dispatch.token}';

    protected $description = 'Dry-run recovery plan for a Content Project PHP engine run; optional --apply';

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
            $this->error('Run #'.$runId.' not found.');

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

        $plan = $engine->recoveryPlan($run);
        $this->line('=== RECOVERY PLAN (dry-run) ===');
        $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');

        if (! (bool) $this->option('apply')) {
            $this->info('Dry-run only. Re-run with --apply --action=normalize-terminal-helpers hoặc --apply --token=<token>.');

            return self::SUCCESS;
        }

        $action = trim((string) ($this->option('action') ?? ''));
        if ($action === 'normalize-terminal-helpers') {
            return $this->applyNormalizeTerminalHelpers($engine, $run, $plan);
        }

        if ($action !== '' && $action !== 'stale-dispatch') {
            $this->error('Unknown --action='.$action.'. Supported: normalize-terminal-helpers, stale-dispatch.');

            return self::FAILURE;
        }

        return $this->applyStaleDispatch($engine, $run, $plan);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function applyNormalizeTerminalHelpers(
        ContentProjectRunEngine $engine,
        SeoProjectRun $run,
        array $plan,
    ): int {
        $helperIds = is_array($plan['pending_helper_items'] ?? null)
            ? array_map(static fn (mixed $id): int => (int) $id, $plan['pending_helper_items'])
            : [];
        $articleIds = is_array($plan['pending_article_items'] ?? null)
            ? array_map(static fn (mixed $id): int => (int) $id, $plan['pending_article_items'])
            : [];

        if ($articleIds !== []) {
            $this->error('Có pending article items — không normalize helper. IDs: '.implode(',', $articleIds));

            return self::FAILURE;
        }

        $eligible = (bool) ($plan['eligible_for_normalize_terminal_helpers'] ?? false);
        $alreadyClean = ($plan['recommended_action'] ?? '') === 'noop_terminal' && $helperIds === [];
        if (! $eligible && ! $alreadyClean) {
            $this->error('Not eligible for normalize-terminal-helpers — xem blockers / pending_article_items.');

            return self::FAILURE;
        }

        $result = $engine->normalizeTerminalHelperRows(
            $run,
            $helperIds === [] ? null : $helperIds,
        );
        $this->line('=== APPLY RESULT (normalize-terminal-helpers) ===');
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');

        if (! ($result['applied'] ?? false)) {
            $this->error('Apply failed: '.($result['reason'] ?? 'unknown'));

            return self::FAILURE;
        }

        if (($result['reason'] ?? '') === 'noop_already_clean') {
            $this->info('No-op — không còn helper/step pending|processing để normalize.');
        } else {
            $this->info('Helper/step rows normalized to skipped. Không reopen run, không dispatch, không đụng article.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function applyStaleDispatch(
        ContentProjectRunEngine $engine,
        SeoProjectRun $run,
        array $plan,
    ): int {
        $token = (string) ($this->option('token') ?? '');
        if ($token === '') {
            $this->error('--apply (stale-dispatch) yêu cầu --token= khớp plan.token vừa inspect.');

            return self::FAILURE;
        }

        if (! ($plan['eligible_for_stale_release'] ?? false)) {
            $this->error('Not eligible — không apply. Xem blockers.');

            return self::FAILURE;
        }

        $result = $engine->applyStaleDispatchRelease($run, $token);
        $this->line('=== APPLY RESULT (stale-dispatch) ===');
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');

        if (! ($result['applied'] ?? false)) {
            $this->error('Apply failed: '.($result['reason'] ?? 'unknown'));

            return self::FAILURE;
        }

        $this->info('Stale active_dispatch released. Có thể resume/stop tùy status — không auto-resume cancelled.');

        return self::SUCCESS;
    }
}
