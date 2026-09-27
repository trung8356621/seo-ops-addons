<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;

final readonly class RetrievalPlan
{
    /**
     * @param  list<RetrievalStep>  $steps
     */
    public function __construct(
        public AgentProjectScope $scope,
        public array $steps,
        public bool $globalUnsupported = false,
        public bool $parameterExtractionDeferred = false,
    ) {}
}
