<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchFoundation\Services\Audit\CrossSiteRelationRepairService;

final class RepairCrossSiteRelationsCommand extends Command
{
    protected $signature = 'seo:repair-cross-site-relations
        {--site_id= : Optional site filter}
        {--dry-run : Report actions without writing (default when --force not set)}
        {--force : Apply repairs}
        {--json : Print JSON only}';

    protected $description = 'Repair cross-site keyword/article/link contamination (use --dry-run first)';

    public function handle(CrossSiteRelationRepairService $repair): int
    {
        $siteId = (int) ($this->option('site_id') ?: 0);
        $force = (bool) $this->option('force');
        $dryRun = ! $force || (bool) $this->option('dry-run');

        if ($force && (bool) $this->option('dry-run')) {
            $dryRun = true;
        }

        $result = $repair->repair($dryRun, $siteId > 0 ? $siteId : null);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info($dryRun ? 'DRY-RUN repair plan' : 'Applied repairs');
        $this->line('Repaired: '.count($result['repaired']));
        $this->line('Unresolved: '.count($result['unresolved']));
        foreach ($result['audit_counts'] as $key => $count) {
            $this->line(sprintf('  audit.%s = %d', $key, (int) $count));
        }

        foreach (array_slice($result['repaired'], 0, 30) as $row) {
            $this->line(sprintf(
                '  [%s] %s keyword=%s site=%s article=%s',
                $dryRun ? 'would' : 'did',
                (string) ($row['action'] ?? ''),
                (string) ($row['keyword_id'] ?? ''),
                (string) ($row['site_id'] ?? ''),
                (string) ($row['article_id'] ?? ''),
            ));
        }

        if (! $force) {
            $this->warn('No writes performed. Re-run with --force to apply.');
        }

        return self::SUCCESS;
    }
}
