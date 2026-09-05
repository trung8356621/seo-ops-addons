<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\Seeding\Support\SeedingDatabaseHealth;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;

final class SeedingDbCheckCommand extends Command
{
    protected $signature = 'seeding:db-check';

    protected $description = 'Check Seeding DB plane (omi_seeding) readiness — no business tables required';

    public function handle(SeedingDatabaseHealth $health, SeedingServiceResolver $resolver): int
    {
        $config = $resolver->resolve();
        $this->line('Service active: '.($config->active ? 'yes' : 'no'));
        $this->line('Connection: '.$config->database['connection']);
        $this->line('Database: '.$config->database['database']);

        $check = $health->check();
        if ($check['reachable']) {
            $this->info('omi_seeding reachable');

            return self::SUCCESS;
        }

        $this->error('omi_seeding not reachable: '.($check['error'] ?? 'unknown'));

        return self::FAILURE;
    }
}
