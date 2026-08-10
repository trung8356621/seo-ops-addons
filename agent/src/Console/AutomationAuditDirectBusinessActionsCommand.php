<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Illuminate\Console\Command;

/**
 * Audit UI/controller/Livewire direct business side-effect callers outside Automation Engine.
 */
final class AutomationAuditDirectBusinessActionsCommand extends Command
{
    protected $signature = 'automation:audit-direct-business-actions {--strict : Fail when forbidden direct callers remain}';

    protected $description = 'Audit direct business action callers that bypass Automation Engine.';

    /**
     * @var array<string, list<array{file: string, needles: list<string>, action: string, status: string}>>
     */
    private const MODULE_CHECKS = [
        'WordPress' => [
            [
                'file' => 'Http/Controllers/ArticleEditorSyncController.php',
                'needles' => ['WordPressManualSyncService'],
                'forbidden' => [
                    'SyncArticleToWordPressFromQueueJob::dispatch',
                    'ManualWordPressContext',
                    'contextFromAuth',
                ],
                'action' => 'wordpress.article.sync',
                'expect' => 'cutover',
            ],
            [
                'file' => 'Services/WordPress/WordPressManualSyncService.php',
                'needles' => ['ManualSyncContext', 'ManualWordPressSyncJob'],
                'forbidden' => [
                    'ManualAutomationDispatcher',
                    'SyncArticleToWordPressFromQueueJob::dispatch',
                    'use App\\Addons\\SeoContentAi\\Jobs\\SyncArticleToWordPressFromQueueJob',
                    'AutomationAvailabilityGate',
                ],
                'action' => 'manual.wordpress.sync',
                'expect' => 'cutover',
            ],
            [
                'file' => 'Jobs/ManualWordPressSyncJob.php',
                'needles' => ['ManualWordPressContext', 'wordpressSynced', 'origin'],
                'forbidden' => [
                    'ManualAutomationDispatcher',
                    'ProductReviewPostSyncReconciler',
                ],
                'action' => 'manual.wordpress.sync',
                'expect' => 'cutover',
            ],
            [
                'file' => 'Jobs/SyncArticleToWordPressFromQueueJob.php',
                'needles' => ['DEPRECATED'],
                'forbidden' => ['syncFromEditorBundle'],
                'action' => 'wordpress.article.sync',
                'expect' => 'deprecated',
            ],
        ],
        'AI' => [
            [
                'file' => 'Automation/BusinessHook/Actions/ArticleGenerateContentHookAction.php',
                'needles' => ['handle'],
                'forbidden' => [],
                'action' => 'ai.article.generate (if registered)',
                'expect' => 'inventory',
            ],
        ],
        'SEO' => [
            [
                'file' => 'Automation/BusinessHook/Actions/ArticleRunSeoAnalysisHookAction.php',
                'needles' => ['handle'],
                'forbidden' => [],
                'action' => 'seo.article.analyze (if registered)',
                'expect' => 'inventory',
            ],
        ],
    ];

    /** Explicit local CRUD / invariant whitelist (not business side effects). */
    private const WHITELIST = [
        'Services/ArticleEditorPersistService.php' => 'local CRUD save draft',
        'Services/ArticleEditorBundleApplyService.php' => 'local apply editor bundle',
        'Services/ArticleEditorSeoMetaService.php' => 'local SEO fields',
        'Support/SeoAccessControl.php' => 'permission helper',
    ];

    public function handle(): int
    {
        $strict = (bool) $this->option('strict');
        $fail = false;
        $base = dirname(__DIR__);

        $this->info('=== Whitelist (local CRUD / invariant) ===');
        foreach (self::WHITELIST as $file => $reason) {
            $this->line("allow: {$file} — {$reason}");
        }

        foreach (self::MODULE_CHECKS as $module => $checks) {
            $this->newLine();
            $this->info("=== {$module} ===");
            foreach ($checks as $check) {
                $path = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $check['file']);
                if (! is_file($path)) {
                    $this->warn("missing: {$check['file']} (action={$check['action']})");
                    continue;
                }
                $source = (string) file_get_contents($path);
                $ok = true;
                foreach ($check['needles'] as $needle) {
                    if ($needle !== '' && ! str_contains($source, $needle)) {
                        $this->error("{$check['file']}: missing [{$needle}] for {$check['action']}");
                        $ok = false;
                        $fail = true;
                    }
                }
                foreach ($check['forbidden'] as $needle) {
                    if ($needle !== '' && str_contains($source, $needle)) {
                        $this->error("{$check['file']}: forbidden [{$needle}] still present (action={$check['action']})");
                        $ok = false;
                        $fail = true;
                    }
                }
                if ($ok) {
                    $this->line("ok: {$check['file']} → {$check['action']} ({$check['expect']})");
                }
            }
        }

        $this->newLine();
        $this->info('=== UI Livewire WP callers ===');
        foreach ([
            'Filament/Resources/ArticleResource/Pages/EditArticle.php',
            'Filament/Resources/ArticleResource/Pages/ListArticles.php',
            'Filament/Resources/ArticleResource/Pages/ListArticleSyncQueue.php',
        ] as $relative) {
            $path = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $source = (string) file_get_contents($path);
            if (str_contains($source, 'SyncArticleToWordPressFromQueueJob')
                || str_contains($source, 'contextFromAuth')
                || str_contains($source, 'ManualWordPressContext')
            ) {
                $this->error("{$relative}: still references legacy manual WP path");
                $fail = true;
            } elseif (! str_contains($source, 'WordPressManualSyncService')) {
                $this->warn("{$relative}: no WordPressManualSyncService reference (may be OK)");
            } else {
                $this->line("ok: {$relative} uses WordPressManualSyncService");
            }
        }

        if ($strict && $fail) {
            $this->error('STRICT FAIL: direct business action audit');

            return self::FAILURE;
        }

        $this->info($fail ? 'Audit finished with warnings.' : 'Audit PASS.');

        return $fail && $strict ? self::FAILURE : self::SUCCESS;
    }
}
