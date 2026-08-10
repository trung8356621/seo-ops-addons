<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Illuminate\Console\Command;

final class AutomationListEventsCommand extends Command
{
    protected $signature = 'automation:list-events';

    protected $description = 'List registered business events from BusinessEventRegistry.';

    public function handle(BusinessEventRegistry $registry): int
    {
        $rows = [];
        foreach ($registry->all() as $definition) {
            $rows[] = [
                $definition->name,
                $definition->module,
                $definition->description,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        $this->table(['Name', 'Module', 'Description'], $rows);
        $this->info('Total: '.count($rows));

        return self::SUCCESS;
    }
}
