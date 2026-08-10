<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

final readonly class AgentModelSelection
{
    public function __construct(
        public string $providerKey,
        public string $model,
        public string $routingReason,
        public bool $fallbackUsed = false,
        public int $contextLimitTokens = 128000,
        public bool $supportsStructuredOutput = true,
        public ?int $connectionId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->providerKey,
            'model' => $this->model,
            'routing_reason' => $this->routingReason,
            'fallback_used' => $this->fallbackUsed,
            'context_limit_tokens' => $this->contextLimitTokens,
            'supports_structured_output' => $this->supportsStructuredOutput,
            'connection_id' => $this->connectionId,
        ];
    }
}
