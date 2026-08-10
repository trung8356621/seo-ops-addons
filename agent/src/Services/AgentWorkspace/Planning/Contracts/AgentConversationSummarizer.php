<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentConversationSummary;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentSummarizationRequest;

interface AgentConversationSummarizer
{
    public function summarize(AgentSummarizationRequest $request): AgentConversationSummary;

    public function shouldSummarize(int $messageCount, int $approxTokens): bool;
}
