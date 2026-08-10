<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Canonical clear of active-processing markers when leaving processing/publishing.
 * Callers merge returned attributes into their transition payload; side effects
 * (idempotency release, cache lock forget) run via applySideEffects().
 */
final class PublishingProcessingMarkerClearer
{
    public function __construct(
        private readonly ?ContentProjectIdempotencyStore $idempotencyStore = null,
        private readonly ?ContentProjectBusinessLock $businessLock = null,
    ) {}

    /**
     * Attribute subset that must be null when leaving active processing.
     *
     * @return array<string, mixed>
     */
    public function clearedAttributes(bool $clearPublishingStartedAt = true): array
    {
        $attrs = [];

        if ($this->hasColumn('publish_lease_expires_at')) {
            $attrs['publish_lease_expires_at'] = null;
        }
        if ($clearPublishingStartedAt && $this->hasColumn('publishing_started_at')) {
            $attrs['publishing_started_at'] = null;
        }
        if ($this->hasColumn('publisher_started_at')) {
            $attrs['publisher_started_at'] = null;
        }
        if ($this->hasColumn('delivery_dispatched_at')) {
            $attrs['delivery_dispatched_at'] = null;
        }
        if ($this->hasColumn('publish_claimed_at')) {
            $attrs['publish_claimed_at'] = null;
        }
        if ($this->hasColumn('publish_claim_token')) {
            $attrs['publish_claim_token'] = null;
        }

        return $attrs;
    }

    /**
     * Merge clearer into a transition payload (does not save).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mergeInto(array $payload, bool $clearPublishingStartedAt = true): array
    {
        return array_merge($payload, $this->clearedAttributes($clearPublishingStartedAt));
    }

    /**
     * Release idempotency "processing" rows + cache lock for this item attempt.
     */
    public function applySideEffects(SeoProjectTask $task, string $reason = 'processing_exit'): void
    {
        $this->releasePublishIdempotency($task);
        $this->releaseItemPublishLock($task);

        RuntimeLogger::info('publishing.processing_markers_cleared', [
            'task_id' => (int) $task->getKey(),
            'reason' => $reason,
            'publish_queue_status' => (string) ($task->publish_queue_status ?? ''),
            'operation_key' => (string) ($task->publish_operation_key ?? ''),
        ]);
    }

    private function releasePublishIdempotency(SeoProjectTask $task): void
    {
        $opKey = trim((string) ($task->publish_operation_key ?? ''));
        if ($opKey === '') {
            return;
        }

        $siteId = (int) ($task->site_id ?? $task->project?->site_id ?? 0);
        $tenants = [
            'site:'.$siteId.':queue',
            'site:'.$siteId.':actor:queue',
        ];

        try {
            $store = $this->idempotencyStore
                ?? (function_exists('app') ? app(ContentProjectIdempotencyStore::class) : null);
            if ($store instanceof ContentProjectIdempotencyStore) {
                foreach ($tenants as $tenantKey) {
                    $store->releasePublishOperation(
                        $tenantKey,
                        'content_project.process_scheduled_publish',
                        $opKey,
                    );
                }
            }
        } catch (Throwable) {
            // Non-fatal — retry path still attempt-scoped.
        }
    }

    private function releaseItemPublishLock(SeoProjectTask $task): void
    {
        $itemId = (int) $task->getKey();
        if ($itemId <= 0) {
            return;
        }

        try {
            $lock = $this->businessLock
                ?? (function_exists('app') ? app(ContentProjectBusinessLock::class) : null);
            $key = $lock instanceof ContentProjectBusinessLock
                ? $lock->itemPublish($itemId)
                : 'item:'.$itemId.':publish';
            $normalized = 'seo.cp.lock.'.trim($key);
            Cache::forget($normalized);
        } catch (Throwable) {
            // Non-fatal.
        }
    }

    private function hasColumn(string $column): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', $column);
        } catch (Throwable) {
            // Pure PHPUnit / no schema — clear known production columns by name.
            return in_array($column, [
                'publish_lease_expires_at',
                'publishing_started_at',
                'publisher_started_at',
                'delivery_dispatched_at',
                'publish_claimed_at',
                'publish_claim_token',
            ], true);
        }
    }
}
