<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRuntimeStatus;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectTransientAiRetryPolicy;

/**
 * Single source of truth for "is this Content Project item actually running right now?".
 *
 * Pure evaluator over a snapshot array — no DB, no container, unit-testable.
 * A row is only `actively_processing` when the run engine can prove a live worker:
 * non-terminal run + processing run-item + matching active_dispatch (claimed, alive
 * heartbeat). Everything else degrades to queued / waiting / stale / terminal so the
 * ops list stops painting stale "Đang chạy" badges on merely pending work.
 */
final class ContentProjectArticleRuntimeStatusResolver
{
    public const LOG_INCONSISTENT = 'content_project.runtime_state_inconsistent';

    /** Fallback when the run engine feature helper cannot read config. */
    private const DEFAULT_HEARTBEAT_STALE_SECONDS = 20 * 60;

    private const TERMINAL_EXEC_STATUSES = ['failed', 'error', 'cancelled', 'stopped', 'timeout'];

    private const SUCCESS_EXEC_STATUSES = ['success', 'completed'];

    /**
     * @param  array{
     *     run_id?: int|null,
     *     run_status?: string|null,
     *     run_item?: array<string, mixed>|null,
     *     active_dispatch?: array<string, mixed>|null,
     *     ai_transient_retry?: array<string, mixed>|null,
     *     processing_count?: int,
     *     task_status?: string|null,
     *     has_dispatch_tracking?: bool,
     *     legacy_fresh_execution?: bool,
     *     is_generation_stale?: bool,
     *     heartbeat_stale_seconds?: int|null,
     *     now?: CarbonInterface|null,
     * }  $context
     */
    public function resolve(array $context): ContentProjectArticleRuntimeStatus
    {
        $nowCandidate = $context['now'] ?? null;
        $now = $nowCandidate instanceof CarbonInterface ? $nowCandidate : Carbon::now();
        $item = is_array($context['run_item'] ?? null) ? $context['run_item'] : null;
        $execStatus = strtolower(trim((string) ($item['status'] ?? '')));
        $attempt = isset($item['attempt']) ? max(0, (int) $item['attempt']) : null;
        $maxAttempts = ContentProjectTransientAiRetryPolicy::MAX_TRANSIENT_ARTICLE_ATTEMPTS;
        $stepLabel = self::stepLabel($item['action'] ?? null);

        // Terminal attempt always beats a sticky task.status = writing/processing.
        if (in_array($execStatus, self::SUCCESS_EXEC_STATUSES, true)) {
            return $this->terminal(
                ContentProjectArticleRuntimeStatus::STATE_COMPLETED,
                'Đã tạo xong',
                'success',
                $attempt,
                $maxAttempts,
            );
        }
        if (in_array($execStatus, self::TERMINAL_EXEC_STATUSES, true)) {
            return $this->terminal(
                ContentProjectArticleRuntimeStatus::STATE_FAILED,
                'Lỗi',
                'danger',
                $attempt,
                $maxAttempts,
            );
        }

        $dispatch = $this->matchingDispatch($context, $item);
        $heartbeatAge = $dispatch !== null
            ? self::ageSeconds($dispatch['last_heartbeat_at'] ?? $dispatch['dispatched_at'] ?? null, $now)
            : null;
        $heartbeatAlive = $heartbeatAge !== null && $heartbeatAge < $this->heartbeatStaleSeconds($context);
        $currentStep = strtolower(trim((string) ($dispatch['current_step'] ?? '')));
        $dispatchStepLabel = self::stepLabel($dispatch['current_step'] ?? null);
        $stepLabel = $dispatchStepLabel ?? $stepLabel;

        if ($execStatus === 'processing') {
            return $this->resolveProcessing(
                $context,
                $now,
                $dispatch,
                $heartbeatAge,
                $heartbeatAlive,
                $stepLabel,
                $attempt,
                $maxAttempts,
                $item,
            );
        }

        if ($execStatus === 'pending') {
            return $this->resolvePending(
                $context,
                $now,
                $dispatch,
                $currentStep,
                $heartbeatAge,
                $stepLabel,
                $attempt,
                $maxAttempts,
                $item,
            );
        }

        return $this->resolveWithoutExecution($context, $attempt, $maxAttempts);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $dispatch
     * @param  array<string, mixed>|null  $item
     */
    private function resolveProcessing(
        array $context,
        CarbonInterface $now,
        ?array $dispatch,
        ?int $heartbeatAge,
        bool $heartbeatAlive,
        ?string $stepLabel,
        ?int $attempt,
        int $maxAttempts,
        ?array $item,
    ): ContentProjectArticleRuntimeStatus {
        $processingCount = max(0, (int) ($context['processing_count'] ?? 0));
        $runTerminal = $this->runIsTerminal($context);
        $processingAge = self::ageSeconds($item['started_at_iso'] ?? null, $now);

        if ($dispatch === null) {
            // Another row owns the reservation while this one still claims processing.
            $hasForeignDispatch = is_array($context['active_dispatch'] ?? null);
            $state = ($hasForeignDispatch || $processingCount > 1)
                ? ContentProjectArticleRuntimeStatus::STATE_INCONSISTENT_PROCESSING
                : ContentProjectArticleRuntimeStatus::STATE_STALE_PROCESSING;

            // Legacy orchestration has no active_dispatch bookkeeping at all — trust
            // the staleness policy instead of painting every legacy row as stuck.
            if (
                $state === ContentProjectArticleRuntimeStatus::STATE_STALE_PROCESSING
                && ($context['has_dispatch_tracking'] ?? true) === false
                && ! empty($context['legacy_fresh_execution'])
                && empty($context['is_generation_stale'])
                && ! $runTerminal
            ) {
                return $this->active($stepLabel, $attempt, $maxAttempts, null, $processingAge);
            }

            return $this->stuck($state, $stepLabel, $attempt, $maxAttempts, $heartbeatAge, $processingAge, $state === ContentProjectArticleRuntimeStatus::STATE_INCONSISTENT_PROCESSING
                ? 'Nhiều item đang processing — chỉ một item có dispatch hợp lệ'
                : 'Không tìm thấy dispatch đang giữ item này');
        }

        if ($runTerminal) {
            return $this->stuck(
                ContentProjectArticleRuntimeStatus::STATE_STALE_PROCESSING,
                $stepLabel,
                $attempt,
                $maxAttempts,
                $heartbeatAge,
                $processingAge,
                'Run đã kết thúc nhưng item vẫn processing',
            );
        }

        if (empty($dispatch['claimed_at'])) {
            return $this->stuck(
                ContentProjectArticleRuntimeStatus::STATE_STALE_PROCESSING,
                $stepLabel,
                $attempt,
                $maxAttempts,
                $heartbeatAge,
                $processingAge,
                'Worker chưa xác nhận nhận job',
            );
        }

        if (! $heartbeatAlive) {
            return $this->stuck(
                ContentProjectArticleRuntimeStatus::STATE_STALE_PROCESSING,
                $stepLabel,
                $attempt,
                $maxAttempts,
                $heartbeatAge,
                $processingAge,
                'Heartbeat quá hạn',
            );
        }

        return $this->active($stepLabel, $attempt, $maxAttempts, $heartbeatAge, $processingAge);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $dispatch
     * @param  array<string, mixed>|null  $item
     */
    private function resolvePending(
        array $context,
        CarbonInterface $now,
        ?array $dispatch,
        string $currentStep,
        ?int $heartbeatAge,
        ?string $stepLabel,
        ?int $attempt,
        int $maxAttempts,
        ?array $item,
    ): ContentProjectArticleRuntimeStatus {
        $retry = $this->matchingAiRetry($context, $item);
        $message = trim((string) ($item['message'] ?? ''));
        $looksLikeAiWait = $message !== '' && mb_stripos($message, 'Đang chờ AI') !== false;

        if ($retry !== null || $currentStep === 'ai_retry_delayed' || $looksLikeAiWait) {
            $delay = null;
            if ($retry !== null && (int) ($retry['delay_seconds'] ?? 0) > 0) {
                $delay = (int) $retry['delay_seconds'];
            }
            $scheduledAt = $retry !== null ? ($retry['scheduled_at'] ?? null) : ($dispatch['dispatched_at'] ?? null);
            $retryAt = null;
            $retryAfter = null;
            if (is_string($scheduledAt) && trim($scheduledAt) !== '' && $delay !== null) {
                $parsed = self::parse($scheduledAt);
                if ($parsed !== null) {
                    $retryAtCarbon = $parsed->copy()->addSeconds($delay);
                    $retryAt = $retryAtCarbon->toIso8601String();
                    $retryAfter = max(0, (int) $now->diffInSeconds($retryAtCarbon, false));
                }
            }
            if ($retryAfter === null && $delay !== null) {
                $retryAfter = $delay;
            }

            $attemptForRetry = $retry !== null && (int) ($retry['attempt'] ?? 0) > 0
                ? (int) $retry['attempt']
                : $attempt;
            $retryStep = self::stepLabel($retry['failed_hook'] ?? null)
                ?? self::stepLabel($currentStep)
                ?? $stepLabel;

            return new ContentProjectArticleRuntimeStatus(
                state: ContentProjectArticleRuntimeStatus::STATE_WAITING_AI_RETRY,
                label: 'Chờ AI',
                tone: 'warning',
                isActive: false,
                showSpinner: false,
                detail: self::detail($retryStep, $attemptForRetry, $maxAttempts, $retryAfter !== null
                    ? 'thử lại sau '.self::humanizeDuration($retryAfter)
                    : null),
                stepLabel: $retryStep,
                attempt: $attemptForRetry,
                maxAttempts: $maxAttempts,
                heartbeatAgeSeconds: $heartbeatAge,
                retryAt: $retryAt,
                retryAfterSeconds: $retryAfter,
                timeLabel: $retryAfter !== null
                    ? 'Thử lại sau ~'.self::humanizeRetryDelay($retryAfter)
                    : 'Đang chờ AI',
            );
        }

        if ($dispatch !== null && empty($dispatch['claimed_at'])) {
            $waitAge = self::ageSeconds($dispatch['dispatched_at'] ?? null, $now);

            return new ContentProjectArticleRuntimeStatus(
                state: ContentProjectArticleRuntimeStatus::STATE_QUEUED,
                label: 'Đang chờ worker',
                tone: 'info',
                isActive: false,
                showSpinner: false,
                detail: self::detail($stepLabel, $attempt, $maxAttempts, $waitAge !== null
                    ? 'chờ '.self::humanizeDuration($waitAge)
                    : null),
                stepLabel: $stepLabel,
                attempt: $attempt,
                maxAttempts: $maxAttempts,
                heartbeatAgeSeconds: $heartbeatAge,
                timeLabel: $waitAge !== null
                    ? 'Đang chờ worker '.self::humanizeDuration($waitAge)
                    : 'Đang chờ worker',
            );
        }

        if ($dispatch !== null) {
            // Reservation claimed but item bounced back to pending — treat as queued, not running.
            return new ContentProjectArticleRuntimeStatus(
                state: ContentProjectArticleRuntimeStatus::STATE_QUEUED,
                label: 'Đang chờ worker',
                tone: 'info',
                isActive: false,
                showSpinner: false,
                detail: self::detail($stepLabel, $attempt, $maxAttempts, null),
                stepLabel: $stepLabel,
                attempt: $attempt,
                maxAttempts: $maxAttempts,
                heartbeatAgeSeconds: $heartbeatAge,
                timeLabel: 'Đang chờ worker',
            );
        }

        // No reservation: circuit breaker / stopped run leftovers stay plain pending.
        return new ContentProjectArticleRuntimeStatus(
            state: ContentProjectArticleRuntimeStatus::STATE_PENDING,
            label: 'Chờ chạy',
            tone: 'gray',
            isActive: false,
            showSpinner: false,
            detail: null,
            stepLabel: null,
            attempt: $attempt,
            maxAttempts: $maxAttempts,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveWithoutExecution(array $context, ?int $attempt, int $maxAttempts): ContentProjectArticleRuntimeStatus
    {
        $taskStatus = strtolower(trim((string) ($context['task_status'] ?? '')));

        if (in_array($taskStatus, ['completed', 'reviewing'], true)) {
            return $this->terminal(
                ContentProjectArticleRuntimeStatus::STATE_COMPLETED,
                'Đã tạo xong',
                'success',
                $attempt,
                $maxAttempts,
            );
        }
        if ($taskStatus === 'failed') {
            return $this->terminal(
                ContentProjectArticleRuntimeStatus::STATE_FAILED,
                'Lỗi',
                'danger',
                $attempt,
                $maxAttempts,
            );
        }

        // task.status = writing without any execution evidence is exactly the stale
        // "Đang chạy" bug — never report it as running.
        return new ContentProjectArticleRuntimeStatus(
            state: ContentProjectArticleRuntimeStatus::STATE_NO_ACTIVE_EXECUTION,
            label: 'Chưa chạy',
            tone: 'gray',
            isActive: false,
            showSpinner: false,
            attempt: $attempt,
            maxAttempts: $maxAttempts,
        );
    }

    private function active(
        ?string $stepLabel,
        ?int $attempt,
        int $maxAttempts,
        ?int $heartbeatAge,
        ?int $processingAge,
    ): ContentProjectArticleRuntimeStatus {
        $timeLabel = $processingAge !== null
            ? 'Đang xử lý '.self::humanizeDuration($processingAge)
            : ($heartbeatAge !== null ? 'Heartbeat '.self::humanizeDuration($heartbeatAge).' trước' : 'Đang xử lý');

        return new ContentProjectArticleRuntimeStatus(
            state: ContentProjectArticleRuntimeStatus::STATE_ACTIVELY_PROCESSING,
            label: 'Đang tạo',
            tone: 'info',
            isActive: true,
            showSpinner: true,
            detail: self::detail($stepLabel, $attempt, $maxAttempts, $processingAge !== null
                ? self::humanizeDuration($processingAge)
                : null),
            stepLabel: $stepLabel,
            attempt: $attempt,
            maxAttempts: $maxAttempts,
            heartbeatAgeSeconds: $heartbeatAge,
            timeLabel: $timeLabel,
        );
    }

    private function stuck(
        string $state,
        ?string $stepLabel,
        ?int $attempt,
        int $maxAttempts,
        ?int $heartbeatAge,
        ?int $processingAge,
        string $warning,
    ): ContentProjectArticleRuntimeStatus {
        $age = $heartbeatAge ?? $processingAge;

        return new ContentProjectArticleRuntimeStatus(
            state: $state,
            label: 'Có thể bị kẹt',
            tone: 'warning',
            isActive: false,
            showSpinner: false,
            detail: self::detail($stepLabel, $attempt, $maxAttempts, $age !== null
                ? 'không phản hồi '.self::humanizeDuration($age)
                : null),
            stepLabel: $stepLabel,
            attempt: $attempt,
            maxAttempts: $maxAttempts,
            heartbeatAgeSeconds: $heartbeatAge,
            timeLabel: $age !== null ? 'Không phản hồi '.self::humanizeDuration($age) : null,
            warning: $warning,
        );
    }

    private function terminal(
        string $state,
        string $label,
        string $tone,
        ?int $attempt,
        int $maxAttempts,
    ): ContentProjectArticleRuntimeStatus {
        return new ContentProjectArticleRuntimeStatus(
            state: $state,
            label: $label,
            tone: $tone,
            isActive: false,
            showSpinner: false,
            attempt: $attempt,
            maxAttempts: $maxAttempts,
        );
    }

    /**
     * Reservation that provably belongs to this run-item.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $item
     * @return array<string, mixed>|null
     */
    private function matchingDispatch(array $context, ?array $item): ?array
    {
        $dispatch = is_array($context['active_dispatch'] ?? null) ? $context['active_dispatch'] : null;
        if ($dispatch === null || $item === null) {
            return null;
        }

        $itemId = (int) ($item['id'] ?? 0);
        if ($itemId <= 0 || (int) ($dispatch['run_item_id'] ?? 0) !== $itemId) {
            return null;
        }

        $runId = (int) ($context['run_id'] ?? 0);
        $itemRunId = (int) ($item['run_id'] ?? 0);
        if ($runId > 0 && $itemRunId > 0 && $runId !== $itemRunId) {
            return null;
        }

        // Token check only when the row actually carries one; run_item_id match otherwise.
        $itemToken = trim((string) ($item['dispatch_token'] ?? ''));
        $dispatchToken = trim((string) ($dispatch['token'] ?? ''));
        if ($itemToken !== '' && $dispatchToken !== '' && ! hash_equals($dispatchToken, $itemToken)) {
            return null;
        }

        return $dispatch;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $item
     * @return array<string, mixed>|null
     */
    private function matchingAiRetry(array $context, ?array $item): ?array
    {
        $retry = is_array($context['ai_transient_retry'] ?? null) ? $context['ai_transient_retry'] : null;
        if ($retry === null || $item === null) {
            return null;
        }

        $itemId = (int) ($item['id'] ?? 0);
        if ($itemId > 0 && (int) ($retry['run_item_id'] ?? 0) === $itemId) {
            return $retry;
        }

        $taskId = (int) ($item['task_id'] ?? 0);
        if ($taskId > 0 && (int) ($retry['task_id'] ?? 0) === $taskId) {
            return $retry;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function runIsTerminal(array $context): bool
    {
        if (array_key_exists('run_terminal', $context) && is_bool($context['run_terminal'])) {
            return $context['run_terminal'];
        }

        $status = strtolower(trim((string) ($context['run_status'] ?? '')));
        if ($status === '') {
            return false;
        }

        return in_array($status, [
            SeoProjectRun::STATUS_COMPLETED,
            SeoProjectRun::STATUS_CANCELLED,
            SeoProjectRun::STATUS_FAILED,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function heartbeatStaleSeconds(array $context): int
    {
        $override = (int) ($context['heartbeat_stale_seconds'] ?? 0);
        if ($override > 0) {
            return $override;
        }

        try {
            return max(60, ContentProjectRunEngineFeature::heartbeatStaleMinutes() * 60);
        } catch (\Throwable) {
            return self::DEFAULT_HEARTBEAT_STALE_SECONDS;
        }
    }

    /**
     * Technical hook / dispatch step → operator-facing step name.
     */
    public static function stepLabel(mixed $raw): ?string
    {
        $value = strtolower(trim((string) ($raw ?? '')));
        if ($value === '') {
            return null;
        }
        // Generic dispatch phases carry no step meaning — caller falls back to the hook.
        // Note: ai_retry_delayed may be replaced by a concrete failed_hook on the dispatch.
        if (in_array($value, ['queued', 'ai_retry_delayed', 'claimed', 'running_article', 'article_finished', 'post_process', 'completed'], true)) {
            return null;
        }
        if (str_contains($value, 'outline')) {
            return ContentProjectArticleRuntimeStatus::STEP_OUTLINE;
        }
        if (str_contains($value, 'vocabulary')) {
            return ContentProjectArticleRuntimeStatus::STEP_VOCABULARY;
        }
        if (
            str_contains($value, 'content')
            || str_contains($value, 'rewrite')
            || str_contains($value, 'writing')
        ) {
            return ContentProjectArticleRuntimeStatus::STEP_WRITING;
        }

        return null;
    }

    /**
     * Emit a diagnostic when several rows claim processing while one reservation exists.
     *
     * @param  array<string, mixed>  $context
     */
    public static function logInconsistentEvidence(array $context): void
    {
        $processingCount = max(0, (int) ($context['processing_count'] ?? 0));
        if ($processingCount <= 1) {
            return;
        }

        $payload = [
            'run_id' => (int) ($context['run_id'] ?? 0),
            'processing_count' => $processingCount,
            'active_run_item_id' => (int) (($context['active_dispatch'] ?? [])['run_item_id'] ?? 0),
        ];

        try {
            if (class_exists(\App\Support\RuntimeLogger::class)) {
                \App\Support\RuntimeLogger::warning(self::LOG_INCONSISTENT, $payload);

                return;
            }
            if (function_exists('logger')) {
                logger()->warning(self::LOG_INCONSISTENT, $payload);
            }
        } catch (\Throwable) {
            // Diagnostics must never break the ops read-model.
        }
    }

    private static function detail(?string $stepLabel, ?int $attempt, int $maxAttempts, ?string $age): ?string
    {
        $parts = [];
        if ($stepLabel !== null && $stepLabel !== '') {
            $parts[] = $stepLabel;
        }
        if ($attempt !== null && $attempt > 1) {
            $parts[] = 'lần '.$attempt.'/'.$maxAttempts;
        }
        if ($age !== null && $age !== '') {
            $parts[] = $age;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function humanizeDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).' phút';
        }

        return intdiv($seconds, 3600).' giờ';
    }

    private static function humanizeRetryDelay(int $seconds): string
    {
        return max(1, (int) ceil(max(0, $seconds) / 60)).' phút';
    }

    private static function ageSeconds(mixed $iso, CarbonInterface $now): ?int
    {
        $parsed = self::parse($iso);
        if ($parsed === null) {
            return null;
        }

        return max(0, (int) $parsed->diffInSeconds($now, false));
    }

    private static function parse(mixed $iso): ?Carbon
    {
        if ($iso instanceof CarbonInterface) {
            return Carbon::instance($iso);
        }
        if (! is_string($iso) || trim($iso) === '') {
            return null;
        }

        try {
            return Carbon::parse($iso);
        } catch (\Throwable) {
            return null;
        }
    }
}
