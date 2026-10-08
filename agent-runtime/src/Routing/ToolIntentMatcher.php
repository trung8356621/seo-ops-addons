<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

interface ToolIntentMatcher
{
    /**
     * @param  list<array{key: string, positive_examples: list<string>, negative_examples: list<string>}>  $intents
     */
    public function match(string $query, array $intents): ToolIntentMatchResult;
}
