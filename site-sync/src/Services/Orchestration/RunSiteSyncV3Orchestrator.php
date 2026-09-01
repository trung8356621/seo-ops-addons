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
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Throwable;

/**
 * Site Sync V3 orchestrator — phase machine (no staged-batch payload replay,
 * no discrete provider-keyword step). Content import never writes body.
 */
final class RunSiteSyncV3Orchestrator
{
    private const CATCH_UP_MAX_ROUNDS = 3;

    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
        private readonly WordPressSiteSyncV3Client $client,
        private readonly SiteSyncV3BulkImporter $importer,
        private readonly SiteCapabilityResolver $capabilities,
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

        // First successful V3 baseline must be force-full — no silent delta acceptance.
        if (! $forceFull && ! self::hasSuccessfulBaseline($site)) {
            return [
                'success' => false,
                'message' => 'Chưa có V3 force-full baseline — chạy Force Full trước khi dùng delta.',
                'protocol' => SiteSyncV3Schema::PROTOCOL,
                'error_code' => 'v3_baseline_required',
            ];
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
        if ($sync) {
            $this->handle((int) $run->id);

            return [
                'success' => true,
                'message' => $forceFull
                    ? 'Force full site sync V3 completed (sync mode).'
                    : 'Site sync V3 completed (sync mode).',
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
            'message' => $forceFull
                ? 'Đã xếp hàng Đồng bộ lại toàn bộ website (V3).'
                : 'Đã xếp hàng Đồng bộ & kiểm tra website (V3).',
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

        $run->forceFill([
            'status' => 'running',
            'resumable' => true,
            'error_message' => null,
        ])->save();

        ProcessSiteSyncV3Job::dispatch($runId, app(SiteSyncRunExecution::class)->readGeneration($run));

        return [
            'success' => true,
            'message' => 'Resuming site sync V3.',
            'run_id' => $runId,
            'public_ref' => (string) $run->public_ref,
            'protocol' => SiteSyncV3Schema::PROTOCOL,
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

        $result = $this->client->discover($site);
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
        $meta['initial_expected_total'] = (int) ($discover['total'] ?? array_sum(array_map('intval', $byType)));
        $meta['initial_expected_by_type'] = $byType;
        $meta['site_revision'] = isset($discover['site_revision']) ? (string) $discover['site_revision'] : null;
        $meta['import_resource'] = SiteSyncV3Schema::RESOURCE_CONTENT;
        $meta['cursor'] = null;
        $meta['continuation'] = ((int) ($meta['continuation'] ?? 0)) + 1;

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
        $since = (string) ($meta['catch_up_since']
            ?? $meta['catch_up_boundary_at']
            ?? $meta['snapshot_at']
            ?? now()->toIso8601String());
        if (! isset($meta['catch_up_since'])) {
            $meta['catch_up_since'] = $since;
        }
        $generation = (int) ($meta['sync_generation'] ?? $run->id);
        $budget = SiteSyncV3Schema::RECORDS_PER_JOB * 2;
        $jobNumber = (int) ($meta['job_number'] ?? 0);
        $counters = is_array($run->counters) ? $run->counters : [];

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
        $result = $this->client->discover($site);
        if (! ($result['success'] ?? false)) {
            return $this->failRun(
                $run,
                'verify_discover_failed',
                (string) ($result['message'] ?? 'V3 verify discover failed'),
            );
        }

        $discover = is_array($result['discover'] ?? null) ? $result['discover'] : [];
        $expectedByType = is_array($discover['by_content_type'] ?? null) ? $discover['by_content_type'] : [];
        $expectedTotal = (int) ($discover['total'] ?? 0);
        $contentExpected = 0;
        foreach (['post', 'page', 'product'] as $key) {
            $contentExpected += (int) ($expectedByType[$key] ?? 0);
        }

        $wpInventory = $this->enumerateWpContentInventory($site, $discover);
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
        $extraRemoved = $this->softDeleteExtraLocalContent($site, $wpIdSet);

        $localByType = $this->countWpBackedByContentType((int) $site->id);
        $localTotal = array_sum($localByType);
        $localIdsByType = $this->localWpContentIdsByType((int) $site->id);

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
            'sample_missing_wp_ids' => array_slice($missingIds, 0, 20),
            'sample_extra_wp_ids' => array_slice($extraIds, 0, 20),
            'extra_removed' => $extraRemoved,
            'at' => now()->toIso8601String(),
        ];
        $meta['continuation'] = ((int) ($meta['continuation'] ?? 0)) + 1;

        $hasMismatch = $missingIds !== [] || $extraIds !== [] || $typeMismatch !== [];
        // Count deltas are diagnostic only when membership lists are empty (discover timing skew).
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
            'current_step' => SiteSyncV3Schema::PHASE_COMPLETE,
            'status' => 'running',
        ])->save();

        return true;
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

        if ($forceFull && $cleanVerify) {
            $site = Site::query()->find((int) $run->site_id);
            if ($site !== null) {
                $generation = (int) ($meta['sync_generation'] ?? $run->id);
                SiteSyncSiteMeta::put(
                    $site,
                    SiteSyncV3Schema::META_BASELINE_COMPLETED_AT,
                    now()->toIso8601String(),
                );
                SiteSyncSiteMeta::put(
                    $site,
                    SiteSyncV3Schema::META_BASELINE_GENERATION,
                    (string) $generation,
                );
                $meta['v3_baseline_completed_at'] = now()->toIso8601String();
                $meta['v3_baseline_generation'] = $generation;
            }
        }

        $run->forceFill([
            'status' => 'completed',
            'current_step' => SiteSyncV3Schema::PHASE_COMPLETE,
            'finished_at' => now(),
            'resumable' => false,
            'error_message' => null,
            'meta' => $meta,
        ])->save();

        return false;
    }

    public static function hasSuccessfulBaseline(Site $site): bool
    {
        $at = trim((string) ($site->getMeta(SiteSyncV3Schema::META_BASELINE_COMPLETED_AT) ?? ''));

        return $at !== '';
    }

    public static function baselineGeneration(Site $site): int
    {
        return (int) ($site->getMeta(SiteSyncV3Schema::META_BASELINE_GENERATION) ?? 0);
    }

    private function failRun(SeoSiteSyncRun $run, string $code, string $message): bool
    {
        $meta = is_array($run->meta) ? $run->meta : [];
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
        ]);

        return false;
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
    private function enumerateWpContentInventory(Site $site, array $discover): ?array
    {
        $snapshotAt = (string) ($discover['snapshot_at'] ?? $discover['generated_at'] ?? '');
        $bounds = is_array($discover['snapshot_bounds'] ?? null) ? $discover['snapshot_bounds'] : [];
        if ($snapshotAt === '' || (int) ($bounds['content_max_id'] ?? 0) <= 0) {
            return null;
        }

        $byId = [];
        $cursor = null;
        for ($page = 0; $page < 200; $page++) {
            $fetched = $this->client->records($site, [
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
            ]);
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
    private function softDeleteExtraLocalContent(Site $site, array $wpIdSet): int
    {
        $removed = 0;
        $local = $this->localWpContentIdsByType((int) $site->id);
        foreach ($local as $wpId => $_type) {
            if (isset($wpIdSet[$wpId])) {
                continue;
            }
            $article = SeoArticle::query()
                ->where('site_id', (int) $site->id)
                ->whereWpPostId($wpId)
                ->first();
            if ($article === null || $article->trashed()) {
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
    private function localWpContentIdsByType(int $siteId): array
    {
        $articles = ArticleContentClassification::scopeNonTerm(
            SeoArticle::query()->where('site_id', $siteId)->hasWpPostId()
        )->with(['wordpressLink', 'articleMetas'])->get();

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
    private function countWpBackedByContentType(int $siteId): array
    {
        $base = SeoArticle::query()
            ->where('site_id', $siteId)
            ->hasWpPostId();

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
}
