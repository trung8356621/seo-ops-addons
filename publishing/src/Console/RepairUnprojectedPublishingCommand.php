<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Console;

use Omnichannel\Addons\Publishing\Services\Publishing\PublishingUnprojectedRepairService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

/**
 * Diagnose invisible dispatch-claimed Publishing Queue rows + optional safe repair.
 */
final class RepairUnprojectedPublishingCommand extends Command
{
    protected $signature = 'seo:publishing:repair-unprojected
        {--dry-run : Report only (default)}
        {--apply : Apply safe repairs}
        {--project= : seo_projects.id}
        {--only-unprojected : Limit repair candidates to stalled/unprojected kinds}';

    protected $description = 'Diagnose/repair Publishing Queue rows missing from presenter buckets (awaiting_delivery / needs_attention).';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        PublishingUnprojectedRepairService $repair,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');
        $projectId = ($this->option('project') !== null && $this->option('project') !== '')
            ? (int) $this->option('project')
            : null;
        $onlyUnprojected = (bool) $this->option('only-unprojected');

        $this->line($dryRun ? '=== DRY-RUN ===' : '=== APPLY ===');

        $report = $repair->diagnoseAndRepair(
            projectId: $projectId,
            dryRun: $dryRun,
            onlyUnprojected: $onlyUnprojected,
        );

        $this->line('total_queue='.$report['total']);
        $this->line('by_raw_status='.json_encode($report['by_raw_status'], JSON_UNESCAPED_UNICODE));
        $this->line('by_presenter_state='.json_encode($report['by_presenter_state'], JSON_UNESCAPED_UNICODE));
        $this->line('unprojected_ids='.implode(',', $report['unprojected_ids']));
        $this->line('batch_id='.$report['batch_id']);

        $this->table(
            ['task_id', 'raw', 'presenter', 'kind', 'attempts', 'delivery_at', 'publisher_at'],
            array_map(static function (array $row): array {
                return [
                    $row['task_id'],
                    $row['raw_status'],
                    $row['presenter_state'],
                    $row['kind'],
                    $row['publish_attempt_count'],
                    $row['delivery_dispatched_at'] ?? '—',
                    $row['publisher_started_at'] ?? '—',
                ];
            }, array_slice($report['classifications'], 0, 100)),
        );

        if (! $dryRun) {
            $this->line('repaired='.count($report['repaired']));
            foreach ($report['repaired'] as $row) {
                $this->line(sprintf(
                    'repaired task=%d kind=%s → %s',
                    $row['task_id'],
                    $row['kind'],
                    $row['repair'] ?? '—',
                ));
            }
        }

        $invariant = ($report['by_presenter_state']['invariant_ok'] ?? false) === true;
        $this->line('invariant_ok='.($invariant ? 'yes' : 'NO'));

        return self::SUCCESS;
    }
}
