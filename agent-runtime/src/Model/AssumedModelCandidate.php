<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

final readonly class AssumedModelCandidate
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $displayName = '',
        public array $capabilities = [],
        public int $priority = 100,
        public bool $isFree = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'display_name' => $this->displayName ?: $this->model,
            'capabilities' => $this->capabilities,
            'priority' => $this->priority,
            'is_free' => $this->isFree,
        ];
    }
}
