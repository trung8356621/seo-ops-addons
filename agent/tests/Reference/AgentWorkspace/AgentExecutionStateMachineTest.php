<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentExecutionStatus;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionStateMachine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AgentExecutionStateMachineTest extends TestCase
{
    private AgentExecutionStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new AgentExecutionStateMachine;
    }

    public function test_valid_transitions_pass(): void
    {
        self::assertTrue($this->machine->canTransition(AgentExecutionStatus::Draft, AgentExecutionStatus::Validating));
        self::assertTrue($this->machine->canTransition(AgentExecutionStatus::Validating, AgentExecutionStatus::Ready));
        self::assertTrue($this->machine->canTransition(AgentExecutionStatus::Validating, AgentExecutionStatus::AwaitingConfirmation));
        self::assertTrue($this->machine->canTransition(AgentExecutionStatus::AwaitingConfirmation, AgentExecutionStatus::Running));
        self::assertTrue($this->machine->canTransition(AgentExecutionStatus::Running, AgentExecutionStatus::Succeeded));
        self::assertTrue($this->machine->canTransition(AgentExecutionStatus::Running, AgentExecutionStatus::Failed));
        self::assertTrue($this->machine->canTransition(AgentExecutionStatus::Ready, AgentExecutionStatus::Cancelled));
    }

    public function test_invalid_transitions_rejected(): void
    {
        self::assertFalse($this->machine->canTransition(AgentExecutionStatus::Succeeded, AgentExecutionStatus::Running));
        self::assertFalse($this->machine->canTransition(AgentExecutionStatus::Failed, AgentExecutionStatus::Running));
        self::assertFalse($this->machine->canTransition(AgentExecutionStatus::Draft, AgentExecutionStatus::Succeeded));

        $this->expectException(InvalidArgumentException::class);
        $this->machine->assertTransition(AgentExecutionStatus::Failed, AgentExecutionStatus::Running);
    }

    public function test_terminal_states_cannot_reexecute(): void
    {
        foreach ([
            AgentExecutionStatus::Succeeded,
            AgentExecutionStatus::Failed,
            AgentExecutionStatus::Cancelled,
            AgentExecutionStatus::Expired,
        ] as $status) {
            self::assertTrue($status->isTerminal());
            try {
                $this->machine->assertNotTerminal($status);
                self::fail('Expected InvalidArgumentException for '.$status->value);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_legacy_storage_mapping(): void
    {
        self::assertSame(AgentExecutionStatus::Draft, AgentExecutionStatus::fromStorage('pending'));
        self::assertSame(AgentExecutionStatus::Succeeded, AgentExecutionStatus::fromStorage('completed'));
        self::assertSame(AgentExecutionStatus::Running, AgentExecutionStatus::fromStorage('running'));
    }

    public function test_cancellable_without_gateway(): void
    {
        self::assertTrue(AgentExecutionStatus::Draft->isCancellableWithoutGateway());
        self::assertTrue(AgentExecutionStatus::AwaitingConfirmation->isCancellableWithoutGateway());
        self::assertFalse(AgentExecutionStatus::Running->isCancellableWithoutGateway());
    }
}
