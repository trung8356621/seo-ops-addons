<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use App\Models\ApiConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Safe restore of AI api_connections from a pre-cutover backup DB.
 *
 * Never prints secrets. Default is dry-run.
 *
 * php artisan seo:ai:reconcile-api-connections-from-backup --dry-run
 * php artisan seo:ai:reconcile-api-connections-from-backup --apply --user=1
 */
final class ReconcileApiConnectionsFromBackupCommand extends Command
{
    protected $signature = 'seo:ai:reconcile-api-connections-from-backup
        {--source=omi_channel__pre_client_split_backup : Physical backup database name}
        {--user= : Target owner user id (defaults to first owner/admin)}
        {--dry-run : Report only (default unless --apply)}
        {--apply : Write restored rows}';

    protected $description = 'Reconcile missing AI api_connections from a backup DB (no secret logging)';

    public function handle(AiRuntimeHealthService $health, AiModelRouterService $router): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');
        if ($apply && (bool) $this->option('dry-run')) {
            $dryRun = true;
        }

        $source = trim((string) $this->option('source'));
        $core = (string) config('database.core_connection', 'mysql');
        if ($source === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $source)) {
            $this->error('Invalid --source database name.');

            return self::FAILURE;
        }

        if (! Schema::connection($core)->hasTable('api_connections')) {
            $this->error('Canonical api_connections missing on core.');

            return self::FAILURE;
        }

        try {
            $exists = DB::connection($core)->select("SHOW TABLES FROM `{$source}` LIKE 'api_connections'");
        } catch (\Throwable $e) {
            $this->error('Cannot read source DB: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($exists === []) {
            $this->error("Source {$source} has no api_connections table.");

            return self::FAILURE;
        }

        $targetUserId = $this->option('user');
        $userId = $targetUserId !== null && $targetUserId !== ''
            ? (int) $targetUserId
            : (int) (DB::table('users')->whereIn('role', ['owner', 'admin'])->orderBy('id')->value('id') ?? 0);
        if ($userId <= 0) {
            $this->error('Provide --user= with a valid owner id.');

            return self::FAILURE;
        }

        $sourceRows = DB::connection($core)->table($source.'.api_connections')
            ->whereIn('provider', [
                ApiConnectionProviders::OPENROUTER,
                ApiConnectionProviders::GEMINI,
                ApiConnectionProviders::DEEPSEEK,
                ApiConnectionProviders::CLAUDE,
            ])
            ->orderBy('id')
            ->get();

        $this->info(sprintf(
            'mode=%s source=%s target_user=%d source_ai_rows=%d',
            $dryRun ? 'dry-run' : 'apply',
            $source,
            $userId,
            $sourceRows->count(),
        ));

        $restored = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($sourceRows as $row) {
            $provider = (string) $row->provider;
            $already = ApiConnection::query()
                ->where('provider', $provider)
                ->where(function ($q) use ($userId): void {
                    $q->where('user_id', $userId)->orWhere('is_global', true);
                })
                ->exists();

            $cipher = (string) ($row->api_key ?? '');
            $usable = false;
            $decryptOk = false;
            if ($cipher !== '') {
                try {
                    $plain = Crypt::decryptString($cipher);
                    $decryptOk = true;
                    $usable = AiConnectionCredential::isUsable($plain);
                } catch (\Throwable) {
                    $decryptOk = false;
                }
            }

            $verdict = match (true) {
                $already => 'skip_exists',
                ! $decryptOk => 'skip_decrypt_failed_must_recreate',
                ! $usable => 'skip_key_not_usable_must_recreate',
                default => 'restore',
            };

            $this->line(sprintf(
                'provider=%s source_id=%s status=%s has_key=%s decrypt_ok=%s usable=%s verdict=%s',
                $provider,
                (string) $row->id,
                (string) ($row->status ?? ''),
                $cipher !== '' ? 'yes' : 'no',
                $decryptOk ? 'yes' : 'no',
                $usable ? 'yes' : 'no',
                $verdict,
            ));

            if ($verdict !== 'restore') {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $restored++;
                continue;
            }

            try {
                $connection = ApiConnection::query()->create([
                    'user_id' => $userId,
                    'provider' => $provider,
                    'name' => (string) ($row->name ?: $provider),
                    'api_key' => Crypt::decryptString($cipher),
                    'status' => (string) ($row->status ?: 'active'),
                    'is_global' => false,
                    'metadata' => is_string($row->metadata ?? null)
                        ? (json_decode((string) $row->metadata, true) ?: [])
                        : (is_array($row->metadata ?? null) ? $row->metadata : []),
                ]);
                $health->unlockConnectionForApiConnection((int) $connection->id);
                $router->syncModelsForConnection((int) $connection->id);
                $restored++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error('restore_failed provider='.$provider.' err='.$e->getMessage());
            }
        }

        $this->info(sprintf(
            'restored=%d skipped=%d failed=%d dry_run=%s',
            $restored,
            $skipped,
            $failed,
            $dryRun ? 'yes' : 'no',
        ));

        if (! $sourceRows->contains(fn ($r): bool => (string) $r->provider === ApiConnectionProviders::DEEPSEEK)) {
            $this->warn('DeepSeek not found in backup — must be recreated manually in Settings → API Connections.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
