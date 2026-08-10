<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Enums\AgentWorkspace;

/**
 * Canonical Agent Workspace execution statuses (Phase 2).
 * DB may still hold legacy pending/completed — map via fromStorage().
 */
enum AgentExecutionStatus: string
{
    case Draft = 'draft';
    case Validating = 'validating';
    case Ready = 'ready';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Failed,
            self::Cancelled,
            self::Expired,
        ], true);
    }

    public function isCancellableWithoutGateway(): bool
    {
        return in_array($this, [
            self::Draft,
            self::Validating,
            self::Ready,
            self::AwaitingConfirmation,
            self::Queued,
        ], true);
    }

    /**
     * Map storage / legacy Phase 1 values onto canonical statuses.
     */
    public static function fromStorage(string $raw): self
    {
        $normalized = strtolower(trim($raw));

        return match ($normalized) {
            'pending' => self::Draft,
            'completed', 'success', 'ok' => self::Succeeded,
            'complete' => self::Succeeded,
            default => self::tryFrom($normalized) ?? self::Failed,
        };
    }

    public function toStorage(): string
    {
        return $this->value;
    }
}
