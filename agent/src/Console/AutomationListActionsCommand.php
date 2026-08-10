<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Illuminate\Console\Command;

final class AutomationListActionsCommand extends Command
{
    protected $signature = 'automation:list-actions';

    protected $description = 'List registered automation actions from AutomationActionRegistry.';

    public function handle(AutomationActionRegistry $registry): int
    {
        $rows = [];
        foreach ($registry->all() as $definition) {
            $rows[] = [
                $definition->actionCode,
                $definition->module,
                $definition->description,
                $definition->isAsyncSafe ? 'yes' : 'no',
                (string) $definition->timeout,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        $this->table(['Code', 'Module', 'Description', 'Async', 'Timeout'], $rows);
        $this->info('Total: '.count($rows));

        return self::SUCCESS;
    }
}
