<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use App\Models\ApiConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Repair soft-poisoned runtime health + orphaned connection ownership that
 * leaves Outline routing at attempts=0 (connection_locked / missing owner).
 *
 * php artisan seo:ai:repair-runtime-health --dry-run
 * php artisan seo:ai:repair-runtime-health --unlock-connections
 * php artisan seo:ai:repair-runtime-health --reassign-orphaned-to=1
 */
final class RepairAiRuntimeHealthCommand extends Command
{
    protected $signature = 'seo:ai:repair-runtime-health
        {--dry-run : Report repairable rows without writing}
        {--user= : Limit to a single routing owner user id}
        {--connection= : Limit to a single api_connection_id}
        {--unlock-connections : Clear connection_locked / manual unlock rows (credentials fixed)}
        {--reassign-orphaned-to= : Reassign api_connections whose user_id is missing from users}';

    protected $description = 'Repair soft-poisoned AI runtime health and orphaned connection ownership';

    /** @var list<string> */
    private const SOFT_CLASSES = [
        AiFailureClass::RateLimited->value,
        AiFailureClass::TransientProvider->value,
        AiFailureClass::ProviderEmptyOutput->value,
        AiFailureClass::ProviderInvalidOutput->value,
        AiFailureClass::ProviderRefusal->value,
    ];

    public function handle(AiRuntimeHealthService $health): int
    {
        if (! Schema::connection(AiRuntimeHealthService::CONNECTION_NAME)->hasTable('ai_runtime_health_states')) {
            $this->warn('ai_runtime_health_states table not ready.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user');
        $connectionId = $this->option('connection');
        $unlockConnections = (bool) $this->option('unlock-connections');
        $reassignTo = $this->option('reassign-orphaned-to');

        $query = AiRuntimeHealthState::query();
        if ($userId !== null && $userId !== '') {
            $query->where('user_id', (int) $userId);
        }
        if ($connectionId !== null && $connectionId !== '') {
            $query->where('api_connection_id', (int) $connectionId);
        }

        $users = (clone $query)->distinct()->pluck('user_id');
        $models = (clone $query)->where('subject_type', AiRuntimeHealthState::SUBJECT_MODEL)->count();
        $connections = (clone $query)->where('subject_type', AiRuntimeHealthState::SUBJECT_CONNECTION)->count();

        $repairable = 0;
        $repaired = 0;
        $skippedHard = 0;
        $skippedManual = 0;
        $unlocked = 0;
        $reassigned = 0;

        if ($reassignTo !== null && $reassignTo !== '') {
            $reassigned = $this->reassignOrphanedConnections((int) $reassignTo, $dryRun);
        }

        if ($unlockConnections) {
            $unlocked = $this->unlockConnections($query, $health, $dryRun);
        }

        $candidates = (clone $query)
            ->where('subject_type', AiRuntimeHealthState::SUBJECT_MODEL)
            ->where('health_status', AiRuntimeHealthStatus::Unavailable->value)
            ->get();

        foreach ($candidates as $row) {
            if ((bool) $row->manual_unlock_required || (bool) $row->paid_locked) {
                $skippedManual++;
                continue;
            }
            $class = (string) ($row->last_failure_class ?? '');
            if (! in_array($class, self::SOFT_CLASSES, true)
                && ! AiRoutesExhaustionClassifier::isSoftProviderFailureClass($class)) {
                $skippedHard++;
                continue;
            }
            $repairable++;
            if ($dryRun) {
                $this->line(sprintf(
                    'repairable model_id=%d user=%d last_class=%s consecutive=%d',
                    (int) $row->subject_id,
                    (int) $row->user_id,
                    $class,
                    (int) $row->consecutive_failures,
                ));
                continue;
            }

            $cooldownExpired = $row->cooldown_until === null
                || Carbon::parse((string) $row->cooldown_until)->lte(now());

            $row->health_status = AiRuntimeHealthStatus::Degraded->value;
            $row->consecutive_failures = 0;
            if ($cooldownExpired) {
                $row->cooldown_until = null;
            }
            $row->save();
            $repaired++;
        }

        $this->info(sprintf(
            'users_scanned=%d models_scanned=%d connections_scanned=%d repairable=%d repaired=%d skipped_hard=%d skipped_manual_lock=%d unlocked_connections=%d reassigned_connections=%d dry_run=%s',
            $users->count(),
            $models,
            $connections,
            $repairable,
            $repaired,
            $skippedHard,
            $skippedManual,
            $unlocked,
            $reassigned,
            $dryRun ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState>  $query
     */
    private function unlockConnections($query, AiRuntimeHealthService $health, bool $dryRun): int
    {
        $rows = (clone $query)
            ->where('subject_type', AiRuntimeHealthState::SUBJECT_CONNECTION)
            ->where(function ($inner): void {
                $inner->where('health_status', AiRuntimeHealthStatus::ConnectionLocked->value)
                    ->orWhere('manual_unlock_required', true);
            })
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            $connectionId = (int) ($row->api_connection_id ?: $row->subject_id);
            if ($connectionId <= 0) {
                continue;
            }
            $ids[$connectionId] = true;
            if ($dryRun) {
                $this->line(sprintf(
                    'unlockable connection_id=%d user=%d status=%s last_class=%s',
                    $connectionId,
                    (int) $row->user_id,
                    (string) $row->health_status,
                    (string) ($row->last_failure_class ?? ''),
                ));
            }
        }

        if ($dryRun) {
            return count($ids);
        }

        $cleared = 0;
        foreach (array_keys($ids) as $connectionId) {
            $cleared += $health->unlockConnectionForApiConnection((int) $connectionId);
        }

        return $cleared;
    }

    private function reassignOrphanedConnections(int $toUserId, bool $dryRun): int
    {
        if ($toUserId <= 0 || ! Schema::hasTable('users') || ! Schema::hasTable('api_connections')) {
            return 0;
        }
        if (! \App\Models\User::query()->whereKey($toUserId)->exists()) {
            $this->error('reassign-orphaned-to user '.$toUserId.' does not exist.');

            return 0;
        }

        $existingUserIds = \App\Models\User::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $existing = array_fill_keys($existingUserIds, true);

        $count = 0;
        $connections = ApiConnection::query()
            ->whereIn('provider', [
                ApiConnectionProviders::OPENROUTER,
                ApiConnectionProviders::GEMINI,
                ApiConnectionProviders::DEEPSEEK,
                ApiConnectionProviders::CLAUDE,
            ])
            ->get();

        foreach ($connections as $connection) {
            $owner = (int) $connection->user_id;
            if ($owner > 0 && isset($existing[$owner])) {
                continue;
            }
            $count++;
            $this->line(sprintf(
                'orphaned connection_id=%d provider=%s old_user=%d key_usable=%s → user=%d',
                (int) $connection->id,
                (string) $connection->provider,
                $owner,
                AiConnectionCredential::isUsable($connection->api_key) ? 'yes' : 'no',
                $toUserId,
            ));
            if ($dryRun) {
                continue;
            }
            $connection->user_id = $toUserId;
            $connection->save();
            app(AiRuntimeHealthService::class)->unlockConnectionForApiConnection((int) $connection->id);
        }

        return $count;
    }
}
