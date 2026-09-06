<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;

/**
 * Repair soft-poisoned runtime health rows (Unavailable from rate_limit/transient).
 *
 * php artisan seo:ai:repair-runtime-health --dry-run
 * php artisan seo:ai:repair-runtime-health
 * php artisan seo:ai:repair-runtime-health --user=2
 */
final class RepairAiRuntimeHealthCommand extends Command
{
    protected $signature = 'seo:ai:repair-runtime-health
        {--dry-run : Report repairable rows without writing}
        {--user= : Limit to a single routing owner user id}
        {--connection= : Limit to a single api_connection_id}';

    protected $description = 'Repair soft-poisoned AI runtime health Unavailable rows (rate_limit/transient only)';

    /** @var list<string> */
    private const SOFT_CLASSES = [
        AiFailureClass::RateLimited->value,
        AiFailureClass::TransientProvider->value,
        AiFailureClass::ProviderEmptyOutput->value,
        AiFailureClass::ProviderInvalidOutput->value,
        AiFailureClass::ProviderRefusal->value,
    ];

    public function handle(): int
    {
        if (! Schema::connection(AiRuntimeHealthService::CONNECTION_NAME)->hasTable('ai_runtime_health_states')) {
            $this->warn('ai_runtime_health_states table not ready.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user');
        $connectionId = $this->option('connection');

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
            'users_scanned=%d models_scanned=%d connections_scanned=%d repairable=%d repaired=%d skipped_hard=%d skipped_manual_lock=%d dry_run=%s',
            $users->count(),
            $models,
            $connections,
            $repairable,
            $repaired,
            $skippedHard,
            $skippedManual,
            $dryRun ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
