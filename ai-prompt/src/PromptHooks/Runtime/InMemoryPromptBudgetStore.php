<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

final class InMemoryPromptBudgetStore implements PromptBudgetStore
{
    /** @var array<string, array{requests: int, tokens: int}> */
    private array $usage = [];

    public function get(string $bucket): array
    {
        return $this->usage[$bucket] ?? ['requests' => 0, 'tokens' => 0];
    }

    public function increment(string $bucket, int $tokens): void
    {
        $state = $this->get($bucket);
        $state['requests']++;
        $state['tokens'] += max(0, $tokens);
        $this->usage[$bucket] = $state;
    }
}
