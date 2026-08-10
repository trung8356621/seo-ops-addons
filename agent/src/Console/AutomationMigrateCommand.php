<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookSchemaGuard;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Support\Automation\AutomationConnection;
use Illuminate\Console\Command;

/**
 * Schema automation đã chuyển sang core.
 * Flag --only-* trỏ migrate core; data copy dùng automation:migrate-to-core.
 */
final class AutomationMigrateCommand extends Command
{
    protected $signature = 'automation:migrate
        {--connection-id= : SEO database connection id (legacy, ignored for automation schema)}
        {--only-business-hook : Ensure Business Hook tables on automation connection}
        {--only-v2 : Ensure Automation V2 tables on automation connection}
        {--only-v3 : Ensure Automation V3 tables on automation connection}';

    protected $description = 'Ensure automation schema on configured automation connection (core).';

    public function handle(SeoDatabaseConnectionService $connections): int
    {
        if ((bool) $this->option('only-v3')
            || (bool) $this->option('only-v2')
            || (bool) $this->option('only-business-hook')
        ) {
            $target = AutomationConnection::target();
            $this->info("Ensuring automation schema on [{$target}] via core migration…");

            $exit = $this->call('migrate', [
                '--database' => $target,
                '--path' => 'database/migrations/2026_07_23_140000_create_core_automation_tables.php',
                '--force' => true,
            ]);

            if ($exit !== self::SUCCESS) {
                return $exit;
            }

            $missing = array_merge(
                BusinessHookSchemaGuard::missingTables(),
                BusinessHookSchemaGuard::missingV2Tables(),
                BusinessHookSchemaGuard::missingV3Tables(),
                BusinessHookSchemaGuard::missingV3Columns(),
            );

            if ($missing !== []) {
                $this->error('Still missing: '.implode(', ', $missing));
                $this->warn('Runtime connection: '.AutomationConnection::name());
                $this->warn('Nếu đang cutover: set AUTOMATION_DB_CONNECTION='.$target.' rồi config:clear.');

                return self::FAILURE;
            }

            $this->info('Automation tables ready on '.$target.'.');
            $this->comment('Data copy: php artisan automation:migrate-to-core --dry-run');

            return self::SUCCESS;
        }

        // Full SEO addon migrate (không tạo automation_* trên omi_seo_ai nữa — legacy no-op).
        $connectionId = $this->option('connection-id');
        $model = $connectionId
            ? \App\Models\SeoDatabaseConnection::query()->find((int) $connectionId)
            : \App\Models\SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();

        if ($model === null) {
            $this->error('No active SEO database connection.');

            return self::FAILURE;
        }

        $connections->bootstrapFromConnection($model);
        $this->info('Running SEO addon migrations (automation schema is no-op / owned by core)…');

        // Compat shell path after peer split (was app/Addons/SeoContentAi/database/migrations).
        $relativePath = 'addons/seo-content-ai-compat/database/migrations';
        if (! is_dir(base_path($relativePath))) {
            $this->comment(
                'No compat migration directory at '.$relativePath
                .'; peer migrations via AddonMigrationRegistrar / refactor:migrate.'
            );

            return self::SUCCESS;
        }

        return $this->call('migrate', [
            '--database' => 'omi_seo_ai',
            '--path' => $relativePath,
            '--force' => true,
        ]);
    }
}
