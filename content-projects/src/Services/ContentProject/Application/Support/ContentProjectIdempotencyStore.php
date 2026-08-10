<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Idempotency store — retention 7 ngày.
 * Stale "processing" (lease TTL) được giải phóng để retry không bị kẹt.
 */
final class ContentProjectIdempotencyStore
{
    private const RETENTION_DAYS = 7;

    public function begin(string $tenantKey, string $action, string $key): ?ContentProjectActionResult
    {
        if ($key === '' || ! $this->ready()) {
            return null;
        }

        $this->purgeExpired();

        $existing = DB::connection('omi_seo_ai')->table('seo_content_project_idempotency_keys')
            ->where('tenant_key', $tenantKey)
            ->where('action', $action)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            $status = (string) ($existing->status ?? '');

            if ($status === 'processing') {
                $updatedAt = isset($existing->updated_at) ? strtotime((string) $existing->updated_at) : false;
                $staleSeconds = PublishingRetryPolicy::LEASE_MINUTES * 60;
                $isStale = $updatedAt === false || (time() - $updatedAt) >= $staleSeconds;
                if ($isStale) {
                    $this->release($tenantKey, $action, $key);

                    return $this->begin($tenantKey, $action, $key);
                }

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING,
                    'Request trước đó đang processing.',
                    metadata: ['idempotent' => true, 'status' => 'processing'],
                );
            }

            if ($status === 'failed') {
                // Failed attempts must be retryable with a new begin (same or new key).
                $this->release($tenantKey, $action, $key);

                return $this->begin($tenantKey, $action, $key);
            }

            if ($status === 'succeeded' && is_string($existing->result_payload)) {
                $payload = json_decode($existing->result_payload, true);
                if (is_array($payload)) {
                    return new ContentProjectActionResult(
                        success: (bool) ($payload['success'] ?? true),
                        code: ContentProjectActionCodes::IDEMPOTENT_REPLAY,
                        message: (string) ($payload['message'] ?? 'Idempotent replay.'),
                        projectId: isset($payload['project_id']) ? (int) $payload['project_id'] : null,
                        affectedItemIds: is_array($payload['affected_item_ids'] ?? null)
                            ? array_map('intval', $payload['affected_item_ids'])
                            : [],
                        warnings: is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [],
                        errors: is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
                        metadata: array_merge(
                            is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                            ['idempotent' => true, 'replay' => true],
                        ),
                    );
                }
            }
        }

        try {
            DB::connection('omi_seo_ai')->table('seo_content_project_idempotency_keys')->insert([
                'tenant_key' => $tenantKey,
                'action' => $action,
                'idempotency_key' => $key,
                'status' => 'processing',
                'result_payload' => null,
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // race → re-read
            return $this->begin($tenantKey, $action, $key);
        }

        return null;
    }

    public function complete(string $tenantKey, string $action, string $key, ContentProjectActionResult $result): void
    {
        if ($key === '' || ! $this->ready()) {
            return;
        }

        DB::connection('omi_seo_ai')->table('seo_content_project_idempotency_keys')
            ->where('tenant_key', $tenantKey)
            ->where('action', $action)
            ->where('idempotency_key', $key)
            ->update([
                'status' => $result->success ? 'succeeded' : 'failed',
                'result_payload' => json_encode($result->toArray(), JSON_UNESCAPED_UNICODE),
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
                'updated_at' => now(),
            ]);
    }

    /**
     * Drop store row so a later attempt may begin again.
     */
    public function release(string $tenantKey, string $action, string $key): void
    {
        if ($key === '' || ! $this->ready()) {
            return;
        }

        DB::connection('omi_seo_ai')->table('seo_content_project_idempotency_keys')
            ->where('tenant_key', $tenantKey)
            ->where('action', $action)
            ->where('idempotency_key', $key)
            ->delete();
    }

    /**
     * Release bare operation key + all attempt-scoped keys for one publish lifecycle.
     */
    public function releasePublishOperation(string $tenantKey, string $action, string $operationKey): void
    {
        $operationKey = trim($operationKey);
        if ($operationKey === '' || ! $this->ready()) {
            return;
        }

        DB::connection('omi_seo_ai')->table('seo_content_project_idempotency_keys')
            ->where('tenant_key', $tenantKey)
            ->where('action', $action)
            ->where(static function ($q) use ($operationKey): void {
                $q->where('idempotency_key', $operationKey)
                    ->orWhere('idempotency_key', 'like', $operationKey.':attempt:%');
            })
            ->delete();
    }

    private function ready(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_content_project_idempotency_keys');
    }

    private function purgeExpired(): void
    {
        try {
            DB::connection('omi_seo_ai')->table('seo_content_project_idempotency_keys')
                ->where('expires_at', '<', now())
                ->limit(200)
                ->delete();
        } catch (Throwable) {
            // ignore
        }
    }
}
