<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Content\Services\ArticleWritingStableHealthService;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowDoctorService;
use Illuminate\Console\Command;

final class WorkflowDoctorCommand extends Command
{
    protected $signature = 'seo:workflow:doctor {workflowId? : SeoTask id (optional)}';

    protected $description = 'Read-only: chẩn đoán execution_role / prompt / edges / Settings bindings + Stable Gate.';

    public function handle(
        WorkflowDoctorService $doctor,
        ArticleWritingStableHealthService $stableHealth,
    ): int {
        $id = (int) $this->argument('workflowId');
        $query = SeoTask::query()->orderBy('id');
        if ($id > 0) {
            $query->whereKey($id);
        }

        $tasks = $query->get();
        if ($tasks->isEmpty()) {
            $this->warn($id > 0 ? "Không tìm thấy Workflow #{$id}." : 'Không có SeoTask.');

            return self::FAILURE;
        }

        $hasBlocking = false;

        foreach ($tasks as $task) {
            $report = $doctor->diagnose($task);
            $this->line('');
            $this->info(sprintf(
                'Workflow #%d — %s',
                $report['workflow_id'],
                $report['name'] !== '' ? $report['name'] : '(no name)',
            ));
            $this->line('  Used by Settings: '.(
                $report['used_by'] === []
                    ? '—'
                    : implode(', ', $report['used_by'])
            ));
            $this->line('  Flow hash: '.$report['flow_data_hash']);
            $this->line('  Roles:');
            foreach ($report['roles'] as $role => $nodeId) {
                $this->line(sprintf(
                    '    - %s: %s',
                    $role,
                    $nodeId ?? '—',
                ));
            }

            $this->printList('Missing required roles', $report['missing_required']);
            $this->printList('Duplicate roles', $report['duplicates']);
            $this->printList('Prompt missing', $report['prompt_missing']);
            $this->printList('Hook mismatch', $report['hook_mismatch']);
            $this->printList('Broken edges', $report['broken_edges']);
            $this->printList('Ambiguous unassigned Prompt nodes', $report['ambiguous_unassigned']);

            $can = $report['can_run'];
            $this->line(sprintf(
                '  Can run: publish=%s content-only=%s improve=%s image=%s',
                $can['publish'] ? 'yes' : 'no',
                $can['content_only'] ? 'yes' : 'no',
                $can['improve'] ? 'yes' : 'no',
                $can['image'] ? 'yes' : 'no',
            ));

            if ($report['blocking_errors'] !== []) {
                $hasBlocking = true;
                $this->error('  BLOCKING:');
                foreach ($report['blocking_errors'] as $err) {
                    $this->line('    • '.$err);
                }
            } else {
                $this->comment('  No blocking errors.');
            }

            foreach ($report['warnings'] as $warn) {
                $this->warn('  WARN: '.$warn);
            }
        }

        $gate = $stableHealth->evaluate();
        $this->line('');
        $this->info('Article Writing Stable Gate: '.$gate['status']);
        $this->line('  Legacy compatibility:');
        foreach ($gate['legacy'] as $k => $v) {
            $this->line('    - '.$k.': '.(is_scalar($v) ? (string) $v : json_encode($v)));
        }
        foreach ($gate['fails'] as $fail) {
            $this->error('  FAIL: '.$fail);
        }
        foreach ($gate['warns'] as $warn) {
            $this->warn('  WARN: '.$warn);
        }
        foreach ($gate['passes'] as $ok) {
            $this->comment('  PASS: '.$ok);
        }

        $this->line('');
        if ($hasBlocking || $gate['status'] === ArticleWritingStableHealthService::STATUS_FAIL) {
            $this->error('Doctor: có blocking error / Stable Gate FAIL.');

            return self::FAILURE;
        }

        $this->info('Doctor: OK.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $items
     */
    private function printList(string $label, array $items): void
    {
        if ($items === []) {
            $this->line("  {$label}: —");

            return;
        }
        $this->line("  {$label}:");
        foreach ($items as $item) {
            $this->line('    • '.$item);
        }
    }
}
