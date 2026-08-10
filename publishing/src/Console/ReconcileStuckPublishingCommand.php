<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Console;

use Omnichannel\Addons\Publishing\Services\Publishing\PublishingStuckRecoveryService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

/**
 * Safe recovery for legacy stuck Publishing items (lease expired / TTL).
 * Reconcile WordPress first — never blind-reset to waiting.
 */
final class ReconcileStuckPublishingCommand extends Command
{
    protected $signature = 'seo:publishing:reconcile-stuck
                            {--project= : Limit to Content Project id}
                            {--dry-run : Report only, no writes}
                            {--connection= : SEO database connection id/hash}';

    protected $description = 'Reconcile stuck Publishing Queue items (lease expired) against WordPress';

    public function handle(
        PublishingStuckRecoveryService $recovery,
        SeoDatabaseConnectionService $databaseConnection,
    ): int {
        $projectId = (int) $this->option('project');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $connectionOpt = trim((string) ($this->option('connection') ?? ''));
            if ($connectionOpt !== '') {
                if (ctype_digit($connectionOpt)) {
                    $databaseConnection->bootstrapByConnectionId((int) $connectionOpt);
                } else {
                    $databaseConnection->bootstrapByHash($connectionOpt);
                }
            } else {
                $databaseConnection->bootstrapLegacySharedConnection();
            }
        } catch (\Throwable $e) {
            $this->error('SEO DB bootstrap failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $stats = $recovery->recoverExpiredLeases(
            connectionMeta: ['resolver' => 'seo:publishing:reconcile-stuck'],
            projectId: $projectId > 0 ? $projectId : null,
            dryRun: $dryRun,
        );

        $this->table(
            ['metric', 'value'],
            collect($stats)->map(static fn ($v, $k): array => [(string) $k, is_scalar($v) ? (string) $v : json_encode($v)])->values()->all(),
        );

        if ($dryRun) {
            $this->warn('Dry-run — no status changes applied.');
        }

        return self::SUCCESS;
    }
}
