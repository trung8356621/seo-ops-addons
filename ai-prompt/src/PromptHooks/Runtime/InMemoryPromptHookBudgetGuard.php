<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\BudgetExceeded;

final class InMemoryPromptHookBudgetGuard implements PromptHookBudgetGuard
{
    public function __construct(
        private readonly PromptBudgetStore $store = new InMemoryPromptBudgetStore,
        private readonly int $maxRequests = 100,
        private readonly int $maxTokens = 1_000_000,
    ) {}

    public function assertWithinBudget(string $hookKey, ?int $siteId, int $estimatedTokens = 0): void
    {
        $bucket = $this->bucket($hookKey, $siteId);
        $state = $this->store->get($bucket);
        if ($state['requests'] >= $this->maxRequests) {
            throw new BudgetExceeded("Budget exceeded: max requests for [{$bucket}]");
        }
        if ($state['tokens'] + $estimatedTokens > $this->maxTokens) {
            throw new BudgetExceeded("Budget exceeded: max tokens for [{$bucket}]");
        }
    }

    public function record(string $hookKey, ?int $siteId, int $tokensIn, int $tokensOut): void
    {
        $this->store->increment($this->bucket($hookKey, $siteId), max(0, $tokensIn) + max(0, $tokensOut));
    }

    private function bucket(string $hookKey, ?int $siteId): string
    {
        return $hookKey.'#'.($siteId ?? 0);
    }
}
