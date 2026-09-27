<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

interface DecisionModelGateway
{
    public function decide(DecisionRequest $request): DecisionResult;
}
