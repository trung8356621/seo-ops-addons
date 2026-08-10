<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\BudgetExceeded;

interface PromptHookBudgetGuard
{
    /**
     * @throws BudgetExceeded
     */
    public function assertWithinBudget(string $hookKey, ?int $siteId, int $estimatedTokens = 0): void;

    public function record(string $hookKey, ?int $siteId, int $tokensIn, int $tokensOut): void;
}
