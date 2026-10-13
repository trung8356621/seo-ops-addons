<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;

interface AssumedModelResolver
{
    public function resolveDecisionModel(int $userId): AssumedModelMetadata;

    public function resolveAnswerModel(int $userId, ?AiRoutingPolicy $routingPolicy = null): AssumedModelMetadata;
}
