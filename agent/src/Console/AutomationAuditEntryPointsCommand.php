<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Illuminate\Console\Command;

final class AutomationAuditEntryPointsCommand extends Command
{
    protected $signature = 'automation:audit-entry-points {--strict : Fail when gate/feedback gaps remain}';

    protected $description = 'Audit Automation entry points for availability gate + UI feedback mapping.';

    public function handle(): int
    {
        $strict = (bool) $this->option('strict');
        $fail = false;
        $base = dirname(__DIR__);

        $checks = [
            [
                'file' => 'Automation/BusinessHook/Services/ManualAutomationDispatcher.php',
                'needles' => ['AutomationAvailabilityGate', 'ManualAutomationDispatchResult'],
                'forbidden' => [],
            ],
            [
                'file' => 'Automation/BusinessHook/Services/AutomationAvailabilityGate.php',
                'needles' => ['checkManual', 'checkEvent', 'checkSchedule'],
                'forbidden' => [],
            ],
            [
                'file' => 'Automation/BusinessHook/Services/BusinessEventDispatcher.php',
                'needles' => ['automation_match_status', 'automation_skip_code'],
                'forbidden' => [],
            ],
            [
                'file' => 'Services/WordPress/WordPressManualSyncService.php',
                'needles' => ['ManualSyncContext', 'ManualWordPressSyncJob'],
                'forbidden' => ['ManualAutomationDispatcher', 'SyncArticleToWordPressFromQueueJob::dispatch'],
            ],
            [
                'file' => 'Http/Controllers/ArticleEditorSyncController.php',
                'needles' => ['notification', 'WordPressManualSyncService'],
                'forbidden' => ['SyncArticleToWordPressFromQueueJob', 'ManualAutomationDispatcher'],
            ],
        ];

        foreach ($checks as $check) {
            $path = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $check['file']);
            if (! is_file($path)) {
                $this->error('missing: '.$check['file']);
                $fail = true;
                continue;
            }
            $source = (string) file_get_contents($path);
            $ok = true;
            foreach ($check['needles'] as $needle) {
                if (! str_contains($source, $needle)) {
                    $this->error("{$check['file']}: missing [{$needle}]");
                    $ok = false;
                    $fail = true;
                }
            }
            foreach ($check['forbidden'] as $needle) {
                if (str_contains($source, $needle)) {
                    $this->error("{$check['file']}: forbidden [{$needle}]");
                    $ok = false;
                    $fail = true;
                }
            }
            if ($ok) {
                $this->line('ok: '.$check['file']);
            }
        }

        $this->newLine();
        $this->info('=== Direct AutomationExecution::create outside controlled services ===');
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base.DIRECTORY_SEPARATOR.'Automation', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace($base.DIRECTORY_SEPARATOR, '', $file->getPathname());
            if (str_contains($rel, 'ManualAutomationDispatcher.php')
                || str_contains($rel, 'AutomationExecutionService.php')
                || str_contains($rel, 'AutomationGraphExecutionService.php')
                || str_contains($rel, 'AutomationVersionService.php')
            ) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (str_contains($src, 'AutomationExecution::query()->create')
                || str_contains($src, 'AutomationExecution::create')
            ) {
                $hits[] = $rel;
            }
        }
        if ($hits === []) {
            $this->line('ok: no unexpected AutomationExecution::create in Automation tree');
        } else {
            foreach ($hits as $hit) {
                $this->warn('review: '.$hit);
            }
        }

        if ($strict && $fail) {
            $this->error('STRICT FAIL: automation entry-point audit');

            return self::FAILURE;
        }

        $this->info($fail ? 'Audit finished with warnings.' : 'Audit PASS.');

        return self::SUCCESS;
    }
}
