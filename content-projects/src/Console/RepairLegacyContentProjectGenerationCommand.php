<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\LegacyContentProjectItemHydrator;
use Illuminate\Console\Command;

final class RepairLegacyContentProjectGenerationCommand extends Command
{
    protected $signature = 'seo:content-project:repair-legacy
        {project_id : Content Project ID}
        {--dry-run : Report only; do not write DB}
        {--apply : Apply safe transient-state repair}';

    protected $description = 'Inspect or repair legacy Content Project generation context without calling AI or WordPress.';

    public function handle(LegacyContentProjectItemHydrator $hydrator): int
    {
        $projectId = (int) $this->argument('project_id');
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        if ($dryRun && $apply) {
            $this->warn('--dry-run wins over --apply; no DB writes will be made.');
            $apply = false;
        }

        $report = $hydrator->inspectProject($projectId, $apply);

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' project #'.$projectId);
        $this->table(
            ['item', 'article', 'type', 'status', 'canonical', 'missing', 'stale', 'proposed', 'can_generate'],
            array_map(static fn (array $item): array => [
                (string) $item['item_id'],
                (string) $item['article_id'],
                (string) $item['item_type'],
                (string) $item['status'],
                (string) $item['canonical_status'],
                implode(',', $item['missing_context_artifacts'] ?? []),
                ! empty($item['stale_execution']) ? json_encode($item['stale_execution']) : '',
                implode(',', $item['proposed_repair'] ?? []),
                ! empty($item['can_generate_after_repair']) ? 'yes' : 'no',
            ], $report['items']),
        );

        $this->newLine();
        $this->line('Totals: '.json_encode($report['totals'], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
