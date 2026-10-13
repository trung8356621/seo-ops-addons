<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Answer;

use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;

interface AnswerModelGateway
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function complete(int $userId, PreparedModelInput $input, array $options = []): string;
}
