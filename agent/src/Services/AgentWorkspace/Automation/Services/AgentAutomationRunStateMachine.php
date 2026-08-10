<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use InvalidArgumentException;

/**
 * Strict run status transitions for Agent Automations.
 */
final class AgentAutomationRunStateMachine
{
    public const PENDING = 'pending';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const WAITING_FOR_APPROVAL = 'waiting_for_approval';

    public const SUCCEEDED = 'succeeded';

    public const NO_CHANGE = 'no_change';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const SKIPPED = 'skipped';

    public const EXPIRED = 'expired';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::PENDING => [self::QUEUED, self::RUNNING, self::SKIPPED, self::CANCELLED],
        self::QUEUED => [self::RUNNING, self::SKIPPED, self::CANCELLED, self::FAILED],
        self::RUNNING => [
            self::WAITING_FOR_APPROVAL,
            self::SUCCEEDED,
            self::NO_CHANGE,
            self::FAILED,
            self::CANCELLED,
            self::SKIPPED,
        ],
        self::WAITING_FOR_APPROVAL => [
            self::RUNNING,
            self::SUCCEEDED,
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
        ],
        self::SUCCEEDED => [],
        self::NO_CHANGE => [],
        self::FAILED => [self::QUEUED], // retry requeues new attempt identity retained on same row
        self::CANCELLED => [],
        self::SKIPPED => [],
        self::EXPIRED => [],
    ];

    public function assertCanTransition(string $from, string $to): void
    {
        $allowed = self::TRANSITIONS[$from] ?? null;
        if ($allowed === null) {
            throw new InvalidArgumentException('unknown_run_status:'.$from);
        }
        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException('invalid_run_transition:'.$from.'->'.$to);
        }
    }

    public function isTerminal(string $status): bool
    {
        return in_array($status, [
            self::SUCCEEDED,
            self::NO_CHANGE,
            self::FAILED,
            self::CANCELLED,
            self::SKIPPED,
            self::EXPIRED,
        ], true) && $status !== self::FAILED;
    }

    public function isTerminalStrict(string $status): bool
    {
        return in_array($status, [
            self::SUCCEEDED,
            self::NO_CHANGE,
            self::CANCELLED,
            self::SKIPPED,
            self::EXPIRED,
        ], true);
    }

    /** @return list<string> */
    public function allStatuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }
}
