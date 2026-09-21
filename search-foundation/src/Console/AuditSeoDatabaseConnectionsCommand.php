<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Console;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceIdentity;
use Illuminate\Console\Command;

/**
 * Legacy seo_database_connections audit — table permanently retired.
 * Reports canonical ServiceDatabaseConnection health instead.
 */
final class AuditSeoDatabaseConnectionsCommand extends Command
{
    protected $signature = 'seo:audit-seo-database-connections
                            {--connection-id= : Ignored (legacy)}
                            {--hash= : Ignored (legacy)}
                            {--project-id= : Ignored (legacy)}
                            {--disable-orphans : Ignored (legacy table retired)}
                            {--dry-run : Report only}';

    protected $description = 'Report canonical SEO ServiceDatabaseConnection (legacy seo_database_connections retired).';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ServiceDatabaseConnectionResolver $resolver,
    ): int {
        $this->warn('seo_database_connections is permanently retired. Showing canonical Service DB.');

        $health = $resolver->health(ServiceIdentity::PUBLIC_SEO);
        $this->table(
            ['field', 'value'],
            collect($health)->map(fn ($v, $k): array => [(string) $k, is_scalar($v) || $v === null ? (string) ($v ?? '') : json_encode($v)])->values()->all(),
        );

        $adapter = $databaseConnection->bootstrapCanonicalSharedConnection();
        $this->info($adapter !== null
            ? 'Canonical SEO bootstrap OK (omi_seo_ai).'
            : 'Canonical SEO bootstrap unavailable — configure Admin → Dịch vụ → SEO.');

        return $adapter !== null ? self::SUCCESS : self::FAILURE;
    }
}
