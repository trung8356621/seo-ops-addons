<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

use Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncStepJob;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncBatch;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRunStep;
use Omnichannel\Addons\SiteSync\Services\Capability\SiteCapabilityResolver;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncBatchData;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Fallback\WorkspaceFallbackRegistry;
use Omnichannel\Addons\SiteSync\Services\Inbound\SiteSyncStagingWriter;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\ProviderKeywordReconciler;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteSyncBatchReconciler;
use Omnichannel\Addons\SiteSync\Services\Progress\SiteSyncProgressTracker;
use Omnichannel\Addons\SiteSync\Services\Progress\SiteSyncStepCatalog;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use Omnichannel\Addons\SiteSync\Support\SiteSyncImportContext;
use App\Models\Site as CoreSite;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SiteSyncStepRunner
{
    public function __construct(
        private readonly WordPressSiteSyncClient $client,
        private readonly SiteCapabilityResolver $capabilities,
        private readonly SiteSyncStagingWriter $staging,
        private readonly SiteSyncBatchReconciler $reconciler,
        private readonly WorkspaceFallbackRegistry $fallbacks,
        private readonly SiteSyncFeatureFlags $flags,
        private readonly SiteSyncRunExecution $execution,
    ) {}

    private ?SiteSyncProgressTracker $progress = null;

    private function progress(): SiteSyncProgressTracker
    {
        return $this->progress ??= new SiteSyncProgressTracker();
    }

    public function runNext(int $runId, bool $dispatchContinue = true, int $jobGeneration = 0): void
    {
        $started = microtime(true);
        $run = $this->execution->freshRun($runId);
        if ($run === null) {
            RuntimeLogger::warning('site_sync.step_claim_rejected', [
                'run_id' => $runId,
                'claim_result' => SiteSyncStepClaimResult::MissingRun->value,
            ]);

            return;
        }

        if ($this->execution->isCanceled($run)) {
            RuntimeLogger::warning('site_sync.job_skipped_canceled', [
                'run_id' => $runId,
                'step' => (string) ($run->current_step ?? ''),
                'job_generation' => $jobGeneration,
                'run_generation' => $this->execution->readGeneration($run),
            ]);
            $this->persistClaimResult($run, null, SiteSyncStepClaimResult::Cancelled);

            return;
        }

        if ($jobGeneration > 0 && $this->execution->readGeneration($run) !== $jobGeneration) {
            RuntimeLogger::warning('site_sync.job_skipped_canceled', [
                'run_id' => $runId,
                'step' => (string) ($run->current_step ?? ''),
                'job_generation' => $jobGeneration,
                'run_generation' => $this->execution->readGeneration($run),
                'reason' => 'stale_generation',
            ]);

            return;
        }

        if ((string) $run->status === 'completed') {
            $this->persistClaimResult($run, null, SiteSyncStepClaimResult::AlreadyCompleted);

            return;
        }

        if (! $this->flags->orchestratorEnabled()) {
            $run->forceFill([
                'status' => 'failed',
                'error_message' => 'Orchestrator disabled',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $site = CoreSite::query()->find((int) $run->site_id);
        if ($site === null) {
            $this->persistClaimResult($run, null, SiteSyncStepClaimResult::MissingRun, 'Site not found');
            $run->forceFill([
                'status' => 'failed',
                'error_message' => 'Site not found',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $step = SeoSiteSyncRunStep::query()
            ->where('run_id', $runId)
            ->whereIn('status', ['pending', 'failed'])
            ->orderBy('step_order')
            ->first();

        if ($step === null) {
            $runningStep = SeoSiteSyncRunStep::query()
                ->where('run_id', $runId)
                ->where('status', 'running')
                ->orderBy('step_order')
                ->first();

            if ($runningStep !== null) {
                $run->refresh();
                if ($this->execution->isCanceled($run)) {
                    return;
                }

                $checkpoint = is_array($runningStep->checkpoint) ? $runningStep->checkpoint : [];
                // Deferred poll (score_missing_articles) intentionally leaves status=running and
                // re-dispatches ProcessSiteSyncStepJob. That continuation MUST reclaim immediately —
                // treating a fresh updated_at as OwnedByOtherWorker drops the queue and sticks the run.
                $isDeferredContinuation = ! empty($checkpoint['deferred']);
                $lastTouchedAt = $runningStep->updated_at ?? $runningStep->started_at;
                if (!$isDeferredContinuation && $lastTouchedAt !== null && $lastTouchedAt->greaterThan(now()->subMinutes(10))) {
                    $this->persistClaimResult($run, $runningStep, SiteSyncStepClaimResult::OwnedByOtherWorker);

                    return;
                }

                $this->persistClaimResult(
                    $run,
                    $runningStep,
                    $isDeferredContinuation ? SiteSyncStepClaimResult::Claimed : SiteSyncStepClaimResult::StaleLock,
                    $isDeferredContinuation
                        ? 'Reclaiming deferred step continuation'
                        : 'Reclaiming stale running step',
                );
                $step = $runningStep;
            }
        }

        if ($step === null) {
            $run->refresh();
            if ($this->execution->isCanceled($run)) {
                return;
            }

            $this->persistClaimResult($run, null, SiteSyncStepClaimResult::AlreadyCompleted);
            $this->finalizeRunIfComplete($run, $site, is_array($run->counters) ? $run->counters : []);

            return;
        }

        $run->refresh();
        if ($this->execution->isCanceled($run)) {
            return;
        }

        $this->persistClaimResult($run, $step, SiteSyncStepClaimResult::Claimed);

        $run->forceFill([
            'status' => 'running',
            'current_step' => $step->step_key,
        ])->save();
        $this->checkpointProgress($run, (string) $step->step_key, [
            'status' => 'running',
            'attempt' => (int) $step->attempt_count,
            'max_attempts' => 3,
        ], true);

        $checkpoint = is_array($step->checkpoint) ? $step->checkpoint : [];
        // Clear defer marker while actively executing so a concurrent job cannot
        // treat this as a free deferred continuation mid-flight.
        unset($checkpoint['deferred'], $checkpoint['deferred_at'], $checkpoint['deferred_until']);

        $step->forceFill([
            'status' => 'running',
            'started_at' => $step->started_at ?? now(),
            'attempt_count' => (int) ($step->attempt_count ?? 0) + 1,
            'checkpoint' => $checkpoint,
            'error_message' => null,
        ])->save();

        try {
            RuntimeLogger::warning('site_sync.step_started', [
                'run_id' => $runId,
                'domain_id' => (int) $site->id,
                'tenant_id' => (int) ($site->user_id ?? 0),
                'step' => (string) $step->step_key,
                'attempt' => (int) $step->attempt_count,
                'mode' => (string) $run->mode,
                'force_full' => (bool) ((is_array($run->meta) ? $run->meta : [])['force_full'] ?? false),
                'claim_result' => SiteSyncStepClaimResult::Claimed->value,
            ]);

            $metrics = $this->executeStep($site, $run, (string) $step->step_key);

            $run->refresh();
            if ($this->execution->isCanceled($run)) {
                $step->refresh();
                if ((string) $step->status === 'running') {
                    $step->forceFill([
                        'status' => 'skipped',
                        'error_message' => 'Canceled by operator',
                        'finished_at' => now(),
                    ])->save();
                }

                return;
            }

            if (! empty($metrics['__canceled_stop'])) {
                $step->forceFill([
                    'status' => 'skipped',
                    'error_message' => 'Canceled by operator',
                    'finished_at' => now(),
                ])->save();

                return;
            }

            if (! empty($metrics['__defer_step'])) {
                unset($metrics['__defer_step']);
                $checkpoint = is_array($step->checkpoint) ? $step->checkpoint : [];
                $checkpoint['deferred'] = true;
                $checkpoint['deferred_at'] = now()->toIso8601String();
                $checkpoint['deferred_until'] = now()
                    ->addSeconds(max(5, (int) ($metrics['defer_seconds'] ?? 20)))
                    ->toIso8601String();
                $step->forceFill([
                    'status' => 'running',
                    'metrics' => $metrics,
                    'checkpoint' => $checkpoint,
                    'error_message' => null,
                ])->save();

                $counters = is_array($run->counters) ? $run->counters : [];
                foreach ($metrics as $key => $value) {
                    if (is_int($value) || is_float($value)) {
                        $counters[$key] = (int) $value;
                    }
                }
                $meta = is_array($run->meta) ? $run->meta : [];
                $meta['last_progress_at'] = now()->toIso8601String();
                $meta['scoring_deferred'] = true;
                $run->counters = $counters;
                $run->meta = $meta;
                $run->forceFill([
                    'status' => 'running',
                    'current_step' => $step->step_key,
                ])->save();

                $delaySeconds = max(5, (int) ($metrics['defer_seconds'] ?? 20));
                if ($dispatchContinue) {
                    $this->execution->dispatchContinuation(
                        $runId,
                        $jobGeneration > 0 ? $jobGeneration : $this->execution->readGeneration($run),
                        $delaySeconds,
                        (string) $step->step_key,
                        'defer_step',
                    );
                }

                return;
            }

            $checkpoint = is_array($step->checkpoint) ? $step->checkpoint : [];
            unset($checkpoint['deferred'], $checkpoint['deferred_at'], $checkpoint['deferred_until']);
            $step->forceFill([
                'status' => 'completed',
                'metrics' => $metrics,
                'checkpoint' => $checkpoint,
                'finished_at' => now(),
            ])->save();

            $counters = is_array($run->counters) ? $run->counters : [];
            foreach ($metrics as $key => $value) {
                if (! is_int($value) && ! is_float($value)) {
                    continue;
                }
                if (str_starts_with((string) $key, 'scoring_') || str_starts_with((string) $key, 'workspace_scores_')) {
                    $counters[$key] = (int) $value;
                } else {
                    $counters[$key] = (int) (($counters[$key] ?? 0) + (int) $value);
                }
            }
            if (isset($metrics['warnings']) && is_array($metrics['warnings'])) {
                $warnings = is_array($run->warnings) ? $run->warnings : [];
                $run->warnings = array_values(array_unique([...$warnings, ...$metrics['warnings']]));
            }
            $meta = is_array($run->meta) ? $run->meta : [];
            $meta['last_progress_at'] = now()->toIso8601String();
            unset($meta['scoring_deferred']);
            $run->meta = $meta;
            $run->counters = $counters;
            $run->cursor = isset($metrics['cursor']) ? (string) $metrics['cursor'] : $run->cursor;
            $run->save();
            $this->checkpointProgress($run, (string) $step->step_key, [
                'status' => 'running',
                'current' => (int) ($counters['checked'] ?? 0),
                'total' => (int) ($counters['total_to_check'] ?? 0) ?: null,
                'metrics' => [
                    'changed' => (int) ($counters['updated'] ?? 0),
                    'unchanged' => (int) ($counters['unchanged'] ?? 0),
                    'failed' => (int) ($counters['failed'] ?? 0),
                    'checked' => (int) ($counters['checked'] ?? 0),
                ],
            ]);

            $next = SeoSiteSyncRunStep::query()
                ->where('run_id', $runId)
                ->where('status', 'pending')
                ->orderBy('step_order')
                ->first();

            if ($next === null) {
                $run->refresh();
                if ($this->execution->isCanceled($run)) {
                    return;
                }

                $this->finalizeRunIfComplete($run, $site, $counters);

                return;
            }

            if ($dispatchContinue) {
                $this->execution->dispatchContinuation(
                    $runId,
                    $jobGeneration > 0 ? $jobGeneration : $this->execution->readGeneration($run),
                    null,
                    (string) $step->step_key,
                    'next_step',
                );
            } else {
                $this->runNext($runId, false, $jobGeneration);
            }

            RuntimeLogger::warning('site_sync.step_completed', [
                'run_id' => $runId,
                'domain_id' => (int) $site->id,
                'tenant_id' => (int) ($site->user_id ?? 0),
                'step' => (string) $step->step_key,
                'attempt' => (int) $step->attempt_count,
                'mode' => (string) $run->mode,
                'force_full' => (bool) ((is_array($run->meta) ? $run->meta : [])['force_full'] ?? false),
                'metrics' => $metrics,
                'next_step' => (string) $next->step_key,
                'next_job_dispatched' => $dispatchContinue,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'business_outcome' => 'step_completed_continue',
            ]);
        } catch (Throwable $e) {
            $run->refresh();
            if ($this->execution->isCanceled($run)) {
                return;
            }

            RuntimeLogger::warning('site_sync.step_failed', [
                'run_id' => $runId,
                'step' => $step->step_key,
                'error' => $e->getMessage(),
            ]);
            $step->forceFill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();
            $run->forceFill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'resumable' => true,
            ])->save();
            $this->checkpointProgress($run, (string) $step->step_key, [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], true);

            $this->notifySiteSyncFailed($run, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $counters
     */
    private function finalizeRunIfComplete(SeoSiteSyncRun $run, CoreSite $site, array $counters): void
    {
        $run->refresh();
        if ($this->execution->isCanceled($run)) {
            return;
        }

        $validation = SiteSyncRunCompletionGuard::validate($run);
        if (! $validation['ok']) {
            $run->forceFill([
                'status' => 'needs_attention',
                'current_step' => 'finalize',
                'error_message' => 'Force full sync incomplete: '.($validation['reason'] ?? 'unknown'),
                'resumable' => true,
            ])->save();
            $this->checkpointProgress($run, 'finalize', [
                'status' => 'needs_attention',
                'current' => (int) (is_array($run->counters) ? ($run->counters['reconciled'] ?? $run->counters['checked'] ?? 0) : 0),
                'total' => (int) (is_array($run->counters) ? ($run->counters['total_to_check'] ?? 0) : 0) ?: null,
                'message' => $validation['reason'],
            ], true);

            RuntimeLogger::warning('site_sync.run_incomplete', [
                'run_id' => (int) $run->id,
                'site_id' => (int) $run->site_id,
                'reason' => $validation['reason'],
            ]);

            return;
        }

        $failedScores = (int) ($counters['scoring_failed'] ?? 0);
        $finalStatus = $failedScores > 0 || (is_array($run->warnings) && $run->warnings !== [])
            ? 'completed_with_warnings'
            : 'completed';
        $runCounters = is_array($run->counters) ? $run->counters : [];
        $run->forceFill([
            'status' => $finalStatus,
            'current_step' => 'finalize',
            'finished_at' => now(),
        ])->save();
        $this->checkpointProgress($run, 'finalize', [
            'status' => $finalStatus,
            'finished_at' => now()->toIso8601String(),
            'current' => (int) ($runCounters['reconciled'] ?? $runCounters['checked'] ?? 0),
            'total' => (int) ($runCounters['total_to_check'] ?? 0) ?: null,
        ], true);
        $this->maybeMarkBootstrapComplete($site, $run);
        $this->notifySiteSyncTerminal($run, $finalStatus, $counters);
    }

    private function persistClaimResult(
        SeoSiteSyncRun $run,
        ?SeoSiteSyncRunStep $step,
        SiteSyncStepClaimResult $result,
        ?string $message = null,
    ): void {
        $meta = is_array($run->meta) ? $run->meta : [];
        $meta['last_claim_result'] = $result->value;
        $meta['last_progress_at'] = now()->toIso8601String();
        if ($message !== null) {
            $meta['last_claim_message'] = $message;
        }
        $run->meta = $meta;
        $run->save();

        if ($step !== null) {
            $checkpoint = is_array($step->checkpoint) ? $step->checkpoint : [];
            $checkpoint['claim_result'] = $result->value;
            $checkpoint['claimed_at'] = now()->toIso8601String();
            if ($message !== null) {
                $checkpoint['claim_message'] = $message;
            }
            $step->forceFill(['checkpoint' => $checkpoint])->save();
        }

        RuntimeLogger::warning('site_sync.step_claim_result', [
            'run_id' => (int) $run->id,
            'site_id' => (int) $run->site_id,
            'step' => $step?->step_key,
            'claim_result' => $result->value,
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeStep(CoreSite $site, SeoSiteSyncRun $run, string $stepKey): array
    {
        return match ($stepKey) {
            'detect_capability' => $this->detectCapability($site),
            'request_snapshot_delta' => $this->requestSnapshotDelta($site, $run),
            'sync_site_profile' => $this->syncSiteProfile($site, $run),
            'sync_url_catalog' => $this->syncUrlCatalog($site, $run),
            'sync_provider_keywords' => $this->syncProviderKeywords($site, $run),
            'missing_capability_fallback' => $this->missingCapabilityFallback($site),
            'validate_changed_links' => $this->validateChangedLinks($site, $run),
            'score_missing_articles' => $this->scoreMissingArticles($site, $run),
            'finalize' => $this->finalize($run),
            default => throw new \InvalidArgumentException('Unknown site sync step: '.$stepKey),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function detectCapability(CoreSite $site): array
    {
        $result = $this->client->fetchCapabilities($site);
        if (! ($result['success'] ?? false) || ! isset($result['manifest'])) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Capability detect failed'));
        }

        $this->capabilities->store($site, $result['manifest']);

        try {
            $heartbeat = $this->client->fetchHeartbeat($site);
            if (($heartbeat['success'] ?? false) && isset($heartbeat['heartbeat'])) {
                $payload = $heartbeat['heartbeat'];
                if (is_array($payload)) {
                    $payload['observed_at'] = now()->toIso8601String();
                    SiteSyncSiteMeta::putJson($site, 'seo_wp_heartbeat', $payload);
                }
            }
        } catch (Throwable) {
            // Heartbeat must not break capability detect.
        }

        $missing = $this->capabilities->missingCapabilities($site);
        if (is_array($missing) && $missing !== []) {
            try {
                $tenantId = (int) ($site->user_id ?? 0);
                if ($tenantId <= 0) {
                    $tenantId = (int) (\App\Models\User::query()
                        ->where('seo_role', \App\Models\User::SEO_ROLE_MANAGER)
                        ->whereNull('parent_id')
                        ->value('id') ?? 0);
                }
                foreach ($missing as $capability) {
                    $name = is_string($capability) ? $capability : (string) ($capability['name'] ?? $capability['key'] ?? 'unknown');
                    app(\Omnichannel\Addons\Seo\Services\Notifications\Publishers\WordPressConnectionNotificationPublisher::class)
                        ->notifyCapabilityMissing(
                            $tenantId,
                            (int) $site->id,
                            $name,
                            'Phiên bản hiện tại chưa hỗ trợ '.$name.'.',
                            (int) $site->id,
                        );
                }
            } catch (Throwable) {
                // Notification must not break sync.
            }
        }

        return [
            'capabilities_stored' => 1,
            'bridge_version' => $result['manifest']->bridgeVersion,
            'missing' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestSnapshotDelta(CoreSite $site, SeoSiteSyncRun $run): array
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $forceFull = $run->mode === SiteSyncSchema::MODE_FORCE_FULL
            || (bool) ($meta['force_full'] ?? false);
        $includeUnchanged = $forceFull || (bool) ($meta['include_unchanged'] ?? false);
        $cursorBefore = $run->cursor;
        $chunkStarted = microtime(true);

        $query = [
            'mode' => $forceFull ? SiteSyncSchema::MODE_FORCE_FULL : $run->mode,
            'cursor' => $forceFull ? $run->cursor : $run->cursor,
            'run_token' => $run->run_token,
            'include_unchanged' => $includeUnchanged,
            'fields' => SiteSyncSchema::FIELDS_METADATA,
        ];

        if ($forceFull && $run->cursor === null) {
            $query['cursor'] = null;
        }

        RuntimeLogger::warning('site_sync.chunk_started', [
            'run_id' => (int) $run->id,
            'step' => 'request_snapshot_delta',
            'cursor_before' => $cursorBefore,
            'force_full' => $forceFull,
        ]);

        // force_full / snapshot: paginated batches; never modified-since delta.
        $result = ($forceFull || $run->mode === SiteSyncSchema::MODE_SNAPSHOT)
            ? $this->client->fetchBatches($site, $query)
            : $this->client->fetchDelta($site, $query);

        if (! ($result['success'] ?? false) || ! isset($result['batch'])) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Delta/snapshot fetch failed'));
        }

        $batch = $result['batch'];
        $staged = $this->staging->stage($site, $batch, (int) $run->id, $forceFull);

        $batchIds = is_array($meta['batch_ids'] ?? null) ? $meta['batch_ids'] : [];
        $batchIds[] = (int) $staged->id;
        $meta['batch_ids'] = array_values(array_unique($batchIds));
        if ($forceFull) {
            $meta['include_unchanged'] = true;
            $meta['force_full'] = true;
        }
        $run->meta = $meta;

        $counters = is_array($run->counters) ? $run->counters : [];
        $fetchedAtStart = (int) ($counters['fetched'] ?? 0);
        $fetched = count($batch->articles);
        $counters['fetched'] = $fetchedAtStart + $fetched;
        $counters['checked'] = (int) ($counters['fetched']);
        $totalFromWp = (int) ($batch->raw['total_count'] ?? 0);
        if ($forceFull && $totalFromWp > 0) {
            $counters['total_to_check'] = max((int) ($counters['total_to_check'] ?? 0), $totalFromWp);
        }
        $run->counters = $counters;
        $run->cursor = $batch->cursor;
        $run->save();
        $this->checkpointBatch($run, 'request_snapshot_delta', $counters, 1, null, $fetched);

        if ($forceFull && $totalFromWp === 0 && $fetched === 0 && $run->cursor === null) {
            $localArticles = SeoArticle::query()->where('site_id', (int) $site->id)->count();
            if ($localArticles > 0) {
                throw new \RuntimeException("Force full sync discovered zero WordPress records while {$localArticles} local articles exist.");
            }
        }

        $loops = 0;
        $maxExtra = max(0, SiteSyncSchema::SNAPSHOT_BATCHES_PER_JOB - 1);
        while (($forceFull || $run->mode === SiteSyncSchema::MODE_SNAPSHOT) && $batch->hasMore && $loops < $maxExtra) {
            $loops++;
            if ($this->execution->isRunStopped((int) $run->id)) {
                return $this->execution->chunkStoppedPayload((int) $run->id, 'request_snapshot_delta', 'snapshot_loop', $run->cursor);
            }
            $run->refresh();
            if ($this->execution->isCanceled($run)) {
                return $this->execution->chunkStoppedPayload((int) $run->id, 'request_snapshot_delta', 'snapshot_loop', $run->cursor);
            }
            $batchStarted = microtime(true);
            $more = $this->client->fetchBatches($site, [
                'mode' => $forceFull ? SiteSyncSchema::MODE_FORCE_FULL : $run->mode,
                'cursor' => $run->cursor,
                'run_token' => $run->run_token,
                'include_unchanged' => true,
                'fields' => SiteSyncSchema::FIELDS_METADATA,
            ]);
            if (! ($more['success'] ?? false) || ! isset($more['batch'])) {
                break;
            }
            $batch = $more['batch'];
            $staged = $this->staging->stage($site, $batch, (int) $run->id, $forceFull);
            $meta = is_array($run->meta) ? $run->meta : [];
            $batchIds = is_array($meta['batch_ids'] ?? null) ? $meta['batch_ids'] : [];
            $batchIds[] = (int) $staged->id;
            $meta['batch_ids'] = array_values(array_unique($batchIds));
            $run->meta = $meta;
            $counters = is_array($run->counters) ? $run->counters : [];
            $fetched = count($batch->articles);
            $counters['fetched'] = (int) ($counters['fetched'] ?? 0) + $fetched;
            $counters['checked'] = (int) ($counters['fetched']);
            $totalFromWp = (int) ($batch->raw['total_count'] ?? 0);
            if ($forceFull && $totalFromWp > 0) {
                $counters['total_to_check'] = max((int) ($counters['total_to_check'] ?? 0), $totalFromWp);
            }
            $run->counters = $counters;
            $run->cursor = $batch->cursor;
            $run->save();
            $this->checkpointBatch(
                $run,
                'request_snapshot_delta',
                $counters,
                $loops + 1,
                round(microtime(true) - $batchStarted, 1),
                $fetched,
            );
        }

        $recordsInChunk = (int) ($counters['fetched'] ?? 0) - $fetchedAtStart;
        RuntimeLogger::warning('site_sync.chunk_completed', [
            'run_id' => (int) $run->id,
            'step' => 'request_snapshot_delta',
            'cursor_before' => $cursorBefore,
            'cursor_after' => $run->cursor,
            'records_in_chunk' => $fetched,
            'records_processed_total' => (int) ($counters['fetched'] ?? 0),
            'records_total' => (int) ($counters['total_to_check'] ?? 0),
            'has_more' => $batch->hasMore,
            'duration_ms' => (int) round((microtime(true) - $chunkStarted) * 1000),
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'continuation_dispatched' => $batch->hasMore && ($forceFull || $run->mode === SiteSyncSchema::MODE_SNAPSHOT),
        ]);

        if ($batch->hasMore && ($forceFull || $run->mode === SiteSyncSchema::MODE_SNAPSHOT)) {
            if ($this->execution->isRunStopped((int) $run->id)) {
                return $this->execution->chunkStoppedPayload((int) $run->id, 'request_snapshot_delta', 'snapshot_defer', $run->cursor);
            }

            $meta = is_array($run->meta) ? $run->meta : [];
            $meta['has_more_batches'] = true;
            $meta['pending_cursor'] = $run->cursor;
            unset($meta['snapshot_exhausted']);
            $run->meta = $meta;
            $run->save();

            return [
                '__defer_step' => true,
                'defer_seconds' => 0,
                'batches_staged' => count(is_array($run->meta['batch_ids'] ?? null) ? $run->meta['batch_ids'] : []),
                'cursor' => $run->cursor,
                'has_more' => true,
                'articles_in_batch' => (int) ($counters['fetched'] ?? 0),
                'force_full' => $forceFull,
                'include_unchanged' => $includeUnchanged,
            ];
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $meta['snapshot_exhausted'] = true;
        unset($meta['has_more_batches'], $meta['pending_cursor']);
        $run->meta = $meta;
        $run->save();

        return [
            'batches_staged' => count(is_array($run->meta['batch_ids'] ?? null) ? $run->meta['batch_ids'] : []),
            'cursor' => $run->cursor,
            'has_more' => false,
            'urls_changed' => count($batch->links),
            'articles_in_batch' => (int) ($counters['fetched'] ?? 0),
            'force_full' => $forceFull,
            'include_unchanged' => $includeUnchanged,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function syncSiteProfile(CoreSite $site, SeoSiteSyncRun $run): array
    {
        $profileResult = $this->client->fetchProfile($site);
        $profile = ($profileResult['success'] ?? false) ? ($profileResult['profile'] ?? []) : [];

        $contacts = [];
        $meta = is_array($run->meta) ? $run->meta : [];
        $batchIds = is_array($meta['batch_ids'] ?? null) ? $meta['batch_ids'] : [];
        foreach ($batchIds as $batchId) {
            $batch = \Omnichannel\Addons\SiteSync\Models\SeoSiteSyncBatch::query()->find((int) $batchId);
            if ($batch === null) {
                continue;
            }
            $data = \Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncBatchData::fromArray($batch->decodedPayload());
            if ($data->profile !== null && $profile === []) {
                $profile = $data->profile;
            }
            foreach ($data->contactsSuggest as $contact) {
                $contacts[] = $contact;
            }
        }

        if (is_array($profile['contacts'] ?? null)) {
            foreach ($profile['contacts'] as $contact) {
                if (is_array($contact)) {
                    $contacts[] = $contact;
                }
            }
        }

        if ($profile === [] && $contacts === []) {
            return ['profile_synced' => 0];
        }

        $this->reconciler->applyProfileSuggestOnly($site, $profile !== [] ? $profile : null, $contacts);

        return ['profile_synced' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    private function syncUrlCatalog(CoreSite $site, SeoSiteSyncRun $run): array
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $forceFull = $run->mode === SiteSyncSchema::MODE_FORCE_FULL || (bool) ($meta['force_full'] ?? false);
        $batchIds = is_array($meta['batch_ids'] ?? null) ? $meta['batch_ids'] : [];
        $stepRow = SeoSiteSyncRunStep::query()
            ->where('run_id', (int) $run->id)
            ->where('step_key', 'sync_url_catalog')
            ->first();
        $checkpoint = is_array($stepRow?->checkpoint) ? $stepRow->checkpoint : [];
        $appliedOffset = (int) ($checkpoint['catalog_applied_offset'] ?? 0);
        $chunkStarted = microtime(true);

        RuntimeLogger::warning('site_sync.chunk_started', [
            'run_id' => (int) $run->id,
            'step' => 'sync_url_catalog',
            'catalog_applied_offset' => $appliedOffset,
            'batch_total' => count($batchIds),
            'force_full' => $forceFull,
        ]);

        $totals = [
            'articles' => 0,
            'urls_synced' => 0,
            'scores' => 0,
            'provider_keywords' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
        ];

        $applied = 0;
        $batchCount = count($batchIds);
        for ($index = $appliedOffset; $index < $batchCount && $applied < SiteSyncSchema::CATALOG_BATCHES_PER_JOB; $index++) {
            if ($this->execution->isRunStopped((int) $run->id)) {
                $checkpoint['catalog_applied_offset'] = $appliedOffset;
                $stepRow?->forceFill(['checkpoint' => $checkpoint])->save();

                return $this->execution->chunkStoppedPayload((int) $run->id, 'sync_url_catalog', 'catalog_batch', $run->cursor);
            }

            $batchId = (int) $batchIds[$index];
            $batch = SeoSiteSyncBatch::query()->find($batchId);
            if ($batch === null) {
                continue;
            }
            if ($batch->applied_at !== null && ! $forceFull) {
                continue;
            }
            $counters = SiteSyncImportContext::run(
                fn (): array => $this->reconciler->apply($site, $batch),
            );
            $totals['articles'] += (int) ($counters['articles'] ?? 0);
            $totals['urls_synced'] += (int) ($counters['urls_synced'] ?? 0);
            $totals['scores'] += (int) ($counters['scores'] ?? 0);
            $totals['provider_keywords'] += (int) ($counters['provider_keywords'] ?? 0);
            $totals['created'] += (int) ($counters['created'] ?? 0);
            $totals['updated'] += (int) ($counters['updated'] ?? 0);
            $totals['unchanged'] += (int) ($counters['unchanged'] ?? 0);
            $totals['failed'] += (int) ($counters['failed'] ?? 0);
            $totals['wp_post_type_backfilled'] = (int) ($totals['wp_post_type_backfilled'] ?? 0)
                + (int) ($counters['wp_post_type_backfilled'] ?? 0);
            $applied++;
            $appliedOffset = $index + 1;
            $this->checkpointCatalog($run, $totals, $appliedOffset, $batchCount);
        }

        $run->refresh();
        $runCounters = is_array($run->counters) ? $run->counters : [];
        $runCounters['reconciled'] = (int) ($runCounters['reconciled'] ?? 0) + (int) ($totals['articles'] ?? 0);
        $runCounters['checked'] = max(
            (int) ($runCounters['reconciled'] ?? 0),
            (int) ($runCounters['fetched'] ?? 0),
        );
        foreach (['created', 'updated', 'unchanged', 'failed', 'urls_synced', 'provider_keywords', 'scores'] as $key) {
            $runCounters[$key] = (int) ($runCounters[$key] ?? 0) + (int) ($totals[$key] ?? 0);
        }
        $run->counters = $runCounters;
        $run->save();

        if ($appliedOffset < $batchCount) {
            if ($this->execution->isRunStopped((int) $run->id)) {
                $checkpoint['catalog_applied_offset'] = $appliedOffset;
                $stepRow?->forceFill(['checkpoint' => $checkpoint])->save();

                return $this->execution->chunkStoppedPayload((int) $run->id, 'sync_url_catalog', 'catalog_defer', $run->cursor);
            }

            $checkpoint['catalog_applied_offset'] = $appliedOffset;
            $stepRow?->forceFill(['checkpoint' => $checkpoint])->save();

            RuntimeLogger::warning('site_sync.chunk_completed', [
                'run_id' => (int) $run->id,
                'step' => 'sync_url_catalog',
                'records_in_chunk' => (int) ($totals['articles'] ?? 0),
                'records_processed_total' => (int) ($runCounters['reconciled'] ?? 0),
                'records_total' => (int) ($runCounters['total_to_check'] ?? 0),
                'has_more' => true,
                'duration_ms' => (int) round((microtime(true) - $chunkStarted) * 1000),
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'continuation_dispatched' => true,
                'catalog_applied_offset' => $appliedOffset,
            ]);

            return array_merge($totals, [
                '__defer_step' => true,
                'defer_seconds' => 0,
                'catalog_pending_batches' => $batchCount - $appliedOffset,
            ]);
        }

        unset($checkpoint['catalog_applied_offset']);
        $stepRow?->forceFill(['checkpoint' => $checkpoint])->save();

        // Delta-only fallback: inline fetch when snapshot was partial (legacy runs).
        if (! $forceFull && $run->cursor !== null) {
            $cursor = $run->cursor;
            $inlineLoops = 0;
            while ($inlineLoops < SiteSyncSchema::CATALOG_BATCHES_PER_JOB) {
                $inlineLoops++;
                if ($this->execution->isRunStopped((int) $run->id)) {
                    return $this->execution->chunkStoppedPayload((int) $run->id, 'sync_url_catalog', 'catalog_inline', $cursor);
                }
                $run->refresh();
                if ($this->execution->isCanceled($run)) {
                    return $this->execution->chunkStoppedPayload((int) $run->id, 'sync_url_catalog', 'catalog_inline', $cursor);
                }
                $batchStarted = microtime(true);
                $result = $this->client->fetchBatches($site, [
                    'mode' => $run->mode,
                    'cursor' => $cursor,
                    'run_token' => $run->run_token,
                    'include_unchanged' => (bool) ($meta['include_unchanged'] ?? false),
                ]);
                if (! ($result['success'] ?? false) || ! isset($result['batch'])) {
                    break;
                }
                $batchData = $result['batch'];
                $staged = $this->staging->stage($site, $batchData, (int) $run->id, false);
                $counters = SiteSyncImportContext::run(
                    fn (): array => $this->reconciler->apply($site, $staged),
                );
                $totals['articles'] += (int) ($counters['articles'] ?? 0);
                $totals['urls_synced'] += (int) ($counters['urls_synced'] ?? 0);
                $totals['scores'] += (int) ($counters['scores'] ?? 0);
                $totals['provider_keywords'] += (int) ($counters['provider_keywords'] ?? 0);
                $totals['created'] += (int) ($counters['created'] ?? 0);
                $totals['updated'] += (int) ($counters['updated'] ?? 0);
                $totals['unchanged'] += (int) ($counters['unchanged'] ?? 0);
                $totals['failed'] += (int) ($counters['failed'] ?? 0);

                $runCounters = is_array($run->counters) ? $run->counters : [];
                $fetched = count($batchData->articles);
                $runCounters['fetched'] = (int) ($runCounters['fetched'] ?? 0) + $fetched;
                $runCounters['reconciled'] = (int) ($runCounters['reconciled'] ?? 0) + (int) ($counters['articles'] ?? 0);
                $runCounters['checked'] = max(
                    (int) ($runCounters['reconciled'] ?? 0),
                    (int) ($runCounters['fetched'] ?? 0),
                );
                foreach (['created', 'updated', 'unchanged', 'failed', 'urls_synced', 'provider_keywords', 'scores'] as $key) {
                    $runCounters[$key] = (int) ($runCounters[$key] ?? 0) + (int) ($counters[$key] ?? 0);
                }
                $run->counters = $runCounters;
                $cursor = $batchData->cursor;
                $run->cursor = $cursor;
                $run->save();
                $this->checkpointCatalog($run, $totals, $appliedOffset + $inlineLoops, null, round(microtime(true) - $batchStarted, 1), $fetched);

                if ($batchData->hasMore) {
                    RuntimeLogger::warning('site_sync.chunk_completed', [
                        'run_id' => (int) $run->id,
                        'step' => 'sync_url_catalog',
                        'cursor_after' => $cursor,
                        'has_more' => true,
                        'continuation_dispatched' => true,
                        'duration_ms' => (int) round((microtime(true) - $chunkStarted) * 1000),
                    ]);

                    return array_merge($totals, [
                        '__defer_step' => true,
                        'defer_seconds' => 0,
                        'has_more' => true,
                    ]);
                }
            }
        }

        $run->refresh();
        $runCounters = is_array($run->counters) ? $run->counters : [];

        RuntimeLogger::warning('site_sync.chunk_completed', [
            'run_id' => (int) $run->id,
            'step' => 'sync_url_catalog',
            'records_processed_total' => (int) ($runCounters['reconciled'] ?? 0),
            'records_total' => (int) ($runCounters['total_to_check'] ?? 0),
            'has_more' => false,
            'duration_ms' => (int) round((microtime(true) - $chunkStarted) * 1000),
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'continuation_dispatched' => false,
        ]);

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncProviderKeywords(CoreSite $site, SeoSiteSyncRun $run): array
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $batchIds = is_array($meta['batch_ids'] ?? null) ? $meta['batch_ids'] : [];
        $updated = 0;
        $skipped = 0;
        $keywordReconciler = app(ProviderKeywordReconciler::class);

        foreach ($batchIds as $batchIndex => $batchId) {
            if ($batchIndex > 0 && $batchIndex % 10 === 0 && $this->execution->isRunStopped((int) $run->id)) {
                return $this->execution->chunkStoppedPayload((int) $run->id, 'sync_provider_keywords', 'keyword_batch', $run->cursor);
            }

            $batch = SeoSiteSyncBatch::query()->find((int) $batchId);
            if ($batch === null) {
                continue;
            }
            $data = SiteSyncBatchData::fromArray($batch->decodedPayload());
            if ($data->providerKeywords === []) {
                continue;
            }
            $result = $keywordReconciler->reconcile($site, $data->providerKeywords);
            $updated += $result['provider_updated'];
            $skipped += $result['skipped_manual'];
        }

        if ($updated > 0) {
            app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordIntelligenceScheduler::class)
                ->onImportBatch((int) $site->id, $updated);
        }

        return [
            'provider_keywords' => $updated,
            'skipped_manual_keywords' => $skipped,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingCapabilityFallback(CoreSite $site): array
    {
        $result = $this->fallbacks->runMissing($site);

        return [
            'warnings' => $result['warnings'],
            'workspace_keywords' => (int) ($result['metrics']['focus_keyword']['workspace_keywords_generated'] ?? 0),
            'fallback_404_checked' => (int) ($result['metrics']['http_404']['checked'] ?? 0),
            'fallback_404_broken' => (int) ($result['metrics']['http_404']['broken'] ?? 0),
            'fallback_metrics' => $result['metrics'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateChangedLinks(CoreSite $site, SeoSiteSyncRun $run): array
    {
        $since = $run->started_at ?? now()->subHour();
        $links = SeoSiteLinkCatalog::query()
            ->forSite((int) $site->id)
            ->where('source', SiteSyncSchema::SOURCE_WORDPRESS)
            ->where('updated_at', '>=', $since)
            ->limit(100)
            ->get();

        $checked = 0;
        $broken = 0;
        foreach ($links as $link) {
            $checked++;
            try {
                $response = Http::timeout(8)->head((string) $link->url);
                if ($response->status() >= 400) {
                    $broken++;
                    $meta = is_array($link->meta) ? $link->meta : [];
                    $meta['last_http_status'] = $response->status();
                    $link->forceFill(['meta' => $meta])->save();
                }
            } catch (Throwable) {
                $broken++;
            }
        }

        return [
            'urls_changed' => $checked,
            'links_broken' => $broken,
        ];
    }

    /**
     * Dispatch missing/stale Workspace SEO scores and wait until queue drains.
     *
     * @return array<string, mixed>
     */
    private function scoreMissingArticles(CoreSite $site, SeoSiteSyncRun $run): array
    {
        $scoring = app(\Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService::class);
        $meta = is_array($run->meta) ? $run->meta : [];
        $stepRow = SeoSiteSyncRunStep::query()
            ->where('run_id', (int) $run->id)
            ->where('step_key', 'score_missing_articles')
            ->first();

        if (empty($meta['scoring_dispatched_at'])) {
            $result = $scoring->queueMissingOrStaleForSite((int) $site->id, [
                'run_id' => (int) $run->id,
                'operation_id' => (string) ($meta['operation_id'] ?? $run->public_ref),
                'step_id' => $stepRow !== null ? (int) $stepRow->id : null,
            ]);
            $meta['scoring_dispatched_at'] = now()->toIso8601String();
            $meta['scoring_queued'] = (int) ($result['queued'] ?? 0);
            $meta['scoring_stale_queued'] = (int) ($result['stale_queued'] ?? 0);
            $meta['scoring_missing_queued'] = (int) ($result['missing_queued'] ?? 0);
            $meta['scoring_polls'] = 0;
            $run->meta = $meta;
            $run->save();
        }

        $progress = $scoring->domainProgress((int) $site->id);
        $pending = (int) ($progress['pending'] ?? 0);
        $processing = (int) ($progress['processing'] ?? 0);
        $failed = (int) ($progress['failed'] ?? 0);
        $completed = (int) ($progress['completed'] ?? 0);
        $queued = (int) ($meta['scoring_queued'] ?? 0);
        $polls = (int) ($meta['scoring_polls'] ?? 0) + 1;
        $meta['scoring_polls'] = $polls;
        $run->meta = $meta;
        $run->save();

        $inFlight = $pending + $processing;
        if ($inFlight > 0) {
            // Worker idle: pending stuck with processing=0 for many polls.
            $waitingWorker = $pending > 0 && $processing === 0 && $polls >= 3;
            if ($polls >= 6) {
                return [
                    'scoring_pending' => $pending,
                    'scoring_processing' => $processing,
                    'scoring_failed' => $failed,
                    'scoring_completed' => $completed,
                    'workspace_scores_queued' => $queued,
                    'scoring_waiting_worker' => $waitingWorker ? 1 : 0,
                    'warnings' => [
                        sprintf(
                            'Chấm SEO nền chưa xử lý xong (%d pending, %d processing). Dữ liệu sync đã áp dụng; có thể chạy chấm lại SEO riêng.',
                            $pending,
                            $processing,
                        ),
                    ],
                ];
            }

            return [
                '__defer_step' => true,
                'defer_seconds' => $waitingWorker ? 30 : 15,
                'scoring_pending' => $pending,
                'scoring_processing' => $processing,
                'scoring_failed' => $failed,
                'scoring_completed' => $completed,
                'workspace_scores_queued' => $queued,
                'scoring_waiting_worker' => $waitingWorker ? 1 : 0,
            ];
        }

        $warnings = [];
        if ($failed > 0) {
            $warnings[] = "{$failed} bài chấm SEO thất bại — có thể retry riêng bước scoring.";
        }
        if ($polls > 120) {
            $warnings[] = 'Chấm SEO chờ lâu hơn dự kiến (worker có thể chậm).';
        }

        return [
            'workspace_scores_queued' => $queued,
            'workspace_scores_generated' => $completed,
            'scoring_failed' => $failed,
            'scoring_pending' => 0,
            'scoring_processing' => 0,
            'scoring_stale_queued' => (int) ($meta['scoring_stale_queued'] ?? 0),
            'scoring_missing_queued' => (int) ($meta['scoring_missing_queued'] ?? 0),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalize(SeoSiteSyncRun $run): array
    {
        $counters = is_array($run->counters) ? $run->counters : [];
        $warnings = is_array($run->warnings) ? $run->warnings : [];
        $site = CoreSite::query()->find((int) $run->site_id);
        if ($site !== null) {
            $this->maybeMarkBootstrapComplete($site, $run);
            try {
                $progress = app(\Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService::class)
                    ->domainProgress((int) $site->id);
                $counters['workspace_scores_generated'] = (int) ($progress['completed'] ?? $counters['workspace_scores_generated'] ?? 0);
                $counters['scoring_failed'] = (int) ($progress['failed'] ?? $counters['scoring_failed'] ?? 0);
                if ((int) ($progress['failed'] ?? 0) > 0) {
                    $warnings[] = ((int) $progress['failed']).' bài chấm SEO thất bại.';
                }
            } catch (Throwable) {
                // Scoring tables may be unavailable — sync data already applied.
            }
        }

        $run->counters = $counters;
        $run->warnings = array_values(array_unique($warnings));
        $run->save();

        return [
            'finalized' => 1,
            'summary' => $counters,
            'warnings' => $warnings,
            'scoring_failed' => (int) ($counters['scoring_failed'] ?? 0),
            'workspace_scores_generated' => (int) ($counters['workspace_scores_generated'] ?? 0),
        ];
    }

    private function maybeMarkBootstrapComplete(CoreSite $site, SeoSiteSyncRun $run): void
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $forceFull = (string) $run->mode === SiteSyncSchema::MODE_FORCE_FULL
            || (bool) ($meta['force_full'] ?? false);
        // force_full traverses entire catalog — treat as bootstrap-complete when finished.
        if (! empty($meta['bootstrap']) || $forceFull) {
            SiteSyncSiteMeta::put($site, SiteSyncSchema::META_BOOTSTRAPPED_AT, now()->toIso8601String());
        }
    }

    /**
     * @param  array<string, mixed>  $counters
     */
    private function notifySiteSyncTerminal(SeoSiteSyncRun $run, string $finalStatus, array $counters): void
    {
        try {
            $publisher = app(\Omnichannel\Addons\Seo\Services\Notifications\Publishers\SiteSyncIncidentNotificationPublisher::class);
            $tenantId = $this->resolveSiteSyncTenantId($run);
            if ($tenantId <= 0) {
                return;
            }

            $connectionId = (int) ($run->site_id ?? 0);
            if ($finalStatus === 'completed') {
                // Quick sync success — intentionally no notification.
                $publisher->resolveRun($tenantId, $connectionId, (int) $run->getKey());
                if (is_string($run->current_step) && $run->current_step !== '') {
                    $publisher->resolveStuck($tenantId, $connectionId, (string) $run->current_step);
                }

                return;
            }

            $failed = (int) ($counters['scoring_failed'] ?? 0);
            $synced = (int) ($counters['urls_synced'] ?? $counters['workspace_scores_generated'] ?? 0);
            $total = max($synced + $failed, (int) ($counters['urls_total'] ?? $synced + $failed));
            if ($failed > 0 || $finalStatus === 'completed_with_warnings') {
                $publisher->notifyPartialFailure(
                    $run,
                    $tenantId,
                    $synced,
                    max($total, 1),
                    max($failed, 1),
                    null,
                );
            }
        } catch (Throwable $e) {
            RuntimeLogger::warning('seo.operational_notification.site_sync_terminal_hook_failed', [
                'run_id' => (int) $run->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifySiteSyncFailed(SeoSiteSyncRun $run, string $message): void
    {
        try {
            $tenantId = $this->resolveSiteSyncTenantId($run);
            if ($tenantId <= 0) {
                return;
            }

            $http = null;
            if (preg_match('/\b(401|403)\b/', $message, $matches) === 1) {
                $http = (int) $matches[1];
            }
            if ($http === 401 || $http === 403) {
                app(\Omnichannel\Addons\Seo\Services\Notifications\Publishers\WordPressConnectionNotificationPublisher::class)
                    ->notifyConnectionFailed(
                        $tenantId,
                        (int) ($run->site_id ?? 0),
                        'HTTP_'.$http,
                        'site #'.(int) ($run->site_id ?? 0),
                        'Publishing và Site Sync đang bị chặn do WordPress trả về '.$http.'.',
                        permanent: true,
                    );
            }

            app(\Omnichannel\Addons\Seo\Services\Notifications\Publishers\SiteSyncIncidentNotificationPublisher::class)
                ->notifyFailed($run, $tenantId, $message);
        } catch (Throwable $e) {
            RuntimeLogger::warning('seo.operational_notification.site_sync_failed_hook_failed', [
                'run_id' => (int) $run->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function checkpointProgress(SeoSiteSyncRun $run, string $phase, array $patch, bool $force = false): void
    {
        $counters = is_array($run->counters) ? $run->counters : [];
        $this->progress()->checkpoint($run, array_merge([
            'status' => (string) ($patch['status'] ?? $run->status),
            'phase' => $phase,
            'step' => SiteSyncStepCatalog::order($phase),
            'total_steps' => SiteSyncStepCatalog::totalSteps(),
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'substeps' => $this->substepsFromCounters($phase, $counters, is_array($patch['metrics'] ?? null) ? $patch['metrics'] : []),
        ], $patch), $force);
    }

    /**
     * @param  array<string, mixed>  $counters
     */
    private function checkpointBatch(
        SeoSiteSyncRun $run,
        string $phase,
        array $counters,
        int $batch,
        ?float $duration = null,
        ?int $processed = null,
    ): void {
        $this->checkpointProgress($run, $phase, [
            'status' => 'running',
            'current' => (int) ($counters['checked'] ?? $counters['fetched'] ?? 0),
            'total' => (int) ($counters['total_to_check'] ?? 0) ?: null,
            'batch' => $batch,
            'batch_duration_seconds' => $duration,
            'metrics' => [
                'checked' => (int) ($counters['checked'] ?? 0),
                'changed' => (int) ($counters['updated'] ?? 0),
                'unchanged' => (int) ($counters['unchanged'] ?? 0),
                'failed' => (int) ($counters['failed'] ?? 0),
                'processed' => $processed ?? (int) ($counters['checked'] ?? 0),
            ],
        ]);
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function checkpointCatalog(
        SeoSiteSyncRun $run,
        array $totals,
        int $batch,
        ?int $batchTotal = null,
        ?float $duration = null,
        ?int $processed = null,
    ): void {
        $runCounters = is_array($run->counters) ? $run->counters : [];
        $reconciled = (int) ($runCounters['reconciled'] ?? 0);
        $current = $reconciled > 0
            ? $reconciled
            : (int) ($runCounters['fetched'] ?? $totals['articles'] ?? 0);
        $total = (int) ($runCounters['total_to_check'] ?? 0) ?: null;
        $this->checkpointProgress($run, 'sync_url_catalog', [
            'status' => 'running',
            'current' => $current > 0 ? $current : (int) ($totals['urls_synced'] ?? 0),
            'total' => $total,
            'batch' => $batch,
            'batch_total' => $batchTotal,
            'batch_duration_seconds' => $duration,
            'metrics' => [
                'changed' => (int) ($totals['updated'] ?? 0),
                'unchanged' => (int) ($totals['unchanged'] ?? 0),
                'failed' => (int) ($totals['failed'] ?? 0),
                'articles' => (int) ($runCounters['reconciled'] ?? $totals['articles'] ?? 0),
                'processed' => $processed ?? (int) ($totals['articles'] ?? 0),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $counters
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function substepsFromCounters(string $phase, array $counters, array $metrics): array
    {
        if ($phase === 'request_snapshot_delta') {
            $current = (int) ($counters['checked'] ?? $metrics['checked'] ?? 0);
            $total = (int) ($counters['total_to_check'] ?? 0) ?: null;

            return [
                ['key' => 'fetch', 'label' => 'Tải dữ liệu WordPress', 'status' => 'active', 'current' => $current, 'total' => $total],
                ['key' => 'catalog', 'label' => 'Đồng bộ bài viết và URL', 'status' => 'pending'],
                ['key' => 'finalize', 'label' => 'Hoàn tất', 'status' => 'pending'],
            ];
        }
        if ($phase === 'sync_url_catalog') {
            return [
                ['key' => 'articles', 'label' => 'Bài viết / URL', 'status' => 'active', 'current' => (int) ($metrics['articles'] ?? $counters['articles'] ?? 0), 'total' => (int) ($counters['total_to_check'] ?? 0) ?: null],
                ['key' => 'seo', 'label' => 'SEO metadata', 'status' => 'active', 'current' => (int) ($counters['scores'] ?? $metrics['checked'] ?? 0), 'total' => (int) ($counters['total_to_check'] ?? 0) ?: null],
                ['key' => 'finalize', 'label' => 'Hoàn tất', 'status' => 'pending'],
            ];
        }
        if ($phase === 'finalize') {
            return [
                ['key' => 'finalize', 'label' => 'Hoàn tất dữ liệu SEO', 'status' => 'completed'],
            ];
        }

        return [];
    }

    private function resolveSiteSyncTenantId(SeoSiteSyncRun $run): int
    {
        $site = CoreSite::query()->find((int) $run->site_id);
        $ownerId = (int) ($site?->user_id ?? 0);
        if ($ownerId > 0) {
            return $ownerId;
        }

        return (int) (\App\Models\User::query()
            ->where('status', \App\Models\User::STATUS_NORMAL)
            ->where('seo_role', \App\Models\User::SEO_ROLE_MANAGER)
            ->whereNull('parent_id')
            ->value('id') ?? 0);
    }
}
