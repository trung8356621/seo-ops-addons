<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Immutable runtime status of one Content Project article row (ops UI SoT).
 *
 * `isActive` is the only signal allowed to paint a live "Đang tạo" state — it
 * requires positive dispatch evidence, never a sticky task.status alone.
 */
final class ContentProjectArticleRuntimeStatus
{
    public const STATE_ACTIVELY_PROCESSING = 'actively_processing';

    public const STATE_QUEUED = 'queued';

    public const STATE_WAITING_AI_RETRY = 'waiting_ai_retry';

    public const STATE_STALE_PROCESSING = 'stale_processing';

    public const STATE_INCONSISTENT_PROCESSING = 'inconsistent_processing';

    public const STATE_FAILED = 'failed';

    public const STATE_COMPLETED = 'completed';

    public const STATE_PENDING = 'pending';

    public const STATE_NO_ACTIVE_EXECUTION = 'no_active_execution';

    public const STEP_OUTLINE = 'Outline';

    public const STEP_VOCABULARY = 'Vocabulary';

    public const STEP_WRITING = 'Writing';

    public function __construct(
        public readonly string $state,
        public readonly string $label,
        public readonly string $tone,
        public readonly bool $isActive,
        public readonly bool $showSpinner,
        public readonly ?string $detail = null,
        public readonly ?string $stepLabel = null,
        public readonly ?int $attempt = null,
        public readonly ?int $maxAttempts = null,
        public readonly ?int $heartbeatAgeSeconds = null,
        public readonly ?string $retryAt = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $timeLabel = null,
        public readonly ?string $warning = null,
    ) {}

    /**
     * Non-terminal runtime — the ops page must keep polling while any row matches.
     */
    public function isLive(): bool
    {
        return in_array($this->state, [
            self::STATE_ACTIVELY_PROCESSING,
            self::STATE_QUEUED,
            self::STATE_WAITING_AI_RETRY,
        ], true);
    }

    public function needsAttention(): bool
    {
        return in_array($this->state, [
            self::STATE_STALE_PROCESSING,
            self::STATE_INCONSISTENT_PROCESSING,
        ], true);
    }

    /**
     * @return array{
     *     state: string,
     *     label: string,
     *     tone: string,
     *     is_active: bool,
     *     show_spinner: bool,
     *     detail: string|null,
     *     step_label: string|null,
     *     attempt: int|null,
     *     max_attempts: int|null,
     *     heartbeat_age_seconds: int|null,
     *     retry_at: string|null,
     *     retry_after_seconds: int|null,
     *     time_label: string|null,
     *     warning: string|null,
     *     is_live: bool,
     *     needs_attention: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'label' => $this->label,
            'tone' => $this->tone,
            'is_active' => $this->isActive,
            'show_spinner' => $this->showSpinner,
            'detail' => $this->detail,
            'step_label' => $this->stepLabel,
            'attempt' => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'heartbeat_age_seconds' => $this->heartbeatAgeSeconds,
            'retry_at' => $this->retryAt,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'time_label' => $this->timeLabel,
            'warning' => $this->warning,
            'is_live' => $this->isLive(),
            'needs_attention' => $this->needsAttention(),
        ];
    }
}
