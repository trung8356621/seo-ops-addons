<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\StructuredRag;

final readonly class QueryUnderstanding
{
    /**
     * @param list<string> $dataRequirements
     * @param list<string> $entities
     * @param list<string> $limitations
     * @param array<string, scalar|null> $parameters
     * @param array<string, mixed> $resolvedContext
     */
    public function __construct(
        public string $intent,
        public bool $requiresRetrieval,
        public bool $requiresAnalysis,
        public string $scope,
        public string $depth,
        public array $dataRequirements,
        public array $entities,
        public array $parameters,
        public array $resolvedContext,
        public array $limitations = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
