<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
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
        if (! $this->tableReady()) {
            return null;
        }

        $connectionId = (int) $candidate->connection->id;
        $connectionHealth = $this->findSubject($userId, AiRuntimeHealthState::SUBJECT_CONNECTION, $connectionId);

        if ($connectionHealth !== null) {
            if ($connectionHealth->health_status === AiRuntimeHealthStatus::ConnectionLocked->value) {
                return 'connection_locked';
            }

            if ($candidate->isFree === false
                && $connectionHealth->paid_locked
                && $connectionHealth->health_status === AiRuntimeHealthStatus::BudgetLimited->value) {
                return 'connection_paid_locked';
            }

            if ($this->isOnCooldown($connectionHealth)) {
                return 'connection_cooldown';
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
                    if ($row->health_status !== AiRuntimeHealthStatus::Unavailable->value) {
                        $row->health_status = AiRuntimeHealthStatus::Healthy->value;
                        $row->cooldown_until = null;
                    }
                    $this->updateModelLastError($candidate, null);
                    $this->maybeNotifyRecovery($userId, $row, $previous, $candidate);
                },
            );
        }
    }

    public function recordFailure(int $userId, RoutedAiCandidate $candidate, AiFailureDecision $decision): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $now = now();
        $connectionId = (int) $candidate->connection->id;
        $errorCode = $decision->errorCode ?? ($decision->httpStatus !== null ? (string) $decision->httpStatus : null);

        $this->mutateSubject($userId, AiRuntimeHealthState::SUBJECT_CONNECTION, $connectionId, $connectionId, function (AiRuntimeHealthState $row) use ($decision, $now, $errorCode, $userId): void {
            $previous = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
            $this->incrementFailureCounters($row, $errorCode, $decision, $now);

            if ($decision->lockConnection) {
                $row->health_status = AiRuntimeHealthStatus::ConnectionLocked->value;
                $row->manual_unlock_required = true;
            } elseif ($decision->lockConnectionPaid) {
                $row->health_status = AiRuntimeHealthStatus::BudgetLimited->value;
                $row->paid_locked = true;
                $row->manual_unlock_required = true;
            } elseif ($decision->applyCooldown) {
                $row->cooldown_until = now()->addMinutes(self::COOLDOWN_MINUTES);
                $row->health_status = $this->degradedOrExisting($row)->value;
            }

            $this->maybeNotifyFailure($userId, $row, $previous, $decision);
        });

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
                        $row->health_status = $this->statusFromConsecutiveFailures($row)->value;
                    } else {
                        $row->health_status = $this->statusFromConsecutiveFailures($row)->value;
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
        $row->paid_locked = false;
        $row->cooldown_until = null;
        $row->save();
        $this->maybeNotifyRecovery($userId, $row, $previous);
    }

    public function enablePaidRoutes(int $userId, int $connectionId): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $row = $this->findSubject($userId, AiRuntimeHealthState::SUBJECT_CONNECTION, $connectionId);
        if ($row === null) {
            return;
        }

        $previous = AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData;
        $row->paid_locked = false;
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
        $status = $row !== null
            ? (AiRuntimeHealthStatus::tryFrom($row->health_status) ?? AiRuntimeHealthStatus::NoData)
            : AiRuntimeHealthStatus::NoData;

        return [
            'connection_id' => $id,
            'connection_name' => $connection !== null ? (string) $connection->name : '#'.$id,
            'provider' => $connection !== null ? (string) $connection->provider : '',
            'health_status' => $status->value,
            'health_label' => $status->label(),
            'paid_locked' => (bool) ($row?->paid_locked ?? false),
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
            return Schema::connection(self::CONNECTION_NAME)->hasTable('ai_runtime_health_states');
        } catch (\Throwable) {
            return false;
        }
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

        DB::connection(self::CONNECTION_NAME)->transaction(function () use ($userId, $subjectType, $subjectId, $connectionId, $mutator): void {
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
        $row->failure_counts = $counts;
    }

    private function statusFromConsecutiveFailures(AiRuntimeHealthState $row): AiRuntimeHealthStatus
    {
        if ($row->consecutive_failures >= self::UNAVAILABLE_THRESHOLD) {
            return AiRuntimeHealthStatus::Unavailable;
        }

        if ($row->consecutive_failures >= self::DEGRADED_THRESHOLD) {
            return AiRuntimeHealthStatus::Degraded;
        }

        return AiRuntimeHealthStatus::Degraded;
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

    /**
     * @return array{label: string, action: string}|null
     */
    private function connectionAction(AiRuntimeHealthState $row): ?array
    {
        if ($row->health_status === AiRuntimeHealthStatus::ConnectionLocked->value) {
            return ['label' => 'Enable connection', 'action' => 'unlock_connection'];
        }

        if ($row->paid_locked) {
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
