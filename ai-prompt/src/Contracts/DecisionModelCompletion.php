<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Contracts;

/**
 * Bounded JSON completion on a resolved decision model.
 * Implementations must not return provider credentials.
 */
interface DecisionModelCompletion
{
    public function complete(ResolvedDecisionModel $model, string $prompt, int $maxOutputTokens): string;
}
