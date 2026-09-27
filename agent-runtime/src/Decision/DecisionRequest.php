<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;

final readonly class DecisionRequest
{
    public function __construct(
        public int $userId,
        public PreparedModelInput $input,
    ) {}
}
