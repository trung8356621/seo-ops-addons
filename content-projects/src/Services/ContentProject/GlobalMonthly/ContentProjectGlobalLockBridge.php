<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use Omnichannel\Addons\ContentProjects\Models\SeoContentGenerationLock;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Jobs\AdvanceGlobalMonthlyBatchJob;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunRecoverableState;
use App\Support\RuntimeLogger;

/**
 * Bridge between Project Run lifecycle and Global Generation Lock / Global Batch.
 */
final class ContentProjectGlobalLockBridge
{
    public const SETTINGS_LOCK_TOKEN = 'global_generation_lock_token';

    public function __construct(
        private readonly GlobalGenerationLock $lock,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array{ok: true}|array{ok: false, message: string, holder: ?array<string, mixed>}
     */
    public function assertMayStart(array $settings): array
    {
        $inherits = (bool) ($settings['inherits_global_lock'] ?? false);
        $batchId = (int) ($settings['global_batch_id'] ?? 0);
        $inspect = $this->lock->inspect();
        $state = (string) ($inspect['state'] ?? GlobalGenerationLock::STATE_UNAVAILABLE);

        if ($inherits) {
            // Child under Global Batch: require verified batch ownership (fail-closed if unavailable).
            if ($batchId <= 0 || ! $this->lock->assertHeldBy(SeoContentGenerationLock::OWNER_GLOBAL_BATCH, $batchId)) {
                return [
                    'ok' => false,
                    'message' => $state === GlobalGenerationLock::STATE_UNAVAILABLE
                        ? GlobalGenerationLock::UNAVAILABLE_MESSAGE
                        : GlobalGenerationLock::BUSY_MESSAGE,
                    'holder' => is_array($inspect['holder'] ?? null) ? $inspect['holder'] : null,
                ];
            }

            return ['ok' => true];
        }

        if ($state === GlobalGenerationLock::STATE_UNAVAILABLE) {
            return [
                'ok' => false,
                'message' => GlobalGenerationLock::UNAVAILABLE_MESSAGE,
                'holder' => null,
            ];
        }

        if ($state === GlobalGenerationLock::STATE_HELD) {
            return [
                'ok' => false,
                'message' => GlobalGenerationLock::BUSY_MESSAGE,
                'holder' => is_array($inspect['holder'] ?? null) ? $inspect['holder'] : null,
            ];
        }

        return ['ok' => true];
    }

    /**
     * Acquire lock for an individual Project Run after the run row exists.
     *
     * @return array{ok: true, token: string}|array{ok: false, message: string, holder: ?array<string, mixed>}
     */
    public function acquireForProjectRun(SeoProjectRun $run): array
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        if (! empty($settings['inherits_global_lock'])) {
            return ['ok' => true, 'token' => ''];
        }

        $runId = (int) $run->getKey();
        $batchId = (int) ($settings['global_batch_id'] ?? 0);
        if ($batchId <= 0) {
            $batchId = (int) (SeoContentGlobalBatch::query()
                ->where('current_project_run_id', $runId)
                ->value('id') ?? 0);
        }

        if ($batchId > 0 && $this->lock->assertHeldBy(SeoContentGenerationLock::OWNER_GLOBAL_BATCH, $batchId)) {
            $settings['inherits_global_lock'] = true;
            $settings['global_batch_id'] = $batchId;
            $run->update(['settings' => $settings]);

            return ['ok' => true, 'token' => ''];
        }

        $acquired = $this->lock->acquire(
            SeoContentGenerationLock::OWNER_PROJECT_RUN,
            $runId,
            [
                'label' => 'Project Run #'.$runId,
                'project_id' => (int) $run->project_id,
            ],
        );

        if (! ($acquired['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => GlobalGenerationLock::BUSY_MESSAGE,
                'holder' => $acquired['holder'] ?? null,
            ];
        }

        $token = (string) $acquired['token'];
        $settings[self::SETTINGS_LOCK_TOKEN] = $token;
        $run->update(['settings' => $settings]);

        return ['ok' => true, 'token' => $token];
    }

    public function releaseForProjectRun(SeoProjectRun $run): void
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        if (! empty($settings['inherits_global_lock']) || (int) ($settings['global_batch_id'] ?? 0) > 0) {
            return;
        }

        $runId = (int) $run->getKey();
        $isChildOfBatch = SeoContentGlobalBatch::query()
            ->where('current_project_run_id', $runId)
            ->exists();
        if ($isChildOfBatch) {
            return;
        }

        $token = (string) ($settings[self::SETTINGS_LOCK_TOKEN] ?? '');
        if ($token === '') {
            return;
        }

        $this->lock->releaseIfOwner(
            SeoContentGenerationLock::OWNER_PROJECT_RUN,
            $runId,
            $token,
        );
    }

    public function heartbeatForRun(SeoProjectRun $run): void
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $batchId = (int) ($settings['global_batch_id'] ?? 0);
        if ($batchId <= 0) {
            $batchId = (int) (SeoContentGlobalBatch::query()
                ->where('current_project_run_id', (int) $run->getKey())
                ->value('id') ?? 0);
        }

        if ($batchId > 0) {
            $holder = $this->lock->currentHolder();
            if ($holder !== null
                && ($holder['owner_type'] ?? '') === SeoContentGenerationLock::OWNER_GLOBAL_BATCH
                && (int) ($holder['owner_id'] ?? 0) === $batchId
            ) {
                $this->lock->heartbeat((string) $holder['owner_token']);
            }

            return;
        }

        $token = (string) ($settings[self::SETTINGS_LOCK_TOKEN] ?? '');
        if ($token !== '') {
            $this->lock->heartbeat($token);
        }
    }

    /**
     * Called when a Project Run reaches a terminal status.
     * Recoverable WORKER_LOST / circuit_breaker: keep lock, do not advance batch.
     */
    public function onProjectRunTerminal(SeoProjectRun $run): void
    {
        if (ContentProjectRunRecoverableState::isRecoverableRun($run)) {
            RuntimeLogger::info('global_generation_lock.hold_recoverable', [
                'run_id' => (int) $run->getKey(),
                'reason' => ContentProjectRunRecoverableState::reasonFromEngine(
                    is_array($run->settings['php_engine'] ?? null) ? $run->settings['php_engine'] : []
                ),
            ]);

            return;
        }

        $settings = is_array($run->settings) ? $run->settings : [];
        $batchId = (int) ($settings['global_batch_id'] ?? 0);
        if ($batchId <= 0) {
            $batchId = (int) (SeoContentGlobalBatch::query()
                ->where('current_project_run_id', (int) $run->getKey())
                ->value('id') ?? 0);
        }

        $this->releaseForProjectRun($run);

        if ($batchId > 0) {
            try {
                AdvanceGlobalMonthlyBatchJob::dispatch($batchId, (int) $run->getKey());
            } catch (\Throwable $e) {
                RuntimeLogger::report($e, [
                    'endpoint' => 'global_monthly_batch.advance_dispatch',
                    'batch_id' => $batchId,
                    'run_id' => (int) $run->getKey(),
                ]);
            }
        }
    }
}
