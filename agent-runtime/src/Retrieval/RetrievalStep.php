<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

final readonly class RetrievalStep
{
    /**
     * @param  array<string, string>  $query
     */
    public function __construct(
        public string $resource,
        public array $query = [],
        public ?string $topicRef = null,
    ) {}
}
