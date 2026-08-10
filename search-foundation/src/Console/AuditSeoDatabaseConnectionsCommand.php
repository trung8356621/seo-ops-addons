<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Console;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\PublishingConnectionCandidateResolver;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\SeoDatabaseConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Audit SEO DB connections for publishing cron — no passwords in output.
 */
final class AuditSeoDatabaseConnectionsCommand extends Command
{
    protected $signature = 'seo:audit-seo-database-connections
                            {--connection-id= : Focus one core seo_database_connections.id}
                            {--hash= : Focus one hash_id}
                            {--project-id= : After resolving a connection, look up seo_projects.id}
                            {--disable-orphans : Set is_active=0 for publishing-ineligible active orphans}
                            {--dry-run : Report only (default when disabling)}';

    protected $description = 'Audit seo_database_connections for publishing runner (stale/demo/orphan). Never prints passwords.';

    public function handle(
        PublishingConnectionCandidateResolver $resolver,
        SeoDatabaseConnectionService $databaseConnection,
    ): int {
        if (! Schema::hasTable('seo_database_connections')) {
            $this->error('Table seo_database_connections missing.');

            return self::FAILURE;
        }

        $focusId = (int) $this->option('connection-id');
        $focusHash = trim((string) $this->option('hash'));
        $projectId = (int) $this->option('project-id');
        $disableOrphans = (bool) $this->option('disable-orphans');
        $dryRun = (bool) $this->option('dry-run') || ! $disableOrphans;

        $query = SeoDatabaseConnection::query()->withCount('users')->orderBy('id');
        if ($focusId > 0) {
            $query->whereKey($focusId);
        }
        if ($focusHash !== '') {
            $query->where('hash_id', $focusHash);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->warn('No matching seo_database_connections rows.');

            return self::SUCCESS;
        }

        $this->line('soft_deletes=false (model uses is_active only; seo_connection_sites dropped — users via seo_connection_users)');
        $this->newLine();

        $disabled = 0;
        foreach ($rows as $connection) {
            $audit = $resolver->auditRow($connection);
            $this->line(sprintf(
                'id=%d hash=%s type=%s db=%s user=%s active=%s users=%d eligible=%s skip=%s',
                $audit['connection_id'],
                $audit['hash_id'],
                $audit['type'],
                $audit['database'],
                $audit['username'] !== '' ? $audit['username'] : '(null)',
                $audit['is_active'] ? '1' : '0',
                $audit['users_count'],
                $audit['publishing_eligible'] ? 'yes' : 'no',
                $audit['skip_reason'] ?? '-',
            ));
            $this->line('  created_via='.$audit['created_via'].' created_at='.($audit['created_at'] ?? 'n/a'));

            if ($disableOrphans
                && $audit['is_active']
                && $audit['skip_reason'] !== null
                && in_array($audit['skip_reason'], [
                    'orphan_demo_no_users',
                    'demo_database',
                    'manual_orphan_no_users',
                    'empty_database',
                ], true)
            ) {
                if ($dryRun) {
                    $this->warn('  [dry-run] would disable is_active=0');
                } else {
                    $connection->forceFill(['is_active' => false])->save();
                    $disabled++;
                    $this->warn('  DISABLED is_active=0');
                }
            }
        }

        if ($projectId > 0) {
            $this->newLine();
            $this->line("Resolve project_id={$projectId} against bootstrapped connection(s)...");
            foreach ($rows as $connection) {
                if (! (bool) $connection->is_active) {
                    continue;
                }
                try {
                    $meta = $databaseConnection->bootstrapAndVerifyFromConnection($connection, forceReconnect: true);
                    $found = DB::connection($databaseConnection->connectionName())
                        ->table('seo_projects')
                        ->where('id', $projectId)
                        ->first(['id', 'site_id', 'name', 'archived_at']);
                    $this->line(sprintf(
                        '  connection_id=%d hash=%s db=%s project=%s',
                        $meta['connection_id'],
                        $meta['hash_id'],
                        $meta['database'],
                        $found ? json_encode($found, JSON_UNESCAPED_UNICODE) : 'NOT_FOUND',
                    ));
                } catch (Throwable $e) {
                    $this->error(sprintf(
                        '  connection_id=%d hash=%s bootstrap_failed: %s',
                        (int) $connection->getKey(),
                        (string) $connection->hash_id,
                        $e->getMessage(),
                    ));
                } finally {
                    $databaseConnection->forgetBootstrappedHash((string) $connection->hash_id);
                    DB::purge($databaseConnection->connectionName());
                    SeoConnectionContext::reset();
                }
            }
        }

        if ($disableOrphans) {
            $this->newLine();
            $this->line($dryRun
                ? 'Dry-run only. Re-run with --disable-orphans without --dry-run to persist.'
                : "Disabled {$disabled} orphan connection(s).");
        }

        return self::SUCCESS;
    }
}
