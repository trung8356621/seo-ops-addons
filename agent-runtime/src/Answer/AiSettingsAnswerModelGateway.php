<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Answer;

use Omnichannel\Addons\AiPrompt\Contracts\AnswerTextCompletion;
use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;

final class AiSettingsAnswerModelGateway implements AnswerModelGateway
{
    public function __construct(
        private readonly AnswerTextCompletion $completion,
        private readonly int $maxOutputTokens = 2048,
    ) {}

    public function complete(int $userId, PreparedModelInput $input): string
    {
        return $this->completion->complete($userId, $input->exportText(), $this->maxOutputTokens);
    }
}
