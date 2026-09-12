<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AiRuntimeHealthService
{
    public const CONNECTION_NAME = 'mysql';

    public const COOLDOWN_MINUTES = 3;

    private const DEGRADED_THRESHOLD = 3;

    private const UNAVAILABLE_THRESHOLD = 5;

    public function __construct(
        private readonly ?AiRuntimeHealthNotificationPublisher $notifications = null,
    ) {}

    public function skipReason(int $userId, RoutedAiCandidate $candidate): ?string
    {
        // SSOT: api_connections.paid_locked only (paid lane). Free candidates ignore this.
        // Deprecated: ai_runtime_health_states.paid_locked is observability — not enforcement.
        if ($candidate->isFree === false && $this->connectionPaidLaneLocked($candidate->connection)) {
            return 'connection_paid_locked';
        }

        if (! $this->tableReady()) {
            return null;
        }

        $connectionId = (int) $candidate->connection->id;
        $connectionHealth = $this->findSubject($userId, AiRuntimeHealthState::SUBJECT_CONNECTION, $connectionId);

        if ($connectionHealth !== null) {
            if ($connectionHealth->health_status === AiRuntimeHealthStatus::ConnectionLocked->value) {
                return 'connection_locked';
            }

            if ($this->isOnCooldown($connectionHealth)) {
                if ($this->isLegacyRateLimitedConnectionCooldown($connectionHealth)) {
                    $this->neutralizeLegacyRateLimitedConnectionCooldown($connectionHealth);
                } else {
                    return 'connection_cooldown';
                }
            }
        }

        $modelId = $candidate->seoAiModelId;
        if ($modelId !== null) {
            $modelHealth = $this->findSubject($userId, AiRuntimeHealthState::SUBJECT_MODEL, $modelId);
            if ($modelHealth !== null) {
                if ($modelHealth->health_status === AiRuntimeHealthStatus::Unavailable->value) {
                    return 'model_unavailable';
                }

                if ($this->isOnCooldown($modelHealth)) {
                    return 'model_cooldown';
                }
            }
        }

        return null;
    }

    public function recordSuccess(int $userId, RoutedAiCandidate $candidate): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $now = now();
        $connectionId = (int) $candidate->connection->id;

        $this->mutateSubject($userId, AiRuntimeHealthState::SUBJECT_CONNECTION, $connectionId, $connectionId, function (AiRuntimeHealthState $row) use ($now, $userId): void {
            $previous = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
            $row->total_attempts++;
            $row->success_count++;
            $row->consecutive_failures = 0;
            $row->last_success_at = $now;
            $counts = is_array($row->failure_counts) ? $row->failure_counts : [];
            $counts['consecutive_connection'] = 0;
            $counts['consecutive_paid_lane'] = 0;
            $row->failure_counts = $counts;
            if (! $row->manual_unlock_required && ! $row->paid_locked) {
                $row->health_status = AiRuntimeHealthStatus::Healthy->value;
                $row->cooldown_until = null;
            }
            $this->maybeNotifyRecovery($userId, $row, $previous);
        });

        if ($candidate->seoAiModelId !== null) {
            $this->mutateSubject(
                $userId,
                AiRuntimeHealthState::SUBJECT_MODEL,
                (int) $candidate->seoAiModelId,
                $connectionId,
                function (AiRuntimeHealthState $row) use ($now, $candidate, $userId): void {
                    $previous = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
                    $row->total_attempts++;
                    $row->success_count++;
                    $row->consecutive_failures = 0;
                    $row->last_success_at = $now;
                    // Success recovers hard Unavailable too (probe / post-repair path).
                    $row->health_status = AiRuntimeHealthStatus::Healthy->value;
                    $row->cooldown_until = null;
                    $this->updateModelLastError($candidate, null);
                    $this->maybeNotifyRecovery($userId, $row, $previous, $candidate);
                },
            );
        }
    }

    public function recordFailure(int $userId, RoutedAiCandidate $candidate, AiFailureDecision $decision): void
    {
        if (! $decision->affectsRuntimeHealth) {
            return;
        }

        if (! $this->tableReady()) {
            return;
        }

        $now = now();
        $connectionId = (int) $candidate->connection->id;
        $errorCode = $decision->errorCode ?? ($decision->httpStatus !== null ? (string) $decision->httpStatus : null);

        $applyConnectionPaidLock = false;
        $this->mutateSubject($userId, AiRuntimeHealthState::SUBJECT_CONNECTION, $connectionId, $connectionId, function (AiRuntimeHealthState $row) use ($decision, $now, $errorCode, $userId, &$applyConnectionPaidLock): void {
            $previous = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
            $this->incrementFailureCounters($row, $errorCode, $decision, $now);
            $this->bumpScopedConsecutiveCounters($row, $decision);

            if ($decision->lockConnection
                || (($this->scopedConsecutive($row, 'consecutive_connection') >= self::DEGRADED_THRESHOLD)
                    && $decision->scope === AiFailureScope::Connection)) {
                $row->health_status = AiRuntimeHealthStatus::ConnectionLocked->value;
                $row->manual_unlock_required = true;
            } elseif ($decision->lockConnectionPaid
                || (($this->scopedConsecutive($row, 'consecutive_paid_lane') >= self::DEGRADED_THRESHOLD)
                    && ($decision->scope === AiFailureScope::ConnectionPaid || $decision->lockConnectionPaid))) {
                // Observability only — authoritative paid lock is api_connections via ConnectionPaidLockService.
                $row->health_status = AiRuntimeHealthStatus::BudgetLimited->value;
                $row->paid_locked = true; // deprecated mirror; not used for route enforcement
                $row->manual_unlock_required = true;
                $applyConnectionPaidLock = true;
            } elseif ($decision->applyCooldown && $this->cooldownAppliesToConnection($decision)) {
                // Model-scoped transient/429 must not cooldown the whole connection —
                // sibling models on the same connection must still be tried in-route.
                $row->cooldown_until = now()->addMinutes(self::COOLDOWN_MINUTES);
                $row->health_status = $this->degradedOrExisting($row)->value;
            }

            $this->maybeNotifyFailure($userId, $row, $previous, $decision);
        });

        if ($applyConnectionPaidLock) {
            app(ConnectionPaidLockService::class)->addReason(
                $candidate->connection,
                \Omnichannel\Addons\AiPrompt\Support\PaidLockReason::BudgetLimited,
            );
            $candidate->connection->refresh();
        }

        if ($candidate->seoAiModelId !== null) {
            $this->mutateSubject(
                $userId,
                AiRuntimeHealthState::SUBJECT_MODEL,
                (int) $candidate->seoAiModelId,
                $connectionId,
                function (AiRuntimeHealthState $row) use ($decision, $now, $errorCode, $userId, $candidate): void {
                    $previous = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
                    $this->incrementFailureCounters($row, $errorCode, $decision, $now);

                    if ($decision->markModelUnavailable || $decision->category === AiFailureClass::ModelNotFound) {
                        $row->health_status = AiRuntimeHealthStatus::Unavailable->value;
                    } elseif ($decision->applyCooldown) {
                        $row->cooldown_until = now()->addMinutes(self::COOLDOWN_MINUTES);
                        // Soft/transient failures stay Degraded — never permanent Unavailable.
                        $row->health_status = $this->statusAfterProviderFailure($row, $decision)->value;
                    } else {
                        $row->health_status = $this->statusAfterProviderFailure($row, $decision)->value;
                    }

                    $this->updateModelLastError($candidate, $decision);
                    $this->maybeNotifyFailure($userId, $row, $previous, $decision, $candidate);
                },
            );
        }
    }

    public function unlockConnection(int $userId, int $connectionId): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $row = $this->findSubject($userId, AiRuntimeHealthState::SUBJECT_CONNECTION, $connectionId);
        if ($row === null) {
            return;
        }

        $previous = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
        $row->health_status = AiRuntimeHealthStatus::Healthy->value;
        $row->manual_unlock_required = false;
        $row->paid_locked = false; // deprecated mirror
        $row->cooldown_until = null;
        $row->save();
        $this->clearConnectionBudgetLockReason($connectionId);
        $this->maybeNotifyRecovery($userId, $row, $previous);
    }

    /**
     * Clear connection locks for every routing owner that recorded health on this API connection.
     * Used after credentials are fixed — owner user_id on health rows can diverge from auth().
     */
    public function unlockConnectionForApiConnection(int $connectionId): int
    {
        if (! $this->tableReady() || $connectionId <= 0) {
            return 0;
        }

        $cleared = 0;
        $rows = AiRuntimeHealthState::query()
            ->where('subject_type', AiRuntimeHealthState::SUBJECT_CONNECTION)
            ->where(function ($query) use ($connectionId): void {
                $query->where('subject_id', $connectionId)
                    ->orWhere('api_connection_id', $connectionId);
            })
            ->get();

        foreach ($rows as $row) {
            $previous = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
            $needsClear = $row->health_status === AiRuntimeHealthStatus::ConnectionLocked->value
                || $row->manual_unlock_required
                || $row->paid_locked
                || $row->cooldown_until !== null;
            if (! $needsClear) {
                continue;
            }
            $row->health_status = AiRuntimeHealthStatus::Healthy->value;
            $row->manual_unlock_required = false;
            $row->paid_locked = false; // deprecated mirror
            $row->cooldown_until = null;
            $row->save();
            $cleared++;
            $this->maybeNotifyRecovery((int) $row->user_id, $row, $previous);
        }

        $this->clearConnectionBudgetLockReason($connectionId);

        return $cleared;
    }

    public function enablePaidRoutes(int $userId, int $connectionId): void
    {
        // Authoritative: remove budget_limited from api_connections (keeps manual_free_only).
        $this->clearConnectionBudgetLockReason($connectionId);

        if (! $this->tableReady()) {
            return;
        }

        $row = $this->findSubject($userId, AiRuntimeHealthState::SUBJECT_CONNECTION, $connectionId);
        if ($row === null) {
            return;
        }

        $previous = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
        $row->paid_locked = false; // deprecated mirror
        if ($row->health_status === AiRuntimeHealthStatus::BudgetLimited->value) {
            $row->health_status = AiRuntimeHealthStatus::Healthy->value;
            $row->manual_unlock_required = false;
        }
        $row->save();
        $this->maybeNotifyRecovery($userId, $row, $previous);
    }

    /**
     * Configured connections LEFT JOIN health state (unused → No data).
     *
     * @return list<array<string, mixed>>
     */
    public function connectionHealthRows(int $userId): array
    {
        $healthById = [];
        if ($this->tableReady()) {
            foreach (AiRuntimeHealthState::query()
                ->where('user_id', $userId)
                ->where('subject_type', AiRuntimeHealthState::SUBJECT_CONNECTION)
                ->get() as $row) {
                $healthById[(int) $row->subject_id] = $row;
            }
        }

        $out = [];
        $seen = [];
        foreach (app(AiModelPriorityService::class)->aiConnections($userId) as $connection) {
            $id = (int) $connection->id;
            $seen[$id] = true;
            $row = $healthById[$id] ?? null;
            $out[] = $this->presentConnectionRow($connection, $row);
        }

        foreach ($healthById as $id => $row) {
            if (isset($seen[$id])) {
                continue;
            }
            $connection = $row->apiConnection;
            $out[] = $this->presentConnectionRow($connection, $row, $id);
        }

        return $out;
    }

    /**
     * Configured area-enabled models LEFT JOIN health state (unused → No data).
     *
     * @return list<array<string, mixed>>
     */
    public function modelHealthRows(int $userId): array
    {
        $healthById = [];
        if ($this->tableReady()) {
            foreach (AiRuntimeHealthState::query()
                ->where('user_id', $userId)
                ->where('subject_type', AiRuntimeHealthState::SUBJECT_MODEL)
                ->get() as $row) {
                $healthById[(int) $row->subject_id] = $row;
            }
        }

        $priorities = app(AiModelPriorityService::class);
        $out = [];
        $seen = [];
        foreach ([
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextFast,
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextLongform,
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextReasoning,
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::Image,
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::Video,
        ] as $area) {
            foreach ($priorities->areaEnabledModels($userId, $area) as $model) {
                $id = (int) $model->id;
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[] = $this->presentModelRow($model, $healthById[$id] ?? null, $this->areaDisplayLabel($area));
            }
        }

        foreach ($healthById as $id => $row) {
            if (isset($seen[$id])) {
                continue;
            }
            $model = SeoAiModel::query()->with('apiConnection')->find($id);
            $out[] = $this->presentModelRow($model, $row, null);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentConnectionRow(
        ?\App\Models\ApiConnection $connection,
        ?AiRuntimeHealthState $row,
        ?int $fallbackId = null,
    ): array {
        $id = $connection !== null ? (int) $connection->id : (int) ($fallbackId ?? $row?->subject_id ?? 0);
        // Prefer health row keyed by connection owner; fall back to any lock on this connection.
        if ($row === null && $connection !== null && $this->tableReady()) {
            $ownerId = app(AiRoutingOwnerResolver::class)->forConnection($connection);
            if ($ownerId > 0) {
                $row = $this->findSubject($ownerId, AiRuntimeHealthState::SUBJECT_CONNECTION, $id);
            }
            if ($row === null) {
                $row = AiRuntimeHealthState::query()
                    ->where('subject_type', AiRuntimeHealthState::SUBJECT_CONNECTION)
                    ->where('subject_id', $id)
                    ->where(function ($query): void {
                        $query->where('health_status', AiRuntimeHealthStatus::ConnectionLocked->value)
                            ->orWhere('manual_unlock_required', true)
                            ->orWhere('paid_locked', true);
                    })
                    ->orderByDesc('updated_at')
                    ->first();
            }
        }
        $status = $row !== null
            ? (AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData)
            : AiRuntimeHealthStatus::NoData;

        return [
            'connection_id' => $id,
            'connection_name' => $connection !== null ? (string) $connection->name : '#'.$id,
            'provider' => $connection !== null ? (string) $connection->provider : '',
            'health_status' => $status->value,
            'health_label' => $status->label(),
            'paid_locked' => (bool) ($row?->paid_locked ?? false), // deprecated mirror; SSOT is api_connections
            'paid_lock_reasons' => $connection !== null
                ? app(ConnectionPaidLockService::class)->reasonValues($connection)
                : [],
            'connection_paid_locked' => $connection !== null
                ? app(ConnectionPaidLockService::class)->isPaidLocked($connection)
                : false,
            'manual_unlock_required' => (bool) ($row?->manual_unlock_required ?? false),
            'success_count' => (int) ($row?->success_count ?? 0),
            'failure_count' => (int) ($row?->failure_count ?? 0),
            'last_failure_class' => $row?->last_failure_class,
            'last_error_code' => $row?->last_error_code,
            'last_failure_message' => $row?->last_failure_message,
            'consecutive_failures' => (int) ($row?->consecutive_failures ?? 0),
            'last_success_at' => $row?->last_success_at?->format('H:i'),
            'last_success_at_raw' => $row?->last_success_at?->toIso8601String(),
            'action' => $row !== null ? $this->connectionAction($row) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentModelRow(
        ?SeoAiModel $model,
        ?AiRuntimeHealthState $row,
        ?string $areaLabel,
    ): array {
        $id = $model !== null ? (int) $model->id : (int) ($row?->subject_id ?? 0);
        $connection = $model?->apiConnection ?? $row?->apiConnection;
        $status = $row !== null
            ? (AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData)
            : AiRuntimeHealthStatus::NoData;

        return [
            'model_id' => $id,
            'model_name' => $model !== null ? (string) ($model->display_name ?: $model->raw_model_name) : '#'.$id,
            'raw_model_name' => $model !== null ? (string) $model->raw_model_name : '',
            'provider' => $connection !== null ? (string) $connection->provider : '',
            'connection_name' => $connection !== null ? (string) $connection->name : '',
            'area_label' => $areaLabel ?? '—',
            'health_status' => $status->value,
            'health_label' => $status->label(),
            'success_count' => (int) ($row?->success_count ?? 0),
            'failure_count' => (int) ($row?->failure_count ?? 0),
            'consecutive_failures' => (int) ($row?->consecutive_failures ?? 0),
            'last_failure_class' => $row?->last_failure_class,
            'last_error_code' => $row?->last_error_code,
            'last_success_at' => $row?->last_success_at?->format('d/m H:i'),
            'last_success_at_raw' => $row?->last_success_at?->toIso8601String(),
        ];
    }

    private function areaDisplayLabel(\Omnichannel\Addons\AiPrompt\Support\AiModelArea $area): string
    {
        return match ($area) {
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextFast => 'Fast Text',
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextLongform => 'Long-form Text',
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextReasoning => 'Reasoning Text',
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::Image => 'Image',
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::Video => 'Video',
            default => $area->value,
        };
    }

    private function tableReady(): bool
    {
        try {
            return Schema::connection($this->connectionName())->hasTable('ai_runtime_health_states');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Authoritative paid-lane lock on api_connections (manual_free_only and/or budget_limited).
     */
    private function connectionPaidLaneLocked(ApiConnection $connection): bool
    {
        if (array_key_exists('paid_locked', $connection->getAttributes())) {
            return (bool) $connection->getAttribute('paid_locked');
        }

        try {
            if (! Schema::connection($connection->getConnectionName() ?: $this->connectionName())
                ->hasColumn('api_connections', 'paid_locked')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return (bool) ($connection->getAttribute('paid_locked') ?? false);
    }

    private function clearConnectionBudgetLockReason(int $connectionId): void
    {
        if ($connectionId <= 0) {
            return;
        }

        $connection = ApiConnection::query()->find($connectionId);
        if (! $connection instanceof ApiConnection) {
            return;
        }

        app(ConnectionPaidLockService::class)->removeReason(
            $connection,
            \Omnichannel\Addons\AiPrompt\Support\PaidLockReason::BudgetLimited,
        );
    }

    private function connectionName(): string
    {
        return (string) config('database.core_connection', self::CONNECTION_NAME);
    }

    private function findSubject(int $userId, string $subjectType, int $subjectId): ?AiRuntimeHealthState
    {
        return AiRuntimeHealthState::query()
            ->where('user_id', $userId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->first();
    }

    /**
     * @param  callable(AiRuntimeHealthState): void  $mutator
     */
    private function mutateSubject(
        int $userId,
        string $subjectType,
        int $subjectId,
        ?int $connectionId,
        callable $mutator,
    ): void {
        if ($userId <= 0) {
            logger()->warning('AI runtime health skipped: missing owner user_id', [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'api_connection_id' => $connectionId,
            ]);

            return;
        }

        DB::connection($this->connectionName())->transaction(function () use ($userId, $subjectType, $subjectId, $connectionId, $mutator): void {
            $row = AiRuntimeHealthState::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    [
                        'user_id' => $userId,
                        'subject_type' => $subjectType,
                        'subject_id' => $subjectId,
                    ],
                    [
                        'api_connection_id' => $connectionId,
                        'health_status' => AiRuntimeHealthStatus::NoData->value,
                    ],
                );

            if ($connectionId !== null && $row->api_connection_id === null) {
                $row->api_connection_id = $connectionId;
            }

            $mutator($row);
            $row->save();
        });
    }

    private function incrementFailureCounters(
        AiRuntimeHealthState $row,
        ?string $errorCode,
        AiFailureDecision $decision,
        \Illuminate\Support\Carbon $now,
    ): void {
        $row->total_attempts++;
        $row->failure_count++;
        $row->consecutive_failures++;
        $row->last_failure_at = $now;
        $row->last_error_code = $errorCode;
        $row->last_failure_class = $decision->category->value;
        $row->last_failure_message = $decision->safeMessage;

        $counts = is_array($row->failure_counts) ? $row->failure_counts : [];
        if ($errorCode !== null && $errorCode !== '') {
            $counts[$errorCode] = (int) ($counts[$errorCode] ?? 0) + 1;
        }
        // Persist scope/category so legacy RateLimited connection cooldown can be reconciled safely.
        $counts['last_scope'] = $decision->scope->value;
        $counts['last_category'] = $decision->category->value;
        $counts['last_http_status'] = $decision->httpStatus;
        $row->failure_counts = $counts;
    }

    private function bumpScopedConsecutiveCounters(AiRuntimeHealthState $row, AiFailureDecision $decision): void
    {
        $counts = is_array($row->failure_counts) ? $row->failure_counts : [];
        if ($decision->scope === AiFailureScope::Model || $decision->scope === AiFailureScope::System) {
            $row->failure_counts = $counts;

            return;
        }
        if ($decision->lockConnectionPaid || $decision->scope === AiFailureScope::ConnectionPaid) {
            $counts['consecutive_paid_lane'] = (int) ($counts['consecutive_paid_lane'] ?? 0) + 1;
            // Paid-lane failure must not inflate connection breaker.
        } elseif ($decision->lockConnection || $decision->scope === AiFailureScope::Connection) {
            $counts['consecutive_connection'] = (int) ($counts['consecutive_connection'] ?? 0) + 1;
        }
        $row->failure_counts = $counts;
    }

    private function scopedConsecutive(AiRuntimeHealthState $row, string $key): int
    {
        $counts = is_array($row->failure_counts) ? $row->failure_counts : [];

        return (int) ($counts[$key] ?? 0);
    }

    private function statusFromConsecutiveFailures(AiRuntimeHealthState $row): AiRuntimeHealthStatus
    {
        if (AiRoutesExhaustionClassifier::isSoftProviderFailureClass((string) ($row->last_failure_class ?? ''))) {
            return AiRuntimeHealthStatus::Degraded;
        }

        if ($row->consecutive_failures >= self::UNAVAILABLE_THRESHOLD) {
            return AiRuntimeHealthStatus::Unavailable;
        }

        if ($row->consecutive_failures >= self::DEGRADED_THRESHOLD) {
            return AiRuntimeHealthStatus::Degraded;
        }

        return AiRuntimeHealthStatus::Degraded;
    }

    private function statusAfterProviderFailure(AiRuntimeHealthState $row, AiFailureDecision $decision): AiRuntimeHealthStatus
    {
        if (AiRoutesExhaustionClassifier::isSoftProviderFailureClass($decision->category->value)
            || $decision->applyCooldown) {
            return AiRuntimeHealthStatus::Degraded;
        }

        return $this->statusFromConsecutiveFailures($row);
    }

    /**
     * Earliest future cooldown among candidates (model or connection). Null if none.
     *
     * @param  list<RoutedAiCandidate>  $candidates
     */
    public function nextAvailableAt(int $userId, array $candidates): ?\Illuminate\Support\Carbon
    {
        if (! $this->tableReady() || $candidates === []) {
            return null;
        }

        $earliest = null;
        $now = now();
        foreach ($candidates as $candidate) {
            if (! $candidate instanceof RoutedAiCandidate) {
                continue;
            }
            $connectionId = (int) $candidate->connection->id;
            $connectionHealth = $this->findSubject($userId, AiRuntimeHealthState::SUBJECT_CONNECTION, $connectionId);
            if ($connectionHealth !== null && $this->isOnCooldown($connectionHealth) && $connectionHealth->cooldown_until !== null) {
                $until = $connectionHealth->cooldown_until instanceof \Illuminate\Support\Carbon
                    ? $connectionHealth->cooldown_until
                    : \Illuminate\Support\Carbon::parse((string) $connectionHealth->cooldown_until);
                if ($until->gt($now) && ($earliest === null || $until->lt($earliest))) {
                    $earliest = $until->copy();
                }
            }
            $modelId = $candidate->seoAiModelId;
            if ($modelId === null) {
                continue;
            }
            $modelHealth = $this->findSubject($userId, AiRuntimeHealthState::SUBJECT_MODEL, (int) $modelId);
            if ($modelHealth !== null && $this->isOnCooldown($modelHealth) && $modelHealth->cooldown_until !== null) {
                $until = $modelHealth->cooldown_until instanceof \Illuminate\Support\Carbon
                    ? $modelHealth->cooldown_until
                    : \Illuminate\Support\Carbon::parse((string) $modelHealth->cooldown_until);
                if ($until->gt($now) && ($earliest === null || $until->lt($earliest))) {
                    $earliest = $until->copy();
                }
            }
        }

        return $earliest;
    }

    /**
     * @param  list<RoutedAiCandidate>  $candidates
     */
    public function retryAfterSeconds(int $userId, array $candidates, int $minimumSeconds = 10, int $maximumSeconds = 300): ?int
    {
        $at = $this->nextAvailableAt($userId, $candidates);
        if ($at === null) {
            return null;
        }
        $seconds = max(0, (int) now()->diffInSeconds($at, false));

        return max($minimumSeconds, min($maximumSeconds, $seconds));
    }

    private function degradedOrExisting(AiRuntimeHealthState $row): AiRuntimeHealthStatus
    {
        $current = AiRuntimeHealthStatus::tryFrom($row->health_status);
        if ($current === AiRuntimeHealthStatus::ConnectionLocked || $current === AiRuntimeHealthStatus::BudgetLimited) {
            return $current;
        }

        return AiRuntimeHealthStatus::Degraded;
    }

    private function isOnCooldown(AiRuntimeHealthState $row): bool
    {
        return $row->cooldown_until !== null && $row->cooldown_until->isFuture();
    }

    private function cooldownAppliesToConnection(AiFailureDecision $decision): bool
    {
        // Rate limits must never cool down the whole connection — free and paid models
        // often share one OpenRouter key but have different rate-limit buckets.
        if ($decision->category === AiFailureClass::RateLimited) {
            return false;
        }

        // Paid-lane failures must not cooldown the whole connection (free lane stays usable).
        return $decision->scope === AiFailureScope::Connection;
    }

    /**
     * Pre-fix poison: RateLimited was persisted as connection_cooldown.
     * Do not treat as full-connection block; keep valid 401/402/403 locks intact.
     */
    private function isLegacyRateLimitedConnectionCooldown(AiRuntimeHealthState $row): bool
    {
        if ($row->manual_unlock_required
            || $row->paid_locked
            || $row->health_status === AiRuntimeHealthStatus::ConnectionLocked->value
            || $row->health_status === AiRuntimeHealthStatus::BudgetLimited->value) {
            return false;
        }

        $class = (string) ($row->last_failure_class ?? '');
        if ($class === AiFailureClass::RateLimited->value) {
            return true;
        }

        $counts = is_array($row->failure_counts) ? $row->failure_counts : [];
        $category = (string) ($counts['last_category'] ?? '');

        return $category === AiFailureClass::RateLimited->value;
    }

    private function neutralizeLegacyRateLimitedConnectionCooldown(AiRuntimeHealthState $row): void
    {
        if (! $this->isLegacyRateLimitedConnectionCooldown($row)) {
            return;
        }

        $row->cooldown_until = null;
        if ($row->health_status === AiRuntimeHealthStatus::Degraded->value
            || $row->health_status === AiRuntimeHealthStatus::Unavailable->value) {
            $row->health_status = AiRuntimeHealthStatus::Healthy->value;
        }
        $row->save();
    }

    /**
     * @return array{label: string, action: string}|null
     */
    private function connectionAction(AiRuntimeHealthState $row): ?array
    {
        if ($row->health_status === AiRuntimeHealthStatus::ConnectionLocked->value) {
            return ['label' => 'Enable connection', 'action' => 'unlock_connection'];
        }

        if ($row->paid_locked
            || $row->health_status === AiRuntimeHealthStatus::BudgetLimited->value) {
            return ['label' => 'Enable paid routes', 'action' => 'enable_paid_routes'];
        }

        return null;
    }

    private function updateModelLastError(RoutedAiCandidate $candidate, ?AiFailureDecision $decision): void
    {
        if ($candidate->seoAiModelId === null) {
            return;
        }

        $model = SeoAiModel::query()->find($candidate->seoAiModelId);
        if ($model === null) {
            return;
        }

        if ($decision === null) {
            return;
        }

        $model->update([
            'last_error' => mb_substr($decision->safeMessage, 0, 2000),
        ]);
    }

    private function maybeNotifyFailure(
        int $userId,
        AiRuntimeHealthState $row,
        AiRuntimeHealthStatus $previous,
        AiFailureDecision $decision,
        ?RoutedAiCandidate $candidate = null,
    ): void {
        if ($this->notifications === null) {
            return;
        }

        $current = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
        if ($previous === $current) {
            return;
        }

        $this->notifications->onHealthTransition($userId, $row, $previous, $current, $decision, $candidate);
    }

    private function maybeNotifyRecovery(
        int $userId,
        AiRuntimeHealthState $row,
        AiRuntimeHealthStatus $previous,
        ?RoutedAiCandidate $candidate = null,
    ): void {
        if ($this->notifications === null) {
            return;
        }

        $current = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
        if ($previous === $current || ! $this->isRecoverableTransition($previous, $current)) {
            return;
        }

        $this->notifications->onHealthRecovered($userId, $row, $previous, $current, $candidate);
    }

    private function isRecoverableTransition(AiRuntimeHealthStatus $previous, AiRuntimeHealthStatus $current): bool
    {
        if ($current !== AiRuntimeHealthStatus::Healthy) {
            return false;
        }

        return in_array($previous, [
            AiRuntimeHealthStatus::Degraded,
            AiRuntimeHealthStatus::Unavailable,
            AiRuntimeHealthStatus::BudgetLimited,
            AiRuntimeHealthStatus::ConnectionLocked,
        ], true);
    }
}
