<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationRuleVersionMigrationService;
use Illuminate\Console\Command;

final class AutomationMigrateRuleVersionsCommand extends Command
{
    protected $signature = 'automation:migrate-rule-versions
        {--dry-run : Preview rules that would receive published versions}
        {--apply : Create published versions for rules missing published_version_id}';

    protected $description = 'Idempotent migration: publish version snapshots for legacy automation rules.';

    public function handle(AutomationRuleVersionMigrationService $migration): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        if (! $apply && ! $dryRun) {
            $this->warn('Use --dry-run (default) or --apply.');

            return self::FAILURE;
        }

        $stats = $migration->migrate(apply: $apply);

        $this->line('total='.$stats['total'].' migrated='.$stats['migrated'].' skipped='.$stats['skipped']);
        if ($stats['errors'] !== []) {
            foreach ($stats['errors'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info($apply ? 'Rule version migration applied.' : 'Dry-run complete. Re-run with --apply to publish.');

        return self::SUCCESS;
    }
}
