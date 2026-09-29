<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

interface AssumedModelResolver
{
    public function resolveDecisionModel(int $userId): AssumedModelMetadata;

    public function resolveAnswerModel(int $userId): AssumedModelMetadata;
}
