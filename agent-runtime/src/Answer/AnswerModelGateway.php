<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Answer;

use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;

interface AnswerModelGateway
{
    public function complete(int $userId, PreparedModelInput $input): string;
}
