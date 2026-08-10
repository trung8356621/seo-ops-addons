<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowRoleMigrationSuggester;
use Illuminate\Console\Command;

final class AssignWorkflowExecutionRolesCommand extends Command
{
    protected $signature = 'seo:workflow:assign-execution-roles
                            {--apply : Ghi role khi confidence high_hook và không conflict}
                            {--task= : Chỉ một SeoTask id}';

    protected $description = 'Audit / assign workflow node.data.execution_role từ Prompt hook (không heuristic title ở apply).';

    public function handle(WorkflowRoleMigrationSuggester $suggester): int
    {
        $apply = (bool) $this->option('apply');
        $taskId = (int) $this->option('task');

        $query = SeoTask::query()->orderBy('id');
        if ($taskId > 0) {
            $query->whereKey($taskId);
        }

        $tasks = $query->get();
        if ($tasks->isEmpty()) {
            $this->warn('Không có SeoTask.');

            return self::SUCCESS;
        }

        $totalAssign = 0;
        $totalSkip = 0;

        foreach ($tasks as $task) {
            $this->line('');
            $this->info(sprintf(
                'Workflow #%d — %s',
                (int) $task->id,
                (string) ($task->name ?? ''),
            ));

            if ($apply) {
                $result = $suggester->applyTask($task);
                $totalAssign += $result['assigned'];
                $totalSkip += $result['skipped'];
                $rows = $result['rows'];
                $this->line("  assigned={$result['assigned']} skipped={$result['skipped']}");
            } else {
                $rows = $suggester->auditTask($task);
            }

            $this->table(
                ['Node', 'Label', 'Hook', 'Current', 'Suggested', 'Confidence', 'Conflict'],
                array_map(static fn (array $row): array => [
                    $row['node_id'],
                    mb_substr((string) $row['node_label'], 0, 32),
                    $row['hook_key'] ?? '—',
                    $row['current_role'] ?? '—',
                    $row['suggested_role'] ?? '—',
                    $row['confidence'],
                    $row['conflict'] ?? '—',
                ], $rows),
            );
        }

        if ($apply) {
            $this->info("Done. assigned={$totalAssign} skipped={$totalSkip}");
        } else {
            $this->comment('Dry-run only. Thêm --apply để ghi role.');
        }

        return self::SUCCESS;
    }
}
