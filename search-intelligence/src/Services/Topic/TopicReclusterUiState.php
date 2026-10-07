<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;

/**
 * Site-scoped durable UI lock/status for explicit Topic recluster.
 *
 * State machine: queued → running → succeeded|failed
 * Survives Livewire F5 via Cache (not session-only).
 */
final class TopicReclusterUiState
{
    private const TTL_SECONDS = 3600;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    /** @deprecated BC alias — prefer STATUS_SUCCEEDED */
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Semantic analyze-only (TASK 4). Does not mutate Topics. */
    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_PROPOSAL_READY = 'proposal_ready';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_APPLY_FAILED = 'apply_failed';

    public const STATUS_DISCARDED = 'discarded';

    public const STATUS_STALE = 'stale';

    public static function cacheKey(int $siteId): string
    {
        return 'topic_core_recluster_ui:'.$siteId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function put(int $siteId, array $payload): void
    {
        if ($siteId <= 0) {
            return;
        }
        Cache::put(self::cacheKey($siteId), $payload, self::TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(int $siteId): ?array
    {
        if ($siteId <= 0) {
            return null;
        }
        $state = Cache::get(self::cacheKey($siteId));
        if (! is_array($state)) {
            return null;
        }

        // Normalize legacy "completed" → succeeded for consumers.
        if (($state['status'] ?? '') === self::STATUS_COMPLETED) {
            $state['status'] = self::STATUS_SUCCEEDED;
        }

        return $state;
    }

    public static function clear(int $siteId): void
    {
        if ($siteId <= 0) {
            return;
        }
        Cache::forget(self::cacheKey($siteId));
    }

    public static function isMutationLocked(int $siteId): bool
    {
        $state = self::get($siteId);
        if ($state === null) {
            return false;
        }
        $status = (string) ($state['status'] ?? '');
        // Apply mutates Topics — lock manual ops while applying.
        if ($status === self::STATUS_APPLYING) {
            return true;
        }
        // Semantic analyze never mutates Topics — keep manual ops available.
        if (($state['mode'] ?? 'legacy') === TopicGroupingProviderMode::SEMANTIC_HTTP) {
            return false;
        }

        return $status === self::STATUS_QUEUED || $status === self::STATUS_RUNNING;
    }

    public static function isActiveStatus(string $status): bool
    {
        return $status === self::STATUS_QUEUED
            || $status === self::STATUS_RUNNING
            || $status === self::STATUS_ANALYZING
            || $status === self::STATUS_APPLYING;
    }

    public static function isAnalyzeActive(int $siteId): bool
    {
        $state = self::get($siteId);
        if ($state === null) {
            return false;
        }
        $status = (string) ($state['status'] ?? '');

        return $status === self::STATUS_QUEUED || $status === self::STATUS_ANALYZING;
    }

    public static function markQueued(
        int $siteId,
        ?string $algorithmVersion = null,
        ?string $rebuildMode = null,
    ): void {
        $semantic = $algorithmVersion === TopicGroupingProviderMode::SEMANTIC_HTTP
            || TopicGroupingProviderMode::isSemanticHttp();
        $metrics = null;
        if ($rebuildMode !== null && $rebuildMode !== '') {
            $metrics = ['rebuild_mode' => $rebuildMode];
        }
        self::put($siteId, [
            'status' => self::STATUS_QUEUED,
            'site_id' => $siteId,
            'mode' => $semantic
                ? TopicGroupingProviderMode::SEMANTIC_HTTP
                : TopicGroupingProviderMode::LEGACY,
            'algorithm_version' => $algorithmVersion ?? TopicReclusterAlgorithm::VERSION,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'metrics' => $metrics,
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    public static function markRunning(int $siteId, ?string $algorithmVersion = null): void
    {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_RUNNING,
            'site_id' => $siteId,
            'algorithm_version' => $algorithmVersion
                ?? (string) ($prior['algorithm_version'] ?? TopicReclusterAlgorithm::VERSION),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => null,
            'metrics' => null,
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public static function markSucceeded(int $siteId, array $metrics, ?string $algorithmVersion = null): void
    {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_SUCCEEDED,
            'site_id' => $siteId,
            'algorithm_version' => $algorithmVersion
                ?? (string) ($prior['algorithm_version'] ?? TopicReclusterAlgorithm::VERSION),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => now()->toIso8601String(),
            'metrics' => $metrics,
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @deprecated Use markSucceeded
     */
    public static function markCompleted(int $siteId, array $metrics): void
    {
        self::markSucceeded($siteId, $metrics);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public static function markFailed(
        int $siteId,
        string $error,
        array $metrics = [],
        ?string $failureReason = null,
        ?string $algorithmVersion = null,
    ): void {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_FAILED,
            'site_id' => $siteId,
            'mode' => (string) ($prior['mode'] ?? TopicGroupingProviderMode::LEGACY),
            'run_id' => $metrics['run_id'] ?? ($prior['run_id'] ?? null),
            'algorithm_version' => $algorithmVersion
                ?? (string) ($prior['algorithm_version'] ?? TopicReclusterAlgorithm::VERSION),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => now()->toIso8601String(),
            'metrics' => $metrics,
            'error' => $error,
            'failure_reason' => $failureReason,
        ]);
    }

    public static function markAnalyzing(int $siteId, string $provider, ?int $runId = null): void
    {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_ANALYZING,
            'site_id' => $siteId,
            'mode' => TopicGroupingProviderMode::SEMANTIC_HTTP,
            'provider' => $provider,
            'run_id' => $runId ?? ($prior['run_id'] ?? null),
            'algorithm_version' => $provider,
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => null,
            'metrics' => null,
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    public static function markProposalReady(int $siteId, SeoTopicGroupingRun $run, string $provider): void
    {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_PROPOSAL_READY,
            'site_id' => $siteId,
            'mode' => TopicGroupingProviderMode::SEMANTIC_HTTP,
            'provider' => $provider,
            'run_id' => (int) $run->id,
            'algorithm_version' => $provider,
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => now()->toIso8601String(),
            'metrics' => [
                'run_id' => (int) $run->id,
                'keyword_count' => (int) $run->keyword_count,
                'group_count' => (int) $run->group_count,
                'unassigned_count' => (int) $run->unassigned_count,
                'low_confidence_count' => (int) $run->low_confidence_count,
                'algorithm' => (string) ($run->algorithm ?? ''),
                'external_analysis_id' => (string) ($run->external_analysis_id ?? ''),
                'input_hash' => (string) ($run->input_hash ?? ''),
                'plan_hash' => (string) ($run->plan_hash ?? ''),
                'rebuild_mode' => $run->rebuildMode(),
            ],
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    public static function markApplying(int $siteId, SeoTopicGroupingRun $run): void
    {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_APPLYING,
            'site_id' => $siteId,
            'mode' => TopicGroupingProviderMode::SEMANTIC_HTTP,
            'provider' => (string) ($run->provider ?? TopicGroupingProviderMode::SEMANTIC_HTTP),
            'run_id' => (int) $run->id,
            'algorithm_version' => (string) ($prior['algorithm_version'] ?? $run->provider),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => null,
            'metrics' => [
                'run_id' => (int) $run->id,
                'plan_hash' => (string) ($run->plan_hash ?? ''),
            ],
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public static function markApplied(int $siteId, SeoTopicGroupingRun $run, array $metrics): void
    {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_APPLIED,
            'site_id' => $siteId,
            'mode' => TopicGroupingProviderMode::SEMANTIC_HTTP,
            'provider' => (string) ($run->provider ?? TopicGroupingProviderMode::SEMANTIC_HTTP),
            'run_id' => (int) $run->id,
            'algorithm_version' => (string) ($prior['algorithm_version'] ?? $run->provider),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => now()->toIso8601String(),
            'metrics' => $metrics,
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    public static function markApplyFailed(
        int $siteId,
        SeoTopicGroupingRun $run,
        string $error,
        ?string $code = null,
    ): void {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_APPLY_FAILED,
            'site_id' => $siteId,
            'mode' => TopicGroupingProviderMode::SEMANTIC_HTTP,
            'provider' => (string) ($run->provider ?? TopicGroupingProviderMode::SEMANTIC_HTTP),
            'run_id' => (int) $run->id,
            'algorithm_version' => (string) ($prior['algorithm_version'] ?? $run->provider),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => now()->toIso8601String(),
            'metrics' => [
                'run_id' => (int) $run->id,
                'apply_error_code' => $code,
            ],
            'error' => $error,
            'failure_reason' => $code,
        ]);
    }

    public static function markDiscarded(int $siteId, SeoTopicGroupingRun $run): void
    {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_DISCARDED,
            'site_id' => $siteId,
            'mode' => TopicGroupingProviderMode::SEMANTIC_HTTP,
            'provider' => (string) ($run->provider ?? TopicGroupingProviderMode::SEMANTIC_HTTP),
            'run_id' => (int) $run->id,
            'algorithm_version' => (string) ($prior['algorithm_version'] ?? $run->provider),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => now()->toIso8601String(),
            'metrics' => ['run_id' => (int) $run->id],
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    public static function markStale(int $siteId, SeoTopicGroupingRun $run, string $reason): void
    {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_STALE,
            'site_id' => $siteId,
            'mode' => TopicGroupingProviderMode::SEMANTIC_HTTP,
            'provider' => (string) ($run->provider ?? TopicGroupingProviderMode::SEMANTIC_HTTP),
            'run_id' => (int) $run->id,
            'algorithm_version' => (string) ($prior['algorithm_version'] ?? $run->provider),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => now()->toIso8601String(),
            'metrics' => ['run_id' => (int) $run->id],
            'error' => $reason,
            'failure_reason' => 'stale',
        ]);
    }
}
