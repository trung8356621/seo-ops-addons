<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncV3Job;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncV3Receipt;
use Omnichannel\Addons\SiteSync\Services\Capability\SiteCapabilityResolver;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3BulkImporter;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3CheckpointStore;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3ContentTypeDriftRepair;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3LanguageScope;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3SecondaryGateService;
use Omnichannel\Addons\SiteSync\Support\SiteSyncWpIdentity;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Omnichannel\Addons\WordPress\Services\SitePolylangService;
use ReflectionMethod;
use Throwable;

/**
 * Site Sync V3 orchestrator — phase machine (no staged-batch payload replay,
 * no discrete provider-keyword step). Content import never writes body.
 */
final class RunSiteSyncV3Orchestrator
{
    private const CATCH_UP_MAX_ROUNDS = 3;

    /** Align with Site Sync stuck / stale reclaim (~10 minutes). */
    private const SCORE_STALE_MINUTES = 10;

    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
        private readonly WordPressSiteSyncV3Client $client,
        private readonly SiteSyncV3BulkImporter $importer,
        private readonly SiteCapabilityResolver $capabilities,
        private readonly SiteSyncV3LanguageScope $languageScope = new SiteSyncV3LanguageScope(),
        private readonly SiteSyncV3CheckpointStore $checkpointStore = new SiteSyncV3CheckpointStore(),
        private readonly SiteSyncV3SecondaryGateService $secondaryGate = new SiteSyncV3SecondaryGateService(),
    ) {}

    /**
     * @param  array{mode?: string, trigger_source?: string, triggered_by?: int|null, force_full?: bool, supersede_active?: bool, sync?: bool, meta?: array<string, mixed>}  $options
     * @return array{success: bool, message: string, run_id?: int, public_ref?: string, protocol?: int}
     */
    public function start(Site $site, array $options = []): array
    {
        if (! $this->flags->protocolV3Enabled()) {
            return [
                'success' => false,
                'message' => 'Site Sync V3 disabled (feature flag).',
            ];
        }

        $forceFull = (bool) ($options['force_full'] ?? false)
            || (string) ($options['mode'] ?? '') === SiteSyncV3Schema::MODE_FORCE_FULL
            || (string) ($options['mode'] ?? '') === SiteSyncSchema::MODE_FORCE_FULL;

        $scope = $this->languageScope->resolveForStart($site, $options);
        $languageScope = (string) ($scope['language_scope'] ?? '');
        $languageRole = (string) ($scope['language_role'] ?? SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY);

        if ($languageRole === SiteSyncV3Schema::LANGUAGE_ROLE_SECONDARY) {
            $gate = $this->secondaryGate->evaluateSecondarySync($site, $languageScope);
            if (! ($gate['allowed'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($gate['message'] ?? 'Secondary sync not allowed.'),
                    'protocol' => SiteSyncV3Schema::PROTOCOL,
                    'error_code' => (string) ($gate['code'] ?? 'secondary_blocked'),
                ];
            }
        }

        // First successful V3 baseline must be force-full — no silent delta acceptance.
        // Scoped languages use per-language checkpoints; empty scope keeps legacy global keys.
        $hasBaseline = $languageScope !== ''
            ? $this->checkpointStore->hasSuccessfulBaseline($site, $languageScope)
            : self::hasSuccessfulBaseline($site);
        if (! $forceFull && ! $hasBaseline) {
            if ($languageScope !== '') {
                // First scoped run for this language (primary Domain sync or optional secondary):
                // auto-promote to force_full instead of failing the UI with a baseline error.
                $forceFull = true;
            } else {
                return [
                    'success' => false,
                    'message' => 'Chưa có V3 force-full baseline — chạy Force Full trước khi dùng delta.',
                    'protocol' => SiteSyncV3Schema::PROTOCOL,
                    'error_code' => 'v3_baseline_required',
                ];
            }
        }

        $mode = $forceFull ? SiteSyncV3Schema::MODE_FORCE_FULL : SiteSyncV3Schema::MODE_DELTA;

        $active = SeoSiteSyncRun::query()
            ->where('site_id', (int) $site->id)
            ->whereIn('status', ['pending', 'running'])
            ->first();

        if ($active !== null) {
            if ($forceFull && ($options['supersede_active'] ?? true)) {
                $this->cancel((int) $active->id);
                $active->refresh();
                $meta = is_array($active->meta) ? $active->meta : [];
                $meta['superseded_by_force_full'] = true;
                $active->forceFill([
                    'meta' => $meta,
                    'error_message' => 'Superseded by force_full run',
                ])->save();
            } else {
                $protocol = (int) ($active->protocol_version ?? 2);
                if ($protocol === SiteSyncV3Schema::PROTOCOL) {
                    ProcessSiteSyncV3Job::dispatch(
                        (int) $active->id,
                        app(SiteSyncRunExecution::class)->readGeneration($active),
                    );
                }

                return [
                    'success' => true,
                    'message' => 'Sync đang chạy — đã kiểm tra lại queue.',
                    'run_id' => (int) $active->id,
                    'public_ref' => (string) $active->public_ref,
                    'protocol' => $protocol,
                ];
            }
        }

        $runMeta = array_merge(
            [
                'protocol' => SiteSyncV3Schema::PROTOCOL,
                'force_full' => $forceFull,
                'import_resource' => SiteSyncV3Schema::RESOURCE_CONTENT,
                'cursor' => null,
                'continuation' => 0,
                'retry_count' => 0,
                'job_number' => 0,
                SiteSyncRunExecution::META_GENERATION => app(SiteSyncRunExecution::class)->initialGeneration(),
                'capability_site_sync_v3' => $this->capabilities->isAvailable($site, SiteSyncV3Schema::CAPABILITY),
            ],
            is_array($options['meta'] ?? null) ? $options['meta'] : [],
        );
        $runMeta[SiteSyncV3Schema::META_LANGUAGE_SCOPE] = $languageScope;
        $runMeta[SiteSyncV3Schema::META_LANGUAGE_ROLE] = $languageRole;

        if (! $forceFull) {
            $importSince = $languageScope !== ''
                ? $this->checkpointStore->resolveDeltaCheckpoint($site, $languageScope)
                : self::resolvePersistentDeltaCheckpoint($site);
            if ($importSince === null || $importSince === '') {
                return [
                    'success' => false,
                    'message' => 'Chưa có V3 force-full baseline — chạy Force Full trước khi dùng delta.',
                    'protocol' => SiteSyncV3Schema::PROTOCOL,
                    'error_code' => 'v3_baseline_required',
                ];
            }
            $runMeta[SiteSyncV3Schema::META_IMPORT_SINCE] = $importSince;
        }

        $run = SeoSiteSyncRun::query()->create([
            'site_id' => (int) $site->id,
            'public_ref' => 'ssr3_'.Str::lower(Str::random(16)),
            'mode' => $mode,
            'protocol_version' => (string) SiteSyncV3Schema::PROTOCOL,
            'status' => 'pending',
            'current_step' => SiteSyncV3Schema::PHASE_DISCOVER,
            'cursor' => null,
            'run_token' => Str::uuid()->toString(),
            'resumable' => true,
            'triggered_by' => $options['triggered_by'] ?? null,
            'trigger_source' => (string) ($options['trigger_source'] ?? 'ui'),
            'counters' => [
                'fetched' => 0,
                'full_fetched' => 0,
                'content_fetched' => 0,
                'terms_fetched' => 0,
                'catch_up_fetched' => 0,
                'upserted' => 0,
                'deleted' => 0,
                'failed' => 0,
                'links' => 0,
                'keywords' => 0,
                'scores' => 0,
            ],
            'warnings' => [],
            'meta' => $runMeta,
            'started_at' => now(),
        ]);

        $sync = (bool) ($options['sync'] ?? false);
        $successMessage = $this->startSuccessMessage($site, $languageScope, $languageRole, $forceFull, $sync);
        if ($sync) {
            $this->handle((int) $run->id);

            return [
                'success' => true,
                'message' => $successMessage,
                'run_id' => (int) $run->id,
                'public_ref' => (string) $run->public_ref,
                'protocol' => SiteSyncV3Schema::PROTOCOL,
            ];
        }

        ProcessSiteSyncV3Job::dispatch(
            (int) $run->id,
            app(SiteSyncRunExecution::class)->readGeneration($run),
        );

        return [
            'success' => true,
            'message' => $successMessage,
            'run_id' => (int) $run->id,
            'public_ref' => (string) $run->public_ref,
            'protocol' => SiteSyncV3Schema::PROTOCOL,
        ];
    }

    public function handle(int $runId): void
    {
        $execution = app(SiteSyncRunExecution::class);
        $run = $execution->freshRun($runId);
        if ($run === null || $execution->isCanceled($run)) {
            return;
        }

        if ((int) ($run->protocol_version ?? 2) !== SiteSyncV3Schema::PROTOCOL) {
            RuntimeLogger::warning('site_sync.v3_skip_non_v3_run', ['run_id' => $runId]);

            return;
        }

        $phase = trim((string) ($run->current_step ?? SiteSyncV3Schema::PHASE_DISCOVER));
        $status = (string) $run->status;

        // Terminal / attention: never flip status back to running.
        if (in_array($status, ['completed', 'completed_with_warnings', 'canceled', 'cancelled', 'needs_attention', 'failed'], true)
            || $phase === SiteSyncV3Schema::PHASE_NEEDS_ATTENTION
        ) {
            return;
        }

        $run->forceFill(['status' => 'running'])->save();

        try {
            $continue = match ($phase) {
                SiteSyncV3Schema::PHASE_DISCOVER => $this->phaseDiscover($run),
                SiteSyncV3Schema::PHASE_IMPORT => $this->phaseImport($run),
                SiteSyncV3Schema::PHASE_RECONCILE_STALE => $this->phaseReconcileStale($run),
                SiteSyncV3Schema::PHASE_CATCH_UP => $this->phaseCatchUp($run),
                SiteSyncV3Schema::PHASE_VERIFY => $this->phaseVerify($run),
                SiteSyncV3Schema::PHASE_SCORE => $this->phaseScore($run),
                SiteSyncV3Schema::PHASE_COMPLETE => $this->phaseComplete($run),
                SiteSyncV3Schema::PHASE_NEEDS_ATTENTION => false,
                default => $this->failRun($run, 'unknown_phase', 'Unknown V3 phase: '.$phase),
            };

            if (! $continue) {
                return;
            }

            $run->refresh();
            if ($execution->isCanceled($run) || (string) $run->status === 'needs_attention') {
                return;
            }

            if ((string) $run->current_step === SiteSyncV3Schema::PHASE_COMPLETE
                && (string) $run->status === 'completed'
            ) {
                return;
            }

            $generation = $execution->readGeneration($run);
            if ($execution->canDispatchContinuation($runId, $generation)) {
                ProcessSiteSyncV3Job::dispatch($runId, $generation);
            }
        } catch (Throwable $e) {
            $this->failRun($run, 'exception', $e->getMessage());
        }
    }

    public function resume(int $runId): array
    {
        $run = SeoSiteSyncRun::query()->find($runId);
        if ($run === null) {
            return ['success' => false, 'message' => 'Run not found.'];
        }
        if ((int) ($run->protocol_version ?? 2) !== SiteSyncV3Schema::PROTOCOL) {
            return ['success' => false, 'message' => 'Not a V3 run.'];
        }
        if (in_array((string) $run->status, ['canceled', 'cancelled', 'completed'], true)) {
            return ['success' => false, 'message' => 'Run finished — cannot resume.'];
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $step = trim((string) ($run->current_step ?? ''));
        $status = (string) $run->status;
        $execution = app(SiteSyncRunExecution::class);
        $patch = [
            'status' => 'running',
            'resumable' => true,
            'error_message' => null,
            'finished_at' => null,
        ];

        // failRun parks current_step=needs_attention; handle() no-ops that phase.
        // Restore the pre-attention phase so dispatched jobs actually continue.
        if ($step === SiteSyncV3Schema::PHASE_NEEDS_ATTENTION
            || in_array($status, ['needs_attention', 'failed'], true)
        ) {
            $resumePhase = $this->resolveAttentionResumePhase($run);
            $patch['current_step'] = $resumePhase;
            unset($meta['error_code']);
            // Exhausted retries from the previous failure must not immediately re-fail.
            $meta['retry_count'] = 0;
            $patch['meta'] = $meta;
        }

        // Pre-fix delta runs may lack frozen import_since — resolve once from checkpoint.
        if ((string) $run->mode === SiteSyncV3Schema::MODE_DELTA) {
            $site = Site::query()->find((int) $run->site_id);
            if ($site !== null) {
                $frozen = $this->ensureImportSinceFrozen($run, $site, $meta);
                if ($frozen === null || $frozen === '') {
                    return [
                        'success' => false,
                        'message' => 'Cannot resume delta — no safe V3 delta checkpoint (force-full baseline required).',
                        'error_code' => 'v3_baseline_required',
                    ];
                }
                $meta[SiteSyncV3Schema::META_IMPORT_SINCE] = $frozen;
                $patch['meta'] = $meta;
            }
        }

        // Bump generation so stale queued ticks from the failed attempt are skipped.
        $nextGeneration = $execution->readGeneration($run) + 1;
        $meta[SiteSyncRunExecution::META_GENERATION] = $nextGeneration;
        $patch['meta'] = $meta;

        $run->forceFill($patch)->save();

        ProcessSiteSyncV3Job::dispatch($runId, $nextGeneration);
        return [
            'success' => true,
            'message' => 'Resuming site sync V3.',
            'run_id' => $runId,
            'public_ref' => (string) $run->public_ref,
            'protocol' => SiteSyncV3Schema::PROTOCOL,
            'current_step' => (string) $run->fresh()?->current_step,
        ];
    }

    public function cancel(int $runId): array
    {
        $run = SeoSiteSyncRun::query()->find($runId);
        if ($run === null) {
            return ['success' => false, 'message' => 'Run not found.'];
        }
        if (in_array((string) $run->status, ['completed', 'completed_with_warnings', 'canceled', 'cancelled'], true)) {
            return [
                'success' => true,
                'message' => 'Run already finished.',
                'run_id' => $runId,
                'public_ref' => (string) $run->public_ref,
            ];
        }

        app(SiteSyncRunExecution::class)->stampCancel($run);
        $run->forceFill([
            'status' => 'canceled',
            'resumable' => false,
            'finished_at' => now(),
            'error_message' => 'Canceled by operator',
        ])->save();

        return [
            'success' => true,
            'message' => 'Run canceled. Already reconciled data kept.',
            'run_id' => $runId,
            'public_ref' => (string) $run->public_ref,
        ];
    }

    private function phaseDiscover(SeoSiteSyncRun $run): bool
    {
        $site = Site::query()->find((int) $run->site_id);
        if ($site === null) {
            return $this->failRun($run, 'site_missing', 'Site not found.');
        }

        $languageScope = $this->runLanguageScope($run);
        $result = $this->client->discover(
            $site,
            $languageScope !== '' ? ['language' => $languageScope] : [],
        );
        if (! ($result['success'] ?? false)) {
            return $this->failRun(
                $run,
                'discover_failed',
                (string) ($result['message'] ?? 'V3 discover failed'),
            );
        }

        $discover = is_array($result['discover'] ?? null) ? $result['discover'] : [];
        $meta = is_array($run->meta) ? $run->meta : [];
        $meta['discover'] = $discover;
        if (array_key_exists('multilingual', $discover)) {
            $meta['multilingual'] = $discover['multilingual'];
        }
        if (array_key_exists('by_language', $discover)) {
            $meta['by_language'] = $discover['by_language'];
        }
        $meta['sync_generation'] = (int) ($discover['sync_generation'] ?? $discover['generation'] ?? $run->id);
        // WP authoritative snapshot clock.
        $meta['snapshot_at'] = (string) ($discover['snapshot_at'] ?? $discover['generated_at'] ?? now()->toIso8601String());
        $bounds = is_array($discover['snapshot_bounds'] ?? null) ? $discover['snapshot_bounds'] : [];
        $meta['snapshot_bounds'] = [
            'content_max_id' => (int) ($bounds['content_max_id'] ?? $discover['content_max_id'] ?? 0),
            'term_max_id' => (int) ($bounds['term_max_id'] ?? $discover['term_max_id'] ?? 0),
        ];
        $meta['snapshot_content_max_id'] = (int) $meta['snapshot_bounds']['content_max_id'];
        $meta['snapshot_term_max_id'] = (int) $meta['snapshot_bounds']['term_max_id'];
        $byType = is_array($discover['by_content_type'] ?? null) ? $discover['by_content_type'] : [];
        // User-facing progress denom = language-scoped CONTENT only.
        // discover.total includes unscoped terms and must not inflate the Domain Overview bar.
        $contentExpected = $this->contentExpectedFromDiscover($discover, $byType);
        $termsExpected = (int) ($discover['resources']['terms']['total'] ?? max(0, (int) ($discover['total'] ?? 0) - $contentExpected));
        $meta['initial_expected_total'] = $contentExpected;
        $meta['initial_expected_content_total'] = $contentExpected;
        $meta['initial_expected_terms_total'] = $termsExpected;
        $meta['initial_expected_records_total'] = (int) ($discover['total'] ?? ($contentExpected + $termsExpected));
        $meta['initial_expected_by_type'] = $byType;
        $meta['site_revision'] = isset($discover['site_revision']) ? (string) $discover['site_revision'] : null;
        $meta['import_resource'] = SiteSyncV3Schema::RESOURCE_CONTENT;
        $meta['cursor'] = null;
        $meta['continuation'] = ((int) ($meta['continuation'] ?? 0)) + 1;

        // Freeze delta since once per run (never recompute to discover snapshot_at).
        if ((string) $run->mode === SiteSyncV3Schema::MODE_DELTA) {
            $importSince = $this->ensureImportSinceFrozen($run, $site, $meta);
            if ($importSince === null || $importSince === '') {
                return $this->failRun(
                    $run,
                    'v3_baseline_required',
                    'Delta import requires a persisted V3 checkpoint (complete a force-full baseline first).',
                );
            }
            $meta[SiteSyncV3Schema::META_IMPORT_SINCE] = $importSince;
        }

        $run->forceFill([
            'meta' => $meta,
            'current_step' => SiteSyncV3Schema::PHASE_IMPORT,
            'status' => 'running',
        ])->save();

        return true;
    }

    private function phaseImport(SeoSiteSyncRun $run): bool
    {
        $site = Site::query()->find((int) $run->site_id);
        if ($site === null) {
            return $this->failRun($run, 'site_missing', 'Site not found.');
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $resource = (string) ($meta['import_resource'] ?? SiteSyncV3Schema::RESOURCE_CONTENT);
        $cursor = is_array($meta['cursor'] ?? null) ? $meta['cursor'] : null;
        $mode = (string) $run->mode;
        $recordsMode = $this->wpRecordsMode($mode);
        $jobNumber = ((int) ($meta['job_number'] ?? 0)) + 1;
        $generation = (int) ($meta['sync_generation'] ?? $run->id);

        $body = [
            'schema' => SiteSyncV3Schema::VERSION,
            'resource' => $resource,
            'mode' => $recordsMode,
            'limit' => SiteSyncV3Schema::RECORDS_PER_JOB,
            'cursor' => $cursor,
            'sync_generation' => $generation,
            // Keyset only — never send offset.
        ];
        $languageScope = $this->runLanguageScope($run);
        if ($languageScope !== '') {
            $body['language'] = $languageScope;
        }
        if ($recordsMode === 'full') {
            $body['snapshot_at'] = (string) ($meta['snapshot_at'] ?? '');
            $bounds = is_array($meta['snapshot_bounds'] ?? null) ? $meta['snapshot_bounds'] : [
                'content_max_id' => (int) ($meta['snapshot_content_max_id'] ?? 0),
                'term_max_id' => (int) ($meta['snapshot_term_max_id'] ?? 0),
            ];
            $body['snapshot_bounds'] = [
                'content_max_id' => (int) ($bounds['content_max_id'] ?? 0),
                'term_max_id' => (int) ($bounds['term_max_id'] ?? 0),
            ];
        } else {
            $importSince = $this->ensureImportSinceFrozen($run, $site, $meta);
            if ($importSince === null || $importSince === '') {
                return $this->failRun(
                    $run,
                    'delta_since_missing',
                    'since is required for delta mode (no frozen import_since / checkpoint).',
                );
            }
            $meta[SiteSyncV3Schema::META_IMPORT_SINCE] = $importSince;
            $body['since'] = $importSince;
        }

        $started = now();
        $tickStarted = hrtime(true);
        $fetched = $this->client->records($site, $body);
        if (! ($fetched['success'] ?? false)) {
            $retry = (int) ($meta['retry_count'] ?? 0) + 1;
            $meta['retry_count'] = $retry;
            $run->forceFill(['meta' => $meta])->save();
            if ($retry >= 3) {
                return $this->failRun($run, 'records_failed', (string) ($fetched['message'] ?? 'records failed'));
            }

            return true; // retry continuation
        }

        $records = is_array($fetched['records'] ?? null) ? $fetched['records'] : [];
        $items = is_array($records['items'] ?? null)
            ? $records['items']
            : (is_array($records['records'] ?? null) ? $records['records'] : []);
        $items = array_values(array_filter($items, static fn (mixed $row): bool => is_array($row)));

        $dbStarted = hrtime(true);
        $counts = $resource === SiteSyncV3Schema::RESOURCE_TERMS
            ? $this->importer->importTermsChunk($site, $run, $items)
            : $this->importer->importContentChunk($site, $run, $items);
        $dbMs = (int) ((hrtime(true) - $dbStarted) / 1_000_000);
        $totalMs = (int) ((hrtime(true) - $tickStarted) / 1_000_000);
        $timings = is_array($fetched['timings'] ?? null) ? $fetched['timings'] : [];

        $cursorAfter = is_array($records['cursor'] ?? null)
            ? $records['cursor']
            : (is_array($records['next_cursor'] ?? null) ? $records['next_cursor'] : null);
        $hasMore = (bool) ($records['has_more'] ?? false);

        if ($hasMore && $this->cursorsEqual($cursor, $cursorAfter)) {
            return $this->failRun(
                $run,
                'sync_cursor_not_advancing',
                'V3 records cursor did not advance while has_more=true.',
            );
        }

        SeoSiteSyncV3Receipt::query()->create([
            'run_id' => (int) $run->id,
            'site_id' => (int) $site->id,
            'resource' => $resource,
            'processing_job_number' => $jobNumber,
            'cursor_before' => $cursor,
            'cursor_after' => $cursorAfter,
            'item_count' => count($items),
            'upsert_count' => (int) ($counts['upsert_count'] ?? 0),
            'delete_count' => (int) ($counts['delete_count'] ?? 0),
            'checksum' => isset($records['checksum']) ? (string) $records['checksum'] : null,
            'wp_request_ms' => (int) ($timings['wp_request_ms'] ?? 0),
            'decode_ms' => (int) ($timings['decode_ms'] ?? 0),
            'db_ms' => $dbMs,
            'total_ms' => $totalMs,
            'query_count' => 0,
            'status' => 'ok',
            'started_at' => $started,
            'finished_at' => now(),
        ]);

        $counters = is_array($run->counters) ? $run->counters : [];
        $itemCount = count($items);
        $counters['fetched'] = (int) ($counters['fetched'] ?? 0) + $itemCount;
        $counters['full_fetched'] = (int) ($counters['full_fetched'] ?? 0) + $itemCount;
        if ($resource === SiteSyncV3Schema::RESOURCE_CONTENT) {
            $counters['content_fetched'] = (int) ($counters['content_fetched'] ?? 0) + $itemCount;
        } else {
            $counters['terms_fetched'] = (int) ($counters['terms_fetched'] ?? 0) + $itemCount;
        }
        $counters['upserted'] = (int) ($counters['upserted'] ?? 0) + (int) ($counts['upsert_count'] ?? 0);
        $counters['deleted'] = (int) ($counters['deleted'] ?? 0) + (int) ($counts['delete_count'] ?? 0);
        $counters['failed'] = (int) ($counters['failed'] ?? 0) + (int) ($counts['failed'] ?? 0);
        $counters['links'] = (int) ($counters['links'] ?? 0) + (int) ($counts['links'] ?? 0);
        $counters['keywords'] = (int) ($counters['keywords'] ?? 0) + (int) ($counts['keywords'] ?? 0);
        $counters['scores'] = (int) ($counters['scores'] ?? 0) + (int) ($counts['scores'] ?? 0);

        $meta['job_number'] = $jobNumber;
        $meta['retry_count'] = 0;
        $meta['continuation'] = ((int) ($meta['continuation'] ?? 0)) + 1;
        $meta['cursor'] = $cursorAfter;

        if ($hasMore && $cursorAfter !== null) {
            $run->forceFill([
                'meta' => $meta,
                'counters' => $counters,
                'current_step' => SiteSyncV3Schema::PHASE_IMPORT,
                'status' => 'running',
            ])->save();

            return true;
        }

        // Advance resource: content → terms → reconcile_stale
        if ($resource === SiteSyncV3Schema::RESOURCE_CONTENT) {
            $meta['import_resource'] = SiteSyncV3Schema::RESOURCE_TERMS;
            $meta['cursor'] = null;
            $run->forceFill([
                'meta' => $meta,
                'counters' => $counters,
                'current_step' => SiteSyncV3Schema::PHASE_IMPORT,
                'status' => 'running',
            ])->save();

            return true;
        }

        $meta['import_resource'] = SiteSyncV3Schema::RESOURCE_TERMS;
        $meta['cursor'] = null;
        $run->forceFill([
            'meta' => $meta,
            'counters' => $counters,
            'current_step' => SiteSyncV3Schema::PHASE_RECONCILE_STALE,
            'status' => 'running',
        ])->save();

        return true;
    }

    private function phaseReconcileStale(SeoSiteSyncRun $run): bool
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $forceFull = (string) $run->mode === SiteSyncV3Schema::MODE_FORCE_FULL
            || (bool) ($meta['force_full'] ?? false);

        if (! $forceFull) {
            $meta['continuation'] = ((int) ($meta['continuation'] ?? 0)) + 1;
            $meta['catch_up_round'] = (int) ($meta['catch_up_round'] ?? 0);
            $run->forceFill([
                'meta' => $meta,
                'current_step' => SiteSyncV3Schema::PHASE_CATCH_UP,
                'status' => 'running',
            ])->save();

            return true;
        }

        $generation = (int) ($meta['sync_generation'] ?? $run->id);
        $staleMarked = 0;
        $languageScope = $this->runLanguageScope($run);

        if (Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')
            && Schema::connection('omi_seo_ai')->hasColumn('wordpress_article_links', 'last_seen_sync_generation')
        ) {
            $query = WordpressArticleLink::query()
                ->where('site_id', (int) $run->site_id)
                ->whereNotNull('last_seen_sync_generation')
                ->where('last_seen_sync_generation', '!=', $generation)
                ->whereNotNull('wp_post_id')
                ->where('wp_post_id', '>', 0);

            // Soft-delete stale WP-backed *content* (not taxonomy terms) not seen in this FULL generation.
            $staleLinks = (clone $query)->get(['id', 'article_id', 'wp_post_id']);
            if (Schema::connection('omi_seo_ai')->hasColumn('wordpress_article_links', 'reconcile_status')) {
                (clone $query)->update(['reconcile_status' => 'stale']);
            }
            foreach ($staleLinks as $link) {
                $articleId = (int) ($link->article_id ?? 0);
                if ($articleId <= 0) {
                    continue;
                }
                $article = SeoArticle::query()->find($articleId);
                if ($article === null || $article->trashed()) {
                    continue;
                }
                $isTerm = $article->articleMetas()
                    ->where('meta_key', ArticleContentClassification::META_WP_IS_TERM)
                    ->where('meta_value', '1')
                    ->exists();
                if ($isTerm) {
                    continue;
                }
                // Language-scoped force-full: never soft-delete other languages.
                if ($languageScope !== ''
                    && trim((string) ($article->language ?? '')) !== $languageScope
                ) {
                    continue;
                }
                $article->delete();
                $staleMarked++;
            }
        }

        $meta['reconcile_stale'] = [
            'generation' => $generation,
            'stale_marked' => $staleMarked,
            'at' => now()->toIso8601String(),
        ];
        $meta['continuation'] = ((int) ($meta['continuation'] ?? 0)) + 1;
        $meta['catch_up_round'] = (int) ($meta['catch_up_round'] ?? 0);
        if (! isset($meta['catch_up_boundary_at'])) {
            $meta['catch_up_boundary_at'] = (string) ($meta['snapshot_at'] ?? now()->toIso8601String());
        }

        $run->forceFill([
            'meta' => $meta,
            'current_step' => SiteSyncV3Schema::PHASE_CATCH_UP,
            'status' => 'running',
        ])->save();

        return true;
    }

    private function phaseCatchUp(SeoSiteSyncRun $run): bool
    {
        $site = Site::query()->find((int) $run->site_id);
        if ($site === null) {
            return $this->failRun($run, 'site_missing', 'Site not found.');
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $round = (int) ($meta['catch_up_round'] ?? 0);
        // Freeze round lower bound: never advance `since` mid-round to "now".
        // Delta import_since is the authoritative previous checkpoint; never use
        // current discover snapshot_at as the first catch-up lower bound.
        $since = trim((string) ($meta['catch_up_since']
            ?? $meta[SiteSyncV3Schema::META_IMPORT_SINCE]
            ?? $meta['catch_up_boundary_at']
            ?? ''));
        if ($since === '' && ((string) $run->mode === SiteSyncV3Schema::MODE_FORCE_FULL || (bool) ($meta['force_full'] ?? false))) {
            $since = trim((string) ($meta['snapshot_at'] ?? ''));
        }
        if ($since === '') {
            return $this->failRun(
                $run,
                'delta_since_missing',
                'Catch-up requires a frozen since (import_since / catch_up_since).',
            );
        }
        if (! isset($meta['catch_up_since'])) {
            $meta['catch_up_since'] = $since;
        }
        $generation = (int) ($meta['sync_generation'] ?? $run->id);
        $budget = SiteSyncV3Schema::RECORDS_PER_JOB * 2;
        $jobNumber = (int) ($meta['job_number'] ?? 0);
        $counters = is_array($run->counters) ? $run->counters : [];
        $languageScope = $this->runLanguageScope($run);

        $totalFetched = 0;
        $deferred = false;

        foreach ([SiteSyncV3Schema::RESOURCE_CONTENT, SiteSyncV3Schema::RESOURCE_TERMS] as $resource) {
            $cursorKey = 'catch_up_cursor_'.$resource;
            $cursor = is_array($meta[$cursorKey] ?? null) ? $meta[$cursorKey] : null;

            while ($budget > 0) {
                $limit = min(SiteSyncV3Schema::RECORDS_PER_JOB, $budget);
                $body = [
                    'schema' => SiteSyncV3Schema::VERSION,
                    'resource' => $resource,
                    'mode' => SiteSyncV3Schema::MODE_DELTA,
                    'limit' => $limit,
                    'cursor' => $cursor,
                    'since' => $since,
                    'sync_generation' => $generation,
                    // Catch-up may need frozen max to detect new terms above snapshot.
                    'snapshot_bounds' => [
                        'content_max_id' => (int) ($meta['snapshot_content_max_id']
                            ?? (is_array($meta['snapshot_bounds'] ?? null) ? ($meta['snapshot_bounds']['content_max_id'] ?? 0) : 0)),
                        'term_max_id' => (int) ($meta['snapshot_term_max_id']
                            ?? (is_array($meta['snapshot_bounds'] ?? null) ? ($meta['snapshot_bounds']['term_max_id'] ?? 0) : 0)),
                    ],
                ];
                if ($languageScope !== '') {
                    $body['language'] = $languageScope;
                }

                $started = now();
                $tickStarted = hrtime(true);
                $fetched = $this->client->records($site, $body);
                if (! ($fetched['success'] ?? false)) {
                    $retry = (int) ($meta['retry_count'] ?? 0) + 1;
                    $meta['retry_count'] = $retry;
                    $run->forceFill(['meta' => $meta])->save();
                    if ($retry >= 3) {
                        return $this->failRun(
                            $run,
                            'catch_up_records_failed',
                            (string) ($fetched['message'] ?? 'catch-up records failed'),
                        );
                    }

                    return true;
                }

                $records = is_array($fetched['records'] ?? null) ? $fetched['records'] : [];
                $items = is_array($records['items'] ?? null)
                    ? $records['items']
                    : (is_array($records['records'] ?? null) ? $records['records'] : []);
                $items = array_values(array_filter($items, static fn (mixed $row): bool => is_array($row)));

                $dbStarted = hrtime(true);
                $counts = $resource === SiteSyncV3Schema::RESOURCE_TERMS
                    ? $this->importer->importTermsChunk($site, $run, $items)
                    : $this->importer->importContentChunk($site, $run, $items);
                $dbMs = (int) ((hrtime(true) - $dbStarted) / 1_000_000);
                $totalMs = (int) ((hrtime(true) - $tickStarted) / 1_000_000);
                $timings = is_array($fetched['timings'] ?? null) ? $fetched['timings'] : [];

                $cursorAfter = is_array($records['cursor'] ?? null)
                    ? $records['cursor']
                    : (is_array($records['next_cursor'] ?? null) ? $records['next_cursor'] : null);
                $hasMore = (bool) ($records['has_more'] ?? false);

                if ($hasMore && $this->cursorsEqual($cursor, $cursorAfter)) {
                    return $this->failRun(
                        $run,
                        'sync_cursor_not_advancing',
                        'V3 catch-up cursor did not advance while has_more=true.',
                    );
                }

                $jobNumber++;
                SeoSiteSyncV3Receipt::query()->create([
                    'run_id' => (int) $run->id,
                    'site_id' => (int) $site->id,
                    'resource' => 'catch_up_'.$resource,
                    'processing_job_number' => $jobNumber,
                    'cursor_before' => $cursor,
                    'cursor_after' => $cursorAfter,
                    'item_count' => count($items),
                    'upsert_count' => (int) ($counts['upsert_count'] ?? 0),
                    'delete_count' => (int) ($counts['delete_count'] ?? 0),
                    'checksum' => isset($records['checksum']) ? (string) $records['checksum'] : null,
                    'wp_request_ms' => (int) ($timings['wp_request_ms'] ?? 0),
                    'decode_ms' => (int) ($timings['decode_ms'] ?? 0),
                    'db_ms' => $dbMs,
                    'total_ms' => $totalMs,
                    'query_count' => 0,
                    'status' => 'ok',
                    'started_at' => $started,
                    'finished_at' => now(),
                ]);

                $itemCount = count($items);
                $totalFetched += $itemCount;
                if ($itemCount > 0) {
                    $budget -= $itemCount;
                }

                // Catch-up events are diagnostics — do NOT inflate FULL progress numerator.
                $counters['catch_up_fetched'] = (int) ($counters['catch_up_fetched'] ?? 0) + $itemCount;
                $counters['upserted'] = (int) ($counters['upserted'] ?? 0) + (int) ($counts['upsert_count'] ?? 0);
                $counters['deleted'] = (int) ($counters['deleted'] ?? 0) + (int) ($counts['delete_count'] ?? 0);
                $counters['failed'] = (int) ($counters['failed'] ?? 0) + (int) ($counts['failed'] ?? 0);
                $counters['links'] = (int) ($counters['links'] ?? 0) + (int) ($counts['links'] ?? 0);
                $counters['keywords'] = (int) ($counters['keywords'] ?? 0) + (int) ($counts['keywords'] ?? 0);
                $counters['scores'] = (int) ($counters['scores'] ?? 0) + (int) ($counts['scores'] ?? 0);

                $cursor = $cursorAfter;
                $meta[$cursorKey] = $cursorAfter;
                $meta['retry_count'] = 0;

                if (! $hasMore || $cursorAfter === null) {
                    $meta[$cursorKey] = null;
                    break;
                }

                if ($budget <= 0) {
                    $deferred = true;
                    break;
                }
            }

            if ($deferred) {
                break;
            }
        }

        $meta['job_number'] = $jobNumber;
        // Round end boundary only — next round's since advances from catch_up_since → this stamp.
        $meta['catch_up_boundary_at'] = now()->toIso8601String();
        $meta['continuation'] = ((int) ($meta['continuation'] ?? 0)) + 1;

        if ($deferred) {
            $run->forceFill([
                'meta' => $meta,
                'counters' => $counters,
                'current_step' => SiteSyncV3Schema::PHASE_CATCH_UP,
                'status' => 'running',
            ])->save();

            return true;
        }

        // Clear per-resource catch-up cursors for next round.
        $meta['catch_up_cursor_'.SiteSyncV3Schema::RESOURCE_CONTENT] = null;
        $meta['catch_up_cursor_'.SiteSyncV3Schema::RESOURCE_TERMS] = null;

        if ($totalFetched === 0) {
            $meta['catch_up_stable'] = true;
            $run->forceFill([
                'meta' => $meta,
                'counters' => $counters,
                'current_step' => SiteSyncV3Schema::PHASE_VERIFY,
                'status' => 'running',
            ])->save();

            return true;
        }

        $nextRound = $round + 1;
        if ($nextRound >= self::CATCH_UP_MAX_ROUNDS) {
            $meta['catch_up_round'] = $nextRound;
            $run->forceFill(['meta' => $meta, 'counters' => $counters])->save();

            return $this->failRun(
                $run,
                'catch_up_max_rounds',
                'Catch-up still changing after '.self::CATCH_UP_MAX_ROUNDS.' rounds.',
            );
        }

        // Advance frozen since for the next catch-up round only after this round exhausted.
        $meta['catch_up_since'] = (string) $meta['catch_up_boundary_at'];
        $meta['catch_up_round'] = $nextRound;
        $run->forceFill([
            'meta' => $meta,
            'counters' => $counters,
            'current_step' => SiteSyncV3Schema::PHASE_CATCH_UP,
            'status' => 'running',
        ])->save();

        return true;
    }

    private function phaseVerify(SeoSiteSyncRun $run): bool
    {
        $site = Site::query()->find((int) $run->site_id);
        if ($site === null) {
            return $this->failRun($run, 'site_missing', 'Site not found.');
        }

        // Fresh discover before verify (CATCH-UP → FRESH DISCOVER → VERIFY).
        $languageScope = $this->runLanguageScope($run);
        $result = $this->client->discover(
            $site,
            $languageScope !== '' ? ['language' => $languageScope] : [],
        );
        if (! ($result['success'] ?? false)) {
            return $this->failRun(
                $run,
                'verify_discover_failed',
                (string) ($result['message'] ?? 'V3 verify discover failed'),
            );
        }

        $discover = is_array($result['discover'] ?? null) ? $result['discover'] : [];
        $expectedByType = is_array($discover['by_content_type'] ?? null) ? $discover['by_content_type'] : [];
        $contentExpected = $this->contentExpectedFromDiscover($discover, $expectedByType);
        // Keep discover.total available for diagnostics; verify membership uses content only.
        $expectedTotal = $contentExpected;

        $wpInventory = $this->enumerateWpContentInventory($site, $discover, $languageScope);
        if ($wpInventory === null) {
            return $this->failRun(
                $run,
                'verify_inventory_failed',
                'Unable to enumerate WordPress content IDs for membership verify.',
            );
        }

        /** @var array<int, string> $wpIdsByType */
        $wpIdsByType = $wpInventory['by_id'];
        $wpIdSet = array_fill_keys(array_keys($wpIdsByType), true);

        // Soft-delete local WP-backed non-term rows absent from fresh WP inventory.
        $extraRemoved = $this->softDeleteExtraLocalContent($site, $wpIdSet, $languageScope);

        $localByType = $this->countWpBackedByContentType((int) $site->id, $languageScope);
        $localTotal = array_sum($localByType);
        $localIdsByType = $this->localWpContentIdsByType((int) $site->id, $languageScope);

        $missingIds = [];
        $extraIds = [];
        $typeMismatch = [];

        foreach ($wpIdsByType as $wpId => $wpType) {
            if (! isset($localIdsByType[$wpId])) {
                $missingIds[] = $wpId;
                continue;
            }
            $localType = $localIdsByType[$wpId];
            if (in_array($wpType, ['post', 'page', 'product'], true)
                && in_array($localType, ['post', 'page', 'product'], true)
                && $wpType !== $localType
            ) {
                $typeMismatch[] = [
                    'wp_id' => $wpId,
                    'wp_type' => $wpType,
                    'local_type' => $localType,
                ];
            }
        }
        foreach ($localIdsByType as $wpId => $_type) {
            if (! isset($wpIdSet[$wpId])) {
                $extraIds[] = $wpId;
            }
        }

        // Content subtype drift (post↔page↔product) is repaired from WP inventory.
        // Content id N and term id N are independent — never treat as collision.
        $typeDrift = ['repaired' => [], 'unresolved' => []];
        if ($typeMismatch !== []) {
            $typeDrift = app(SiteSyncV3ContentTypeDriftRepair::class)
                ->repairFromInventory((int) $site->id, $typeMismatch);
            // Re-read local map after repair so membership check uses fresh subtypes.
            $localIdsByType = $this->localWpContentIdsByType((int) $site->id, $languageScope);
            $localByType = $this->countWpBackedByContentType((int) $site->id, $languageScope);
            $localTotal = array_sum($localByType);
            $typeMismatch = [];
            foreach ($wpIdsByType as $wpId => $wpType) {
                if (! isset($localIdsByType[$wpId])) {
                    continue;
                }
                $localType = $localIdsByType[$wpId];
                if (in_array($wpType, ['post', 'page', 'product'], true)
                    && in_array($localType, ['post', 'page', 'product'], true)
                    && $wpType !== $localType
                ) {
                    $typeMismatch[] = [
                        'wp_id' => $wpId,
                        'wp_type' => $wpType,
                        'local_type' => $localType,
                    ];
                }
            }
        }

        $missing = [];
        $extra = [];
        foreach (['post', 'page', 'product'] as $type) {
            $exp = (int) ($expectedByType[$type] ?? 0);
            $got = (int) ($localByType[$type] ?? 0);
            if ($got < $exp) {
                $missing[$type] = $exp - $got;
            } elseif ($got > $exp) {
                $extra[$type] = $got - $exp;
            }
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $meta['final_expected_total'] = $expectedTotal;
        $meta['final_expected_content_total'] = $contentExpected;
        $meta['final_expected_by_type'] = $expectedByType;
        $meta['final_site_revision'] = isset($discover['site_revision'])
            ? (string) $discover['site_revision']
            : null;
        $meta['final_manifest_at'] = (string) ($discover['snapshot_at'] ?? $discover['generated_at'] ?? now()->toIso8601String());
        $meta['verify'] = [
            'local_by_type' => $localByType,
            'local_total' => $localTotal,
            'content_expected' => $contentExpected,
            'wp_content_enumerated' => count($wpIdsByType),
            'missing' => $missing,
            'extra' => $extra,
            'type_mismatch' => $typeMismatch,
            'type_drift_repaired' => $typeDrift['repaired'],
            'type_drift_unresolved' => $typeDrift['unresolved'],
            'sample_missing_wp_ids' => array_slice($missingIds, 0, 20),
            'sample_extra_wp_ids' => array_slice($extraIds, 0, 20),
            'extra_removed' => $extraRemoved,
            'at' => now()->toIso8601String(),
        ];
        $meta['continuation'] = ((int) ($meta['continuation'] ?? 0)) + 1;

        // Fail only on identity membership gaps. Type drift must be repaired above;
        // count deltas remain diagnostic when membership lists are empty.
        $hasMismatch = $missingIds !== []
            || $extraIds !== []
            || $typeMismatch !== []
            || ($typeDrift['unresolved'] ?? []) !== [];
        if ($hasMismatch) {
            $run->forceFill(['meta' => $meta])->save();

            return $this->failRun(
                $run,
                'verify_count_mismatch',
                'Verify membership mismatch vs fresh WordPress inventory.',
            );
        }

        // Clear count-only noise when identity membership matched.
        $meta['verify']['missing'] = [];
        $meta['verify']['extra'] = [];

        $run->forceFill([
            'meta' => $meta,
            'current_step' => SiteSyncV3Schema::PHASE_SCORE,
            'status' => 'running',
        ])->save();

        return true;
    }

    /**
     * WP-backed Workspace SEO scoring after verify — async poll with progress watchdog.
     * Does not queue local-only inventory; does not false-complete after N polls.
     */
    private function phaseScore(SeoSiteSyncRun $run): bool
    {
        $site = Site::query()->find((int) $run->site_id);
        if ($site === null) {
            return $this->failRun($run, 'site_missing', 'Site not found.');
        }

        $scoring = app(\Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService::class);
        $meta = is_array($run->meta) ? $run->meta : [];
        $counters = is_array($run->counters) ? $run->counters : [];
        $execution = app(SiteSyncRunExecution::class);

        if (empty($meta['scoring_dispatched_at'])) {
            $queueContext = [
                'run_id' => (int) $run->id,
                'operation_id' => (string) ($meta['operation_id'] ?? $run->public_ref),
            ];
            $languageScope = $this->runLanguageScope($run);
            if ($languageScope !== '') {
                $queueContext['language'] = $languageScope;
            }
            $result = $scoring->queueMissingOrStaleWpBackedForSite((int) $site->id, $queueContext);
            $meta['scoring_dispatched_at'] = now()->toIso8601String();
            $meta['scoring_queued'] = (int) ($result['queued'] ?? 0);
            $meta['scoring_stale_queued'] = (int) ($result['stale_queued'] ?? 0);
            $meta['scoring_missing_queued'] = (int) ($result['missing_queued'] ?? 0);
            $meta['scoring_polls'] = 0;
            $meta['scoring_last_progress_at'] = now()->toIso8601String();
            $meta['last_progress_at'] = now()->toIso8601String();
            $meta['scoring_progress_signature'] = null;
            $counters['workspace_scores_queued'] = (int) ($result['queued'] ?? 0);
            $counters['scoring_stale_queued'] = (int) ($result['stale_queued'] ?? 0);
            $counters['scoring_missing_queued'] = (int) ($result['missing_queued'] ?? 0);
        }

        $languageScope = $this->runLanguageScope($run);
        $langArg = $languageScope !== '' ? $languageScope : null;
        $progressMethod = new ReflectionMethod($scoring, 'domainWpBackedProgress');
        $progress = $progressMethod->getNumberOfParameters() >= 2
            ? $scoring->domainWpBackedProgress((int) $site->id, $langArg)
            : $scoring->domainWpBackedProgress((int) $site->id);
        $total = (int) ($progress['total'] ?? 0);
        $completed = (int) ($progress['completed'] ?? 0);
        $pending = (int) ($progress['pending'] ?? 0);
        $processing = (int) ($progress['processing'] ?? 0);
        $failed = (int) ($progress['failed'] ?? 0);
        // remaining includes failed + never-queued; unresolved = silent gaps only.
        $remaining = (int) ($progress['remaining'] ?? 0);
        $unresolved = max(0, $remaining - $failed);
        $inFlight = $pending + $processing;
        $polls = (int) ($meta['scoring_polls'] ?? 0) + 1;

        $signature = implode(':', [$total, $completed, $pending, $processing, $failed, $unresolved]);
        $previousSignature = isset($meta['scoring_progress_signature'])
            ? (string) $meta['scoring_progress_signature']
            : null;
        $meaningfulProgress = $previousSignature === null || $previousSignature !== $signature;
        if ($meaningfulProgress) {
            $meta['scoring_last_progress_at'] = now()->toIso8601String();
            $meta['last_progress_at'] = now()->toIso8601String();
            $meta['scoring_progress_signature'] = $signature;
        }

        $meta['scoring_polls'] = $polls;
        $meta['scoring'] = [
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'processing' => $processing,
            'failed' => $failed,
            'unresolved' => $unresolved,
            'at' => now()->toIso8601String(),
        ];
        $counters['workspace_scores_generated'] = $completed;
        $counters['scoring_failed'] = $failed;
        $counters['scoring_pending'] = $pending;
        $counters['scoring_processing'] = $processing;
        $counters['scoring_total'] = $total;

        // All WP-backed eligible rows reached a terminal state (completed or failed).
        if ($inFlight === 0 && $unresolved === 0) {
            $meta['scoring_deferred'] = false;
            $meta['scoring_failed'] = $failed;
            $warnings = is_array($run->warnings) ? $run->warnings : [];
            if ($failed > 0) {
                $warnings[] = "{$failed} bài chấm SEO thất bại — sync hoàn tất với cảnh báo.";
            }
            $run->forceFill([
                'meta' => $meta,
                'counters' => $counters,
                'warnings' => array_values(array_unique($warnings)),
                'current_step' => SiteSyncV3Schema::PHASE_COMPLETE,
                'status' => 'running',
            ])->save();

            return true;
        }

        // Progress-aware watchdog — never false-complete after a fixed poll count.
        $lastProgressAt = trim((string) ($meta['scoring_last_progress_at'] ?? ''));
        if ($lastProgressAt !== '') {
            try {
                $last = \Illuminate\Support\Carbon::parse($lastProgressAt);
                if ($last->lessThanOrEqualTo(now()->subMinutes(self::SCORE_STALE_MINUTES))) {
                    $run->forceFill(['meta' => $meta, 'counters' => $counters])->save();

                    return $this->failRun(
                        $run,
                        'scoring_stale',
                        sprintf(
                            'Chấm SEO không tiến triển trong %d phút (%d/%d hoàn tất, %d chờ, %d xử lý, %d thất bại, %d chưa xếp hàng).',
                            self::SCORE_STALE_MINUTES,
                            $completed,
                            $total,
                            $pending,
                            $processing,
                            $failed,
                            $unresolved,
                        ),
                    );
                }
            } catch (Throwable) {
                // Keep polling if timestamp unparsable.
            }
        }

        $meta['scoring_deferred'] = true;
        $waitingWorker = $pending > 0 && $processing === 0;
        $deferSeconds = $waitingWorker ? 30 : 15;
        $run->forceFill([
            'meta' => $meta,
            'counters' => $counters,
            'current_step' => SiteSyncV3Schema::PHASE_SCORE,
            'status' => 'running',
        ])->save();

        $generation = $execution->readGeneration($run);
        if ($execution->canDispatchContinuation((int) $run->id, $generation)) {
            ProcessSiteSyncV3Job::dispatch((int) $run->id, $generation)
                ->delay(now()->addSeconds($deferSeconds));
        }

        // false → handle() must not immediately re-dispatch (we already delayed).
        return false;
    }

    private function phaseComplete(SeoSiteSyncRun $run): bool
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $forceFull = (string) $run->mode === SiteSyncV3Schema::MODE_FORCE_FULL
            || (bool) ($meta['force_full'] ?? false);
        $verify = is_array($meta['verify'] ?? null) ? $meta['verify'] : [];
        $cleanVerify = ($verify['sample_missing_wp_ids'] ?? null) === []
            && ($verify['sample_extra_wp_ids'] ?? null) === []
            && ($verify['type_mismatch'] ?? null) === []
            && (int) ($verify['wp_content_enumerated'] ?? 0) > 0;
        $languageScope = $this->runLanguageScope($run);
        $isPrimary = $this->languageScope->isPrimaryRun($run);

        if ($cleanVerify) {
            $site = Site::query()->find((int) $run->site_id);
            if ($site !== null) {
                $patch = [];
                if ($forceFull) {
                    $generation = (int) ($meta['sync_generation'] ?? $run->id);
                    $completedAt = now()->toIso8601String();
                    $patch['baseline_completed_at'] = $completedAt;
                    $patch['baseline_generation'] = $generation;
                    $meta['v3_baseline_completed_at'] = $completedAt;
                    $meta['v3_baseline_generation'] = $generation;
                }

                // Advance persistent delta checkpoint only after catch-up + verify succeed.
                // Prefer catch_up_boundary_at (stamped after the stable empty delta round);
                // WP DELTA_OVERLAP_SECONDS covers the query/finish race. Never use finished_at alone.
                $nextCheckpoint = $this->resolveTerminalDeltaCheckpoint($meta);
                if ($nextCheckpoint !== null && $nextCheckpoint !== '') {
                    $patch['delta_checkpoint_at'] = $nextCheckpoint;
                    $meta['v3_delta_checkpoint_at'] = $nextCheckpoint;
                    $meta['v3_delta_checkpoint_advanced'] = true;
                }

                $siteRevision = trim((string) (
                    $meta['final_site_revision']
                    ?? $meta['site_revision']
                    ?? ''
                ));
                if ($siteRevision !== '') {
                    $patch['site_revision'] = $siteRevision;
                }

                if ($patch !== []) {
                    // Language-safe store; primary/unscoped also mirrors META_BASELINE_COMPLETED_AT,
                    // META_BASELINE_GENERATION, and META_DELTA_CHECKPOINT_AT for legacy readers.
                    $this->checkpointStore->put(
                        $site,
                        $languageScope,
                        $patch,
                        isPrimary: $isPrimary,
                    );
                }
            }
        }

        $scoringFailed = (int) ($meta['scoring_failed'] ?? 0);
        $terminalStatus = $scoringFailed > 0 ? 'completed_with_warnings' : 'completed';
        $meta['scoring_deferred'] = false;

        $run->forceFill([
            'status' => $terminalStatus,
            'current_step' => SiteSyncV3Schema::PHASE_COMPLETE,
            'finished_at' => now(),
            'resumable' => false,
            'error_message' => null,
            'meta' => $meta,
        ])->save();

        return false;
    }

    public static function hasSuccessfulBaseline(Site $site, string $languageScope = ''): bool
    {
        if ($languageScope !== '') {
            return (new SiteSyncV3CheckpointStore())->hasSuccessfulBaseline($site, $languageScope);
        }

        $at = trim((string) ($site->getMeta(SiteSyncV3Schema::META_BASELINE_COMPLETED_AT) ?? ''));

        return $at !== '';
    }

    public static function baselineGeneration(Site $site): int
    {
        return (int) ($site->getMeta(SiteSyncV3Schema::META_BASELINE_GENERATION) ?? 0);
    }

    /**
     * Resolve the persistent lower bound for the next V3 delta import.
     * Never returns `now()`. Empty means baseline_required.
     * Non-empty $languageScope uses per-language checkpoints; empty keeps legacy global keys.
     */
    public static function resolvePersistentDeltaCheckpoint(Site $site, string $languageScope = ''): ?string
    {
        if ($languageScope !== '') {
            return (new SiteSyncV3CheckpointStore())->resolveDeltaCheckpoint($site, $languageScope);
        }

        $explicit = trim((string) ($site->getMeta(SiteSyncV3Schema::META_DELTA_CHECKPOINT_AT) ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $fromRun = self::checkpointFromLatestSuccessfulV3Run((int) $site->id);
        if ($fromRun !== null && $fromRun !== '') {
            return $fromRun;
        }

        $baseline = trim((string) ($site->getMeta(SiteSyncV3Schema::META_BASELINE_COMPLETED_AT) ?? ''));
        if ($baseline !== '') {
            return $baseline;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function ensureImportSinceFrozen(SeoSiteSyncRun $run, Site $site, array $meta): ?string
    {
        $existing = trim((string) ($meta[SiteSyncV3Schema::META_IMPORT_SINCE] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        return self::resolvePersistentDeltaCheckpoint($site, $this->runLanguageScope($run));
    }

    /**
     * Safe terminal checkpoint after verified sync.
     * Prefer catch_up_boundary_at (after stable empty round); WP applies overlap.
     *
     * @param  array<string, mixed>  $meta
     */
    private function resolveTerminalDeltaCheckpoint(array $meta): ?string
    {
        // Prefer catch_up_boundary_at (stamped after stable empty delta round).
        // Never use finished_at / final_manifest_at — those are not WP delta-query bounds.
        foreach ([
            'catch_up_boundary_at',
            'catch_up_since',
            SiteSyncV3Schema::META_IMPORT_SINCE,
            'snapshot_at',
        ] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function checkpointFromLatestSuccessfulV3Run(int $siteId): ?string
    {
        if ($siteId <= 0) {
            return null;
        }

        $run = SeoSiteSyncRun::query()
            ->where('site_id', $siteId)
            ->where('protocol_version', (string) SiteSyncV3Schema::PROTOCOL)
            ->whereIn('status', ['completed', 'completed_with_warnings'])
            ->orderByDesc('id')
            ->first();

        if ($run === null) {
            return null;
        }

        // Only proven WP/query lower bounds. Never finished_at (Laravel wall clock after
        // the last delta query) or final_manifest_at (verify-time discover, not import since).
        $meta = is_array($run->meta) ? $run->meta : [];
        foreach ([
            'v3_delta_checkpoint_at',
            'catch_up_boundary_at',
            'catch_up_since',
            SiteSyncV3Schema::META_IMPORT_SINCE,
            'snapshot_at',
        ] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function failRun(SeoSiteSyncRun $run, string $code, string $message): bool
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $previousPhase = trim((string) ($run->current_step ?? ''));
        if ($previousPhase !== ''
            && $previousPhase !== SiteSyncV3Schema::PHASE_NEEDS_ATTENTION
            && in_array($previousPhase, SiteSyncV3Schema::PHASES, true)
        ) {
            $meta[SiteSyncV3Schema::META_ATTENTION_RESUME_PHASE] = $previousPhase;
        }
        $meta['error_code'] = $code;

        $run->forceFill([
            'status' => 'needs_attention',
            'current_step' => SiteSyncV3Schema::PHASE_NEEDS_ATTENTION,
            'error_message' => $message,
            'finished_at' => now(),
            'resumable' => true,
            'meta' => $meta,
        ])->save();

        RuntimeLogger::warning('site_sync.v3_needs_attention', [
            'run_id' => (int) $run->id,
            'site_id' => (int) $run->site_id,
            'error_code' => $code,
            'message' => $message,
            'resume_phase' => $meta[SiteSyncV3Schema::META_ATTENTION_RESUME_PHASE] ?? null,
        ]);

        return false;
    }

    /**
     * Phase to continue after operator resume from needs_attention / failed.
     * Prefers meta attention_resume_phase; falls back to progress markers for
     * runs that failed before that key existed.
     */
    private function resolveAttentionResumePhase(SeoSiteSyncRun $run): string
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $saved = trim((string) ($meta[SiteSyncV3Schema::META_ATTENTION_RESUME_PHASE] ?? ''));
        $resumablePhases = [
            SiteSyncV3Schema::PHASE_DISCOVER,
            SiteSyncV3Schema::PHASE_IMPORT,
            SiteSyncV3Schema::PHASE_RECONCILE_STALE,
            SiteSyncV3Schema::PHASE_CATCH_UP,
            SiteSyncV3Schema::PHASE_VERIFY,
            SiteSyncV3Schema::PHASE_SCORE,
            SiteSyncV3Schema::PHASE_COMPLETE,
        ];
        if (in_array($saved, $resumablePhases, true)) {
            return $saved;
        }

        if (! empty($meta['scoring_dispatched_at']) || is_array($meta['scoring'] ?? null)) {
            return SiteSyncV3Schema::PHASE_SCORE;
        }
        if (is_array($meta['verify'] ?? null)) {
            return SiteSyncV3Schema::PHASE_VERIFY;
        }
        if (! empty($meta['catch_up_stable'])) {
            return SiteSyncV3Schema::PHASE_VERIFY;
        }
        if (isset($meta['catch_up_round']) || isset($meta['catch_up_since'])) {
            return SiteSyncV3Schema::PHASE_CATCH_UP;
        }
        if (isset($meta['import_resource'])
            || isset($meta['job_number'])
            || (is_array($meta['cursor'] ?? null) && $meta['cursor'] !== [])
        ) {
            return SiteSyncV3Schema::PHASE_IMPORT;
        }
        if (isset($meta['discover']) || isset($meta['initial_expected_total'])) {
            return SiteSyncV3Schema::PHASE_DISCOVER;
        }

        return SiteSyncV3Schema::PHASE_DISCOVER;
    }

    /**
     * Map run mode to WP /records mode (full|delta).
     */
    private function wpRecordsMode(string $runMode): string
    {
        if ($runMode === SiteSyncV3Schema::MODE_FORCE_FULL
            || $runMode === SiteSyncSchema::MODE_FORCE_FULL
            || $runMode === 'full'
        ) {
            return 'full';
        }

        return SiteSyncV3Schema::MODE_DELTA;
    }

    /**
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    private function cursorsEqual(?array $a, ?array $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }

        return json_encode($a) === json_encode($b);
    }

    /**
     * @param  array<string, mixed>  $discover
     * @return array{by_id: array<int, string>}|null
     */
    private function enumerateWpContentInventory(Site $site, array $discover, string $languageScope = ''): ?array
    {
        $snapshotAt = (string) ($discover['snapshot_at'] ?? $discover['generated_at'] ?? '');
        $bounds = is_array($discover['snapshot_bounds'] ?? null) ? $discover['snapshot_bounds'] : [];
        if ($snapshotAt === '' || (int) ($bounds['content_max_id'] ?? 0) <= 0) {
            return null;
        }

        $byId = [];
        $cursor = null;
        for ($page = 0; $page < 200; $page++) {
            $body = [
                'schema' => SiteSyncV3Schema::VERSION,
                'resource' => SiteSyncV3Schema::RESOURCE_CONTENT,
                'mode' => 'full',
                'limit' => SiteSyncV3Schema::RECORDS_PER_JOB,
                'cursor' => $cursor,
                'snapshot_at' => $snapshotAt,
                'snapshot_bounds' => [
                    'content_max_id' => (int) ($bounds['content_max_id'] ?? 0),
                    'term_max_id' => (int) ($bounds['term_max_id'] ?? 0),
                ],
                'sync_generation' => 0,
            ];
            if ($languageScope !== '') {
                $body['language'] = $languageScope;
            }
            $fetched = $this->client->records($site, $body);
            if (! ($fetched['success'] ?? false)) {
                return null;
            }
            $records = is_array($fetched['records'] ?? null) ? $fetched['records'] : [];
            $items = is_array($records['items'] ?? null) ? $records['items'] : [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $wpId = (int) ($item['wp_id'] ?? $item['wordpress_id'] ?? 0);
                if ($wpId <= 0) {
                    continue;
                }
                $type = (string) ($item['content_type'] ?? $item['type'] ?? $item['wp_post_type'] ?? 'other');
                if (! in_array($type, ['post', 'page', 'product'], true)) {
                    $type = 'other';
                }
                $byId[$wpId] = $type;
            }
            $hasMore = (bool) ($records['has_more'] ?? false);
            $cursor = is_array($records['cursor'] ?? null)
                ? $records['cursor']
                : (is_array($records['next_cursor'] ?? null) ? $records['next_cursor'] : null);
            if (! $hasMore || $cursor === null) {
                break;
            }
        }

        return ['by_id' => $byId];
    }

    /**
     * @param  array<int, true>  $wpIdSet
     */
    private function softDeleteExtraLocalContent(Site $site, array $wpIdSet, string $languageScope = ''): int
    {
        $removed = 0;
        $local = $this->localWpContentIdsByType((int) $site->id, $languageScope);
        foreach ($local as $wpId => $_type) {
            if (isset($wpIdSet[$wpId])) {
                continue;
            }
            // Content namespace only — never soft-delete a term that shares the numeric id.
            $article = SiteSyncWpIdentity::findContent((int) $site->id, $wpId);
            if ($article === null || $article->trashed()) {
                continue;
            }
            if ($languageScope !== ''
                && trim((string) ($article->language ?? '')) !== $languageScope
            ) {
                continue;
            }
            $article->delete();
            $removed++;
        }

        return $removed;
    }

    /**
     * Non-term WP-backed content only.
     *
     * @return array<int, string> wp_post_id => content_type
     */
    private function localWpContentIdsByType(int $siteId, string $languageScope = ''): array
    {
        $query = ArticleContentClassification::scopeNonTerm(
            SeoArticle::query()->where('site_id', $siteId)->hasWpPostId()
        );
        if ($languageScope !== '') {
            $query->where('language', $languageScope);
        }
        $articles = $query->with(['wordpressLink', 'articleMetas'])->get();

        $out = [];
        foreach ($articles as $article) {
            $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            if ($wpId <= 0) {
                continue;
            }
            $type = (string) ($article->articleMetas->firstWhere('meta_key', ArticleContentClassification::META_CONTENT_TYPE)?->meta_value ?? 'other');
            if (! in_array($type, ['post', 'page', 'product'], true)) {
                $type = 'other';
            }
            $out[$wpId] = $type;
        }

        return $out;
    }

    /**
     * @return array{post: int, page: int, product: int, other: int}
     */
    private function countWpBackedByContentType(int $siteId, string $languageScope = ''): array
    {
        $base = SeoArticle::query()
            ->where('site_id', $siteId)
            ->hasWpPostId();
        if ($languageScope !== '') {
            $base->where('language', $languageScope);
        }

        $post = ArticleContentClassification::scopeNonTerm(
            ArticleContentClassification::scopeContentType(clone $base, ContentType::Post),
        )->count();
        $page = ArticleContentClassification::scopeNonTerm(
            ArticleContentClassification::scopeContentType(clone $base, ContentType::Page),
        )->count();
        $product = ArticleContentClassification::scopeNonTerm(
            ArticleContentClassification::scopeContentType(clone $base, ContentType::Product),
        )->count();
        $total = ArticleContentClassification::scopeNonTerm(clone $base)->count();

        return [
            'post' => $post,
            'page' => $page,
            'product' => $product,
            'other' => max(0, $total - $post - $page - $product),
        ];
    }

    /**
     * User-facing content inventory from discover — never discover.total (content+terms).
     *
     * @param  array<string, mixed>  $discover
     * @param  array<string, mixed>  $byType
     */
    private function contentExpectedFromDiscover(array $discover, array $byType = []): int
    {
        $fromResources = (int) ($discover['resources']['content']['total'] ?? 0);
        if ($fromResources > 0) {
            return $fromResources;
        }

        $sum = 0;
        foreach (['post', 'page', 'product'] as $key) {
            $sum += (int) ($byType[$key] ?? $discover['by_content_type'][$key] ?? 0);
        }
        if ($sum > 0) {
            return $sum;
        }

        // Last resort only when WP did not split resources (legacy payloads).
        return (int) ($discover['total'] ?? 0);
    }

    private function runLanguageScope(SeoSiteSyncRun $run): string
    {
        $meta = is_array($run->meta) ? $run->meta : [];

        return trim((string) ($meta[SiteSyncV3Schema::META_LANGUAGE_SCOPE] ?? ''));
    }

    private function startSuccessMessage(
        Site $site,
        string $languageScope,
        string $languageRole,
        bool $forceFull,
        bool $sync,
    ): string {
        if ($languageScope !== '') {
            $label = app(SitePolylangService::class)->languageLabel($languageScope, $site);
            if ($label === '') {
                $label = $languageScope;
            }
            if ($languageRole === SiteSyncV3Schema::LANGUAGE_ROLE_SECONDARY) {
                return "Đồng bộ {$label}";
            }

            return "Đồng bộ & kiểm tra {$label}";
        }

        if ($sync) {
            return $forceFull
                ? 'Force full site sync V3 completed (sync mode).'
                : 'Site sync V3 completed (sync mode).';
        }

        return $forceFull
            ? 'Đã xếp hàng Đồng bộ lại toàn bộ website (V3).'
            : 'Đã xếp hàng Đồng bộ & kiểm tra website (V3).';
    }
}
