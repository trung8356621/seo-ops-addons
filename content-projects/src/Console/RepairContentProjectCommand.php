<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskRepairService;
use Illuminate\Console\Command;

final class RepairContentProjectCommand extends Command
{
    protected $signature = 'content-project:repair
        {--dry-run : In plan, không ghi DB (mặc định nếu thiếu --apply)}
        {--apply : Thực thi repair}
        {--project-id= : Chỉ một project}
        {--task-id= : Chỉ group chứa task}
        {--include-archive : Bao gồm archive repair (mặc định bật)}
        {--repair-run-items : Báo legacy run JSON cần backfill}
        {--repair-archive : Repair archive mirrors}
        {--purge-sync-orphans : Force-delete orphan sau relink}
        {--force : Alias --apply}';

    protected $description = 'Repair Content Project: backfill source_key, merge duplicates, archive mirrors, purge sync orphans.';

    public function handle(SeoProjectTaskRepairService $repair): int
    {
        $apply = (bool) $this->option('apply') || (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        if ($dryRun && $apply) {
            $this->warn('--dry-run thắng --apply trong phiên này: không ghi DB.');
            $apply = false;
        }

        if (! $apply) {
            $this->info('DRY-RUN — không ghi DB. Thêm --apply để thực thi.');
        } else {
            $this->warn('APPLY — sẽ ghi DB. Backup trước khi tiếp tục.');
        }

        $stats = $repair->repair(
            apply: $apply,
            projectId: ($id = (int) ($this->option('project-id') ?? 0)) > 0 ? $id : null,
            taskId: ($tid = (int) ($this->option('task-id') ?? 0)) > 0 ? $tid : null,
            includeArchive: true,
            repairRunItems: (bool) $this->option('repair-run-items') || true,
            repairArchive: ! $this->option('repair-archive') ? true : (bool) $this->option('repair-archive'),
            purgeSyncOrphans: (bool) $this->option('purge-sync-orphans') || true,
        );

        $manual = $stats['manual_groups'] ?? [];
        unset($stats['manual_groups']);

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(static fn (mixed $v, string $k): array => [$k, is_scalar($v) ? (string) $v : json_encode($v)])->values()->all(),
        );

        if (is_array($manual) && $manual !== []) {
            $this->newLine();
            $this->warn('Manual review groups: '.count($manual));
            foreach (array_slice($manual, 0, 20) as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
    }
}
