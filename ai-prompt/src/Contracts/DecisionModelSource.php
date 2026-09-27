<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Contracts;

/**
 * Enabled Decision Models in AI Settings priority order.
 * Agent Runtime depends on this contract, not on model names.
 */
interface DecisionModelSource
{
    /**
     * @return list<ResolvedDecisionModel>
     */
    public function orderedModels(int $userId): array;
}
