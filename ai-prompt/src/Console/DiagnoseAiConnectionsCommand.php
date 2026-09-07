<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use App\Models\ApiConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Services\AiConnectionInventoryService;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Safe AI connection inventory diagnostic — never prints secrets.
 *
 * php artisan seo:ai:connections:diagnose --user=1
 */
final class DiagnoseAiConnectionsCommand extends Command
{
    protected $signature = 'seo:ai:connections:diagnose
        {--user= : Viewer user id (defaults to first owner)}';

    protected $description = 'Diagnose AI connection inventory (Settings vs runtime source of truth)';

    public function handle(AiConnectionInventoryService $inventory): int
    {
        $userId = (int) ($this->option('user') ?: 0);
        if ($userId <= 0) {
            $userId = (int) (DB::table('users')->whereIn('role', ['owner', 'admin'])->orderBy('id')->value('id') ?? 0);
        }
        if ($userId <= 0) {
            $this->error('Provide --user=');

            return self::FAILURE;
        }

        $core = (string) config('database.core_connection', 'mysql');
        $physical = (string) config("database.connections.{$core}.database");
        $this->info("viewer_user={$userId} workspace_inventory=".($inventory->viewerSeesWorkspaceInventory($userId) ? 'yes' : 'no'));
        $this->info("canonical_model=App\\Models\\ApiConnection connection={$core} physical_db={$physical} table=api_connections");
        $this->info('model_getConnectionName='.(new ApiConnection)->getConnectionName());

        $counts = $inventory->counts($userId);
        $this->line('counts '.json_encode($counts));

        $this->line('--- inventory ---');
        foreach ($inventory->configuredAiConnections($userId) as $row) {
            $this->line(sprintf(
                'id=%d provider=%s name=%s status=%s user_id=%s is_global=%s credential_usable=%s type=%s',
                (int) $row->id,
                (string) $row->provider,
                (string) $row->name,
                (string) $row->status,
                (string) $row->user_id,
                (int) $row->is_global,
                AiConnectionCredential::isUsable($row->api_key) ? 'yes' : 'no',
                ApiConnectionProviders::connectionType((string) $row->provider)->value,
            ));
        }

        try {
            $seoCount = DB::connection($core)->table('omi_seo_ai.api_connections')->count();
            $this->line("duplicate_omi_seo_ai.api_connections count={$seoCount} (non-canonical)");
        } catch (\Throwable $e) {
            $this->line('duplicate_omi_seo_ai: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
