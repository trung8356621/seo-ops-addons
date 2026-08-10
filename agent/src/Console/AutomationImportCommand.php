<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationImportExportService;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Console\Command;

final class AutomationImportCommand extends Command
{
    protected $signature = 'automation:import
        {file : Path to JSON import file}
        {--disabled : Import as disabled draft (default)}
        {--enabled : Import rule enabled}';

    protected $description = 'Import automation rule JSON schema v3 as disabled draft graph.';

    public function handle(AutomationImportExportService $importer): int
    {
        $path = (string) $this->argument('file');
        $disabled = (bool) $this->option('enabled') ? false : true;

        try {
            $result = $importer->importFromFile($path, $disabled);
        } catch (AutomationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rule = $result['rule'];
        $this->info(sprintf(
            'Imported rule [%s] id=%d nodes=%d edges=%d enabled=%s',
            $rule->code,
            $rule->id,
            $rule->nodes->count(),
            $rule->edges->count(),
            $rule->is_enabled ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
