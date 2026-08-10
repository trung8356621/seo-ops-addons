<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution;

use Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentExecutionStatus;
use InvalidArgumentException;

/**
 * Allowed status transitions for seo_agent_executions.
 * UI must never set status directly — only Orchestrator + this machine.
 */
final class AgentExecutionStateMachine
{
    /**
     * @return array<string, list<string>>
     */
    public static function allowedMap(): array
    {
        return [
            AgentExecutionStatus::Draft->value => [
                AgentExecutionStatus::Validating->value,
                AgentExecutionStatus::Cancelled->value,
                AgentExecutionStatus::Expired->value,
            ],
            AgentExecutionStatus::Validating->value => [
                AgentExecutionStatus::Ready->value,
                AgentExecutionStatus::AwaitingConfirmation->value,
                AgentExecutionStatus::Failed->value,
                AgentExecutionStatus::Cancelled->value,
            ],
            AgentExecutionStatus::Ready->value => [
                AgentExecutionStatus::Queued->value,
                AgentExecutionStatus::Running->value,
                AgentExecutionStatus::Cancelled->value,
                AgentExecutionStatus::Expired->value,
            ],
            AgentExecutionStatus::AwaitingConfirmation->value => [
                AgentExecutionStatus::Ready->value,
                AgentExecutionStatus::Queued->value,
                AgentExecutionStatus::Running->value,
                AgentExecutionStatus::Cancelled->value,
                AgentExecutionStatus::Expired->value,
            ],
            AgentExecutionStatus::Queued->value => [
                AgentExecutionStatus::Running->value,
                AgentExecutionStatus::Cancelled->value,
                AgentExecutionStatus::Failed->value,
            ],
            AgentExecutionStatus::Running->value => [
                AgentExecutionStatus::Succeeded->value,
                AgentExecutionStatus::Failed->value,
                AgentExecutionStatus::Cancelled->value,
            ],
            AgentExecutionStatus::Succeeded->value => [],
            AgentExecutionStatus::Failed->value => [],
            AgentExecutionStatus::Cancelled->value => [],
            AgentExecutionStatus::Expired->value => [],
        ];
    }

    public function canTransition(AgentExecutionStatus $from, AgentExecutionStatus $to): bool
    {
        $allowed = self::allowedMap()[$from->value] ?? [];

        return in_array($to->value, $allowed, true);
    }

    public function assertTransition(AgentExecutionStatus $from, AgentExecutionStatus $to): void
    {
        if ($this->canTransition($from, $to)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'agent.execution.invalid_transition:%s->%s',
            $from->value,
            $to->value,
        ));
    }

    public function assertNotTerminal(AgentExecutionStatus $status): void
    {
        if ($status->isTerminal()) {
            throw new InvalidArgumentException('agent.execution.terminal:'.$status->value);
        }
    }
}
