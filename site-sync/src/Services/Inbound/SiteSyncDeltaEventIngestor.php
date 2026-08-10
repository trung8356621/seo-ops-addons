<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Inbound;

use Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncInboundEventJob;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Models\SeoArticleRemoteSnapshot;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncInboundEvent;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncBatchData;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverStateService;
use Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverModes;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Ownership\SiteSyncOwnershipResolver;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteSyncBatchReconciler;
use Omnichannel\Addons\SiteSync\Services\Security\SiteSyncCallbackVerifier;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Support\Str;

final class SiteSyncDeltaEventIngestor
{
    public const MAX_PAYLOAD_BYTES = 1_500_000;

    public function __construct(
        private readonly SiteSyncCallbackVerifier $verifier,
        private readonly SiteSyncFeatureFlags $flags,
        private readonly SiteSyncStagingWriter $staging,
        private readonly SiteSyncBatchReconciler $reconciler,
        private readonly SiteSyncOwnershipResolver $ownership,
        private readonly SiteSyncCutoverStateService $cutover,
    ) {}

    /**
     * Fast path: authenticate + validate + persist inbox + queue. No heavy reconcile in HTTP.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message: string, event_id?: int, status?: string, code?: string}
     */
    public function receive(
        Site $site,
        array $payload,
        string $rawBody,
        ?string $timestamp,
        ?string $nonce,
        ?string $signature,
        ?string $idempotencyKeyHeader = null,
        ?string $operationIdHeader = null,
    ): array {
        if (! $this->flags->autoPushEnabled()) {
            return ['success' => false, 'message' => 'Auto push disabled.', 'code' => 'auto_push_disabled'];
        }

        // legacy_active: accept diagnostic inbox but do not auto-reconcile (fail closed dual-write).
        $mode = $this->cutover->modeFor($site);
        $queueReconcile = $this->cutover->isV2Writer($site);
        if ($mode === SiteSyncCutoverModes::LEGACY_ACTIVE) {
            $queueReconcile = false;
        }

        if (strlen($rawBody) > self::MAX_PAYLOAD_BYTES) {
            return ['success' => false, 'message' => 'Payload too large.', 'code' => 'payload_too_large'];
        }

        $sig = $this->verifier->verify($site, $rawBody, $timestamp, $nonce, $signature);
        if (! $sig['ok']) {
            return [
                'success' => false,
                'message' => $sig['message'],
                'code' => $sig['code'] ?? 'signature_failed',
            ];
        }

        if (! isset($payload['schema'])) {
            $payload['schema'] = SiteSyncSchema::VERSION;
        }
        if (! SiteSyncSchema::isSupportedSchema((string) $payload['schema'])) {
            return ['success' => false, 'message' => 'Unsupported schema.', 'code' => 'schema'];
        }

        $idempotencyKey = trim((string) ($idempotencyKeyHeader
            ?: ($payload['idempotency_key'] ?? '')
            ?: ($payload['operation_id'] ?? '')
            ?: hash('sha256', $rawBody)));
        if ($idempotencyKey === '') {
            $idempotencyKey = Str::uuid()->toString();
        }

        $existing = SeoSiteSyncInboundEvent::query()
            ->where('site_id', (int) $site->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return [
                'success' => true,
                'message' => 'Duplicate ignored.',
                'event_id' => (int) $existing->id,
                'status' => SeoSiteSyncInboundEvent::STATUS_IGNORED_DUPLICATE,
            ];
        }

        $event = SeoSiteSyncInboundEvent::query()->create([
            'site_id' => (int) $site->id,
            'event_id' => (string) ($payload['event_id'] ?? $idempotencyKey),
            'idempotency_key' => $idempotencyKey,
            'operation_id' => (string) ($operationIdHeader ?: ($payload['operation_id'] ?? '')),
            'event_type' => (string) ($payload['event_type'] ?? 'article.updated'),
            'wordpress_id' => isset($payload['wordpress_id']) ? (int) $payload['wordpress_id'] : null,
            'status' => SeoSiteSyncInboundEvent::STATUS_VALIDATED,
            'schema_version' => (string) $payload['schema'],
            'attempts' => 0,
            'hashes' => is_array($payload['hashes'] ?? null) ? $payload['hashes'] : null,
            'payload' => $payload,
            'meta' => [
                'origin' => (string) ($payload['origin'] ?? 'wordpress_outbox'),
                'provider' => (string) ($payload['provider'] ?? ''),
                'changed_fields' => is_array($payload['changed_fields'] ?? null) ? $payload['changed_fields'] : [],
            ],
            'occurred_at' => isset($payload['occurred_at']) ? $payload['occurred_at'] : now(),
            'received_at' => now(),
        ]);

        if (! $queueReconcile) {
            $event->forceFill([
                'status' => SeoSiteSyncInboundEvent::STATUS_VALIDATED,
                'meta' => array_merge(is_array($event->meta) ? $event->meta : [], [
                    'held_for_cutover_mode' => $mode,
                    'reconcile_deferred' => true,
                ]),
            ])->save();

            return [
                'success' => true,
                'message' => 'Delta accepted; reconcile deferred (cutover mode '.$mode.').',
                'event_id' => (int) $event->id,
                'status' => SeoSiteSyncInboundEvent::STATUS_VALIDATED,
                'code' => 'reconcile_deferred',
            ];
        }

        $event->forceFill(['status' => SeoSiteSyncInboundEvent::STATUS_QUEUED])->save();
        ProcessSiteSyncInboundEventJob::dispatch((int) $event->id);

        return [
            'success' => true,
            'message' => 'Delta event queued.',
            'event_id' => (int) $event->id,
            'status' => SeoSiteSyncInboundEvent::STATUS_QUEUED,
        ];
    }

    public function process(int $eventId): void
    {
        $event = SeoSiteSyncInboundEvent::query()->find($eventId);
        if ($event === null) {
            return;
        }
        if (in_array($event->status, [
            SeoSiteSyncInboundEvent::STATUS_COMPLETED,
            SeoSiteSyncInboundEvent::STATUS_IGNORED_DUPLICATE,
            SeoSiteSyncInboundEvent::STATUS_IGNORED_STALE,
        ], true)) {
            return;
        }

        $site = Site::query()->find((int) $event->site_id);
        if ($site === null) {
            $event->forceFill([
                'status' => SeoSiteSyncInboundEvent::STATUS_DEAD_LETTER,
                'last_error_code' => 'site_missing',
                'last_error_message' => 'Site not found',
            ])->save();

            return;
        }

        $event->forceFill([
            'status' => SeoSiteSyncInboundEvent::STATUS_PROCESSING,
            'attempts' => (int) $event->attempts + 1,
        ])->save();

        try {
            $payload = is_array($event->payload) ? $event->payload : [];
            if ($this->isStale($site, $event, $payload)) {
                $event->forceFill([
                    'status' => SeoSiteSyncInboundEvent::STATUS_IGNORED_STALE,
                    'processed_at' => now(),
                    'last_error_code' => 'stale',
                    'last_error_message' => 'Incoming event older than local remote snapshot',
                ])->save();

                return;
            }

            if ($this->hasUnsavedLocalDraft($site, $event)) {
                $this->storeRemoteSnapshotOnly($site, $event, $payload);
                $event->forceFill([
                    'status' => SeoSiteSyncInboundEvent::STATUS_COMPLETED,
                    'processed_at' => now(),
                    'meta' => array_merge(is_array($event->meta) ? $event->meta : [], [
                        'remote_change_available' => true,
                    ]),
                ])->save();

                return;
            }

            if (! isset($payload['mode'])) {
                $payload['mode'] = SiteSyncSchema::MODE_DELTA;
            }
            $batch = SiteSyncBatchData::fromArray($payload);

            $existingBatchId = (int) ((is_array($event->meta) ? ($event->meta['batch_id'] ?? 0) : 0));
            if ($existingBatchId > 0) {
                $existingBatch = \Omnichannel\Addons\SiteSync\Models\SeoSiteSyncBatch::query()->find($existingBatchId);
                if ($existingBatch !== null && $existingBatch->applied_at !== null) {
                    $this->storeRemoteSnapshotOnly($site, $event, $payload);
                    $event->forceFill([
                        'status' => SeoSiteSyncInboundEvent::STATUS_COMPLETED,
                        'processed_at' => now(),
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'meta' => array_merge(is_array($event->meta) ? $event->meta : [], [
                            'batch_id' => $existingBatchId,
                            'resumed_idempotent' => true,
                        ]),
                    ])->save();

                    return;
                }
                if ($existingBatch !== null) {
                    $this->reconciler->apply($site, $existingBatch);
                    $this->storeRemoteSnapshotOnly($site, $event, $payload);
                    $event->forceFill([
                        'status' => SeoSiteSyncInboundEvent::STATUS_COMPLETED,
                        'processed_at' => now(),
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'meta' => array_merge(is_array($event->meta) ? $event->meta : [], [
                            'batch_id' => (int) $existingBatch->id,
                            'resumed' => true,
                        ]),
                    ])->save();

                    return;
                }
            }

            $staged = $this->staging->stage($site, $batch);
            $event->forceFill([
                'meta' => array_merge(is_array($event->meta) ? $event->meta : [], [
                    'batch_id' => (int) $staged->id,
                    'staged_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $this->reconciler->apply($site, $staged);
            $this->storeRemoteSnapshotOnly($site, $event, $payload);

            $event->forceFill([
                'status' => SeoSiteSyncInboundEvent::STATUS_COMPLETED,
                'processed_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
                'meta' => array_merge(is_array($event->meta) ? $event->meta : [], [
                    'batch_id' => (int) $staged->id,
                ]),
            ])->save();
        } catch (\Throwable $e) {
            RuntimeLogger::warning('site_sync.inbound_process_failed', [
                'event_id' => $eventId,
                'site_id' => $event->site_id,
                'error' => $e->getMessage(),
            ]);
            $dead = (int) $event->attempts >= 8;
            $event->forceFill([
                'status' => $dead
                    ? SeoSiteSyncInboundEvent::STATUS_DEAD_LETTER
                    : SeoSiteSyncInboundEvent::STATUS_FAILED,
                'last_error_code' => 'process_failed',
                'last_error_message' => mb_substr($e->getMessage(), 0, 500),
                'retry_after' => $dead ? null : now()->addSeconds(min(3600, 30 * (2 ** max(0, (int) $event->attempts - 1)))),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isStale(Site $site, SeoSiteSyncInboundEvent $event, array $payload): bool
    {
        $wpId = (int) ($event->wordpress_id ?? 0);
        if ($wpId <= 0) {
            return false;
        }
        $incomingHash = (string) (($payload['hashes']['content_hash'] ?? null) ?: ($payload['articles'][0]['content_hash'] ?? ''));
        $snap = SeoArticleRemoteSnapshot::query()
            ->where('site_id', (int) $site->id)
            ->where('wordpress_id', $wpId)
            ->first();
        if ($snap === null || $incomingHash === '') {
            return false;
        }
        $occurred = $event->occurred_at;
        if ($occurred !== null && $snap->remote_modified_at !== null && $occurred->lt($snap->remote_modified_at)) {
            return true;
        }

        return false;
    }

    private function hasUnsavedLocalDraft(Site $site, SeoSiteSyncInboundEvent $event): bool
    {
        $wpId = (int) ($event->wordpress_id ?? 0);
        if ($wpId <= 0) {
            return false;
        }
        $article = SeoArticle::query()
            ->where('site_id', (int) $site->id)
            ->whereWpPostId($wpId)
            ->first();
        if ($article === null) {
            return false;
        }
        // Convention: article meta flag set by editor while draft dirty.
        $dirty = $article->articleMetas()
            ->where('meta_key', 'seo_editor_unsaved_draft')
            ->value('meta_value');

        return (string) $dirty === '1';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeRemoteSnapshotOnly(Site $site, SeoSiteSyncInboundEvent $event, array $payload): void
    {
        $wpId = (int) ($event->wordpress_id ?? 0);
        if ($wpId <= 0) {
            return;
        }
        $articleId = SeoArticle::query()
            ->where('site_id', (int) $site->id)
            ->whereWpPostId($wpId)
            ->value('id');

        SeoArticleRemoteSnapshot::query()->updateOrCreate(
            ['site_id' => (int) $site->id, 'wordpress_id' => $wpId],
            [
                'article_id' => $articleId !== null ? (int) $articleId : null,
                'content_hash' => (string) (($payload['hashes']['content_hash'] ?? null) ?: ''),
                'remote_change_available' => true,
                'payload' => $payload,
                'remote_modified_at' => $event->occurred_at ?? now(),
            ],
        );
    }
}
