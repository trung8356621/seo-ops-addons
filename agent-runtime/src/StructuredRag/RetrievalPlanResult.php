<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\StructuredRag;

final readonly class RetrievalPlanResult
{
    /**
     * @param list<string> $capabilities
     * @param list<string> $modules
     * @param list<string> $missingRequirements
     * @param array<string, scalar|null> $parameters
     */
    public function __construct(
        public array $capabilities,
        public array $modules,
        public array $parameters,
        public array $missingRequirements,
        public string $depth,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
