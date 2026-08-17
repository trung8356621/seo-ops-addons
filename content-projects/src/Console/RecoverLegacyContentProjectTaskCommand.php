<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectLegacyTaskRecoveryService;
use Illuminate\Console\Command;

final class RecoverLegacyContentProjectTaskCommand extends Command
{
    protected $signature = 'seo:content-project:recover-legacy-task
        {task_id : SeoProjectTask ID}
        {--dry-run : Report only; do not write DB (default)}
        {--detach : Apply detach-only (no relink, no new article)}
        {--apply : Alias of --detach}
        {--skip-wp : Skip remote WordPress candidate search (unused; detach-only)}';

    protected $description = 'Detach a legacy corrupted Content Project article link. No auto-relink, no new article.';

    public function handle(ContentProjectLegacyTaskRecoveryService $recovery): int
    {
        $taskId = (int) $this->argument('task_id');
        $apply = (bool) $this->option('detach') || (bool) $this->option('apply');
        if ((bool) $this->option('dry-run') || ! $apply) {
            $apply = false;
        }

        $report = $apply
            ? $recovery->recover($taskId, true, false)
            : $recovery->plan($taskId, false);

        if (($report['ok'] ?? false) !== true) {
            $this->error((string) ($report['error'] ?? 'recovery_failed'));

            return self::FAILURE;
        }

        $current = $report['current'] ?? [];
        $this->line('Task #'.$taskId);
        $this->line('project_id='.(string) ($report['project_id'] ?? ''));
        $this->line('keyword='.(string) ($report['keyword'] ?? ''));
        $this->newLine();
        $this->line('Current:');
        $this->line('  article_id='.(string) ($current['article_id'] ?? 'NULL'));
        $this->line('  article_site='.(string) ($current['article_site'] ?? 'n/a'));
        $this->line('  domain='.(string) ($current['domain'] ?? ''));
        $this->newLine();
        $this->line('Proposed:');
        foreach ($report['proposed'] ?? [] as $action) {
            $this->line('  '.$action);
        }
        $this->newLine();
        $this->line('action: detach only');
        $this->line('  no relink');
        $this->line('  no new article');
        $this->line(($apply ? 'APPLY' : 'DRY-RUN').' auto_reconcile=NO create_article=NO manual_action_required=YES');

        if (! empty($report['applied'])) {
            $this->warn('Applied: '.implode('; ', $report['applied']));
        }

        return self::SUCCESS;
    }
}
