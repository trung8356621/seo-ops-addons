<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectOperation;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsMetrics;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectMetricKeys;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Structured log cho Content Project commands — không log raw idempotency key / content.
 */
final class ContentProjectOperationLogger
{
    public function __construct(
        private readonly ContentProjectOpsMetrics $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     */
    public function info(
        string $command,
        string $resultCode,
        int $durationMs,
        ?string $operationId = null,
        ?string $idempotencyKey = null,
        ?string $actorType = null,
        ?int $actorId = null,
        ?string $tenantRef = null,
        ?string $projectRef = null,
        ?string $itemRef = null,
        array $extra = [],
    ): void {
        $idempotencyHash = $idempotencyKey !== null && $idempotencyKey !== ''
            ? ContentProjectIdempotencyKeyFactory::hashForLog($idempotencyKey)
            : null;

        $success = array_key_exists('success', $extra)
            ? (bool) $extra['success']
            : ! str_contains(strtolower($resultCode), 'fail');

        $metadata = $this->compactMetadata($extra, $durationMs);

        $context = array_merge([
            'operation_id' => $operationId,
            'idempotency_key_hash' => $idempotencyHash,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'tenant_ref' => $tenantRef,
            'project_ref' => $projectRef,
            'item_ref' => $itemRef,
            'command' => $command,
            'result_code' => $resultCode,
            'duration_ms' => $durationMs,
            'success' => $success,
        ], $metadata);

        RuntimeLogger::info('content_project.operation', $context);

        $this->persistOperation(
            command: $command,
            resultCode: $resultCode,
            durationMs: $durationMs,
            operationId: $operationId,
            idempotencyHash: $idempotencyHash,
            actorType: $actorType,
            actorId: $actorId,
            tenantRef: $tenantRef,
            projectRef: $projectRef,
            itemRef: $itemRef,
            success: $success,
            metadata: $metadata,
            extra: $extra,
        );

        $this->incrementMetrics($command, $tenantRef, $projectRef);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function compactMetadata(array $extra, int $durationMs): array
    {
        $allowed = [
            'request_id',
            'idempotent_replay',
            'command_class',
            'affected_item_refs',
            'command_payload',
            'success',
        ];

        $metadata = ['duration_ms' => $durationMs];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $extra)) {
                $metadata[$key] = $extra[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $extra
     */
    private function persistOperation(
        string $command,
        string $resultCode,
        int $durationMs,
        ?string $operationId,
        ?string $idempotencyHash,
        ?string $actorType,
        ?int $actorId,
        ?string $tenantRef,
        ?string $projectRef,
        ?string $itemRef,
        bool $success,
        array $metadata,
        array $extra,
    ): void {
        if ($operationId === null || $operationId === '') {
            return;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_operations')) {
            return;
        }

        $finishedAt = now();
        $startedAt = $finishedAt->copy()->subMilliseconds(max(0, $durationMs));

        try {
            ContentProjectOperation::query()->insert([
                'operation_id' => $operationId,
                'request_id' => isset($extra['request_id']) ? (string) $extra['request_id'] : null,
                'command' => $command,
                'actor_type' => $actorType ?? 'system',
                'actor_id' => $actorId,
                'tenant_ref' => $tenantRef,
                'project_ref' => $projectRef,
                'item_ref' => $itemRef,
                'article_ref' => isset($extra['article_ref']) ? (string) $extra['article_ref'] : null,
                'result_code' => $resultCode,
                'status' => 'finished',
                'success' => $success,
                'duration_ms' => $durationMs,
                'idempotency_key_hash' => $idempotencyHash,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'created_at' => $finishedAt,
                'updated_at' => $finishedAt,
            ]);
        } catch (Throwable) {
            // persistence never breaks business path
        }
    }

    private function incrementMetrics(string $command, ?string $tenantRef, ?string $projectRef): void
    {
        $siteId = $this->resolveSiteIdFromTenantRef($tenantRef);
        $projectId = $this->resolveProjectIdFromRef($projectRef);

        foreach ($this->metricKeysForCommand($command) as $metricKey) {
            $this->metrics->increment($metricKey, 1, $siteId, $projectId);
        }
    }

    /**
     * @return list<string>
     */
    private function metricKeysForCommand(string $command): array
    {
        $normalized = strtolower(trim($command));

        if (str_contains($normalized, 'generate')) {
            return [ContentProjectMetricKeys::AI_GENERATE_TOTAL];
        }

        if (str_contains($normalized, 'archive') || str_contains($normalized, 'workspace_destroy')) {
            $keys = [ContentProjectMetricKeys::ARCHIVE_TOTAL];
            if (str_contains($normalized, 'workspace_destroy') || str_contains($normalized, 'destroy')) {
                $keys[] = ContentProjectMetricKeys::WORKSPACE_DESTROY_TOTAL;
            }

            return $keys;
        }

        if (str_contains($normalized, 'restore')) {
            return [ContentProjectMetricKeys::RESTORE_TOTAL];
        }

        if (
            str_contains($normalized, 'publish')
            || str_contains($normalized, 'process_scheduled')
        ) {
            $keys = [ContentProjectMetricKeys::PUBLISH_TOTAL];
            if (str_contains($normalized, 'retry')) {
                $keys[] = ContentProjectMetricKeys::PUBLISH_RETRY_TOTAL;
            }

            return $keys;
        }

        return [];
    }

    private function resolveSiteIdFromTenantRef(?string $tenantRef): ?int
    {
        if ($tenantRef === null || ! preg_match('/^site:(\d+)/', $tenantRef, $m)) {
            return null;
        }

        $siteId = (int) $m[1];

        return $siteId > 0 ? $siteId : null;
    }

    private function resolveProjectIdFromRef(?string $projectRef): ?int
    {
        if ($projectRef === null || $projectRef === '') {
            return null;
        }

        try {
            $projectId = ContentProjectPublicRef::decodeProject($projectRef);

            return $projectId > 0 ? $projectId : null;
        } catch (Throwable) {
            return null;
        }
    }
}
