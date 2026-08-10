<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationRuleService;
use Omnichannel\Addons\WordPress\Jobs\SyncArticleToWordPressFromQueueJob;
use Omnichannel\Addons\Publishing\Services\ArticleScheduleReconcileService;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\Publishing\Services\ScheduledArticlePublishRunner;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressGateway;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressSideEffectGuard;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AutomationAuditWordpressCouplingCommand extends Command
{
    protected $signature = 'automation:audit-wordpress-coupling {--strict : Fail if automatic direct callers or unguarded boundary remain}';

    protected $description = 'Audit WordPress runtime boundary guard, contexts, queues, and rule conflicts.';

    /** @var list<string> */
    private const FORBIDDEN_AUTOMATIC_CALLERS = [
        'Services/CreateArticlesFromTaskService.php',
        'Services/SeoProjectWorkflowRunService.php',
        'Services/PromptTestPublishService.php',
        'Services/SeoProjectTaskLifecycleService.php',
        'Services/SeoProjectArchiveService.php',
        'Services/ArticleScheduleReconcileService.php',
        'Services/ScheduledArticlePublishRunner.php',
    ];

    public function handle(AutomationRuleService $ruleService): int
    {
        $strict = (bool) $this->option('strict');
        $fail = false;

        $this->info('=== Runtime boundary ===');
        if (! class_exists(WordPressGateway::class) || ! class_exists(WordPressSideEffectGuard::class)) {
            $this->error('WordPressGateway / WordPressSideEffectGuard missing');
            $fail = true;
        } else {
            $this->line('ok: WordPressGateway + WordPressSideEffectGuard');
        }

        $syncSource = (string) file_get_contents($this->addonPath('Services/WordPressArticleSyncService.php'));
        if (str_contains($syncSource, 'Http::')) {
            $this->error('WordPressArticleSyncService still uses Http:: directly');
            $fail = true;
        } else {
            $this->line('ok: WordPressArticleSyncService mutates only via WordPressGateway');
        }
        if (! str_contains($syncSource, 'WordPressExecutionContext')) {
            $this->error('WordPressArticleSyncService missing WordPressExecutionContext parameter');
            $fail = true;
        }

        $hook = (string) file_get_contents($this->addonPath('Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php'));
        if (! str_contains($hook, 'AutomationWordPressContext')) {
            $this->error('SyncArticleToWordPressHookAction missing AutomationWordPressContext');
            $fail = true;
        } else {
            $this->line('ok: automation action builds AutomationWordPressContext');
        }

        $manual = (string) file_get_contents($this->addonPath('Services/WordPress/WordPressManualSyncService.php'));
        if (! str_contains($manual, 'ManualSyncContext') || ! str_contains($manual, 'ManualWordPressSyncJob')) {
            $this->error('WordPressManualSyncService missing ManualSyncContext / ManualWordPressSyncJob');
            $fail = true;
        } else {
            $this->line('ok: manual service uses ManualSyncContext + ManualWordPressSyncJob');
        }
        if (str_contains($manual, 'ManualAutomationDispatcher')) {
            $this->error('WordPressManualSyncService still depends on ManualAutomationDispatcher');
            $fail = true;
        }
        if (str_contains($manual, 'SyncArticleToWordPressFromQueueJob::dispatch')
            || str_contains($manual, 'use App\\Addons\\SeoContentAi\\Jobs\\SyncArticleToWordPressFromQueueJob')
        ) {
            $this->error('WordPressManualSyncService still dispatches/imports SyncArticleToWordPressFromQueueJob');
            $fail = true;
        } else {
            $this->line('ok: manual service does not import/dispatch legacy seo WP job');
        }

        $this->newLine();
        $this->info('=== Automatic direct callers (forbidden) ===');
        foreach (self::FORBIDDEN_AUTOMATIC_CALLERS as $relative) {
            $path = $this->addonPath($relative);
            if (! is_file($path)) {
                $this->warn("missing: {$relative}");
                continue;
            }
            $source = (string) file_get_contents($path);
            $hits = [];
            foreach ([
                'WordPressArticleSyncService',
                'ArticleWpSyncQueueService',
                'SyncArticleToWordPressFromQueueJob',
                'WordPressGateway',
                'publishForArticle',
                'syncForArticle',
                'ensureWordPressPostForArticle',
            ] as $needle) {
                if (str_contains($source, $needle)) {
                    $hits[] = $needle;
                }
            }
            if ($hits !== []) {
                $this->error("AUTOMATIC: {$relative} → ".implode(', ', $hits));
                $fail = true;
            } else {
                $this->line("ok: {$relative}");
            }
        }

        $this->newLine();
        $this->info('=== Manual entry points ===');
        $this->line('manual: '.WordPressManualSyncService::class);
        $this->line('manual: ArticleEditorSyncController::syncWp');
        $this->line('manual: EditArticle sync / ListArticles seo meta');

        $this->newLine();
        $this->info('=== Enabled WordPress rules / conflicts ===');
        $enabledWp = $this->enabledWordpressRules();
        foreach ($enabledWp as $row) {
            $this->line("enabled: {$row['code']} #{$row['id']} event={$row['event_name']}");
            $rule = AutomationRule::query()->find($row['id']);
            if ($rule instanceof AutomationRule) {
                $conflicts = $ruleService->findConflictingWordpressRules($rule);
                if ($conflicts !== []) {
                    $this->error('conflict: '.$rule->code.' vs '.implode(', ', array_column($conflicts, 'rule_code')));
                    $fail = true;
                }
            }
        }
        if ($enabledWp === []) {
            $this->line('(none enabled)');
        }

        $this->newLine();
        $this->info('=== Queued legacy WP jobs (database sample) ===');
        $this->inspectQueuedJobs();

        $this->newLine();
        $this->info('=== Scheduled / system ===');
        $this->line('scheduled-runner: '.ScheduledArticlePublishRunner::class.' → emit article.publish_requested only');
        $this->line('reconcile: '.ArticleScheduleReconcileService::class.' Laravel-only');
        $this->line('legacy-manual-queue: '.ArticleWpSyncQueueService::QUEUE_NAME.' / '.SyncArticleToWordPressFromQueueJob::class);
        $this->line('automation-wp-action-queue: automation-external');

        if ($strict && $fail) {
            $this->error('STRICT FAIL: WordPress coupling / unguarded boundary remains.');

            return self::FAILURE;
        }

        $this->info($fail ? 'Audit completed with warnings.' : 'Audit PASS (runtime boundary present).');

        return self::SUCCESS;
    }

    private function addonPath(string $relative): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function inspectQueuedJobs(): void
    {
        try {
            if (config('queue.default') !== 'database') {
                $this->line('queue driver='.(string) config('queue.default').' — skip jobs table scan');

                return;
            }
            $rows = DB::table('jobs')->orderByDesc('id')->limit(300)->get(['id', 'queue', 'payload']);
            $hits = 0;
            foreach ($rows as $row) {
                $payload = (string) ($row->payload ?? '');
                if (! str_contains($payload, 'SyncArticleToWordPress') && ! str_contains($payload, 'WordPress')) {
                    continue;
                }
                $hits++;
                $class = preg_match('/"displayName":"([^"]+)"/', $payload, $m) === 1 ? $m[1] : 'unknown';
                $this->line("id={$row->id} queue={$row->queue} class={$class}");
            }
            if ($hits === 0) {
                $this->line('(none in latest 300)');
            }
        } catch (\Throwable $e) {
            $this->warn('jobs inspect failed: '.$e->getMessage());
        }
    }

    /**
     * @return list<array{id: int, code: string, event_name: string}>
     */
    private function enabledWordpressRules(): array
    {
        $wp = AutomationActionCode::WordpressArticleSync->value;
        $out = [];
        foreach (AutomationRule::query()->where('is_enabled', true)->with(['actions', 'nodes'])->get() as $rule) {
            $has = $rule->actions->contains(static fn ($a): bool => (string) $a->action_code === $wp)
                || $rule->nodes->contains(static fn ($n): bool => (string) ($n->action_code ?? '') === $wp);
            if ($has) {
                $out[] = ['id' => (int) $rule->id, 'code' => (string) $rule->code, 'event_name' => (string) $rule->event_name];
            }
        }

        return $out;
    }
}
