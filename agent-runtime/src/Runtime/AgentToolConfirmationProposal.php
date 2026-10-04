<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Runtime;

use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecision;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;

final readonly class AgentToolConfirmationProposal
{
    /** @param list<string> $capabilities @param array<string, scalar|null> $parameters @param list<string> $toolCapabilities @param array<string, mixed> $scope */
    public function __construct(
        public string $intent,
        public string $primaryCapability,
        public array $capabilities,
        public array $parameters,
        public string $responseTemplate,
        public array $toolCapabilities,
        public array $scope,
    ) {}

    public static function fromDecision(RetrievalDecision $decision, AgentProjectScope $scope): ?self
    {
        $tools = AgentCapabilityCatalog::toolCapabilities($decision->capabilities);
        if ($tools === []) {
            return null;
        }

        return new self(
            $decision->intent,
            (string) $decision->primaryCapability,
            $decision->capabilities,
            $decision->parameters,
            $decision->responseTemplate,
            $tools,
            $scope->toArray(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'primary_capability' => $this->primaryCapability,
            'capabilities' => $this->capabilities,
            'parameters' => $this->parameters,
            'response_template' => $this->responseTemplate,
            'tool_capabilities' => $this->toolCapabilities,
            'scope' => $this->scope,
        ];
    }

    /** @return array{intent: string, tool_capabilities: list<string>, parameters: array<string, scalar|null>} */
    public function clientPayload(): array
    {
        return [
            'intent' => $this->intent,
            'tool_capabilities' => $this->toolCapabilities,
            'parameters' => $this->parameters,
        ];
    }
}
