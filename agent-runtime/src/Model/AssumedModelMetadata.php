<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

final readonly class AssumedModelMetadata
{
    /**
     * @param  list<AssumedModelCandidate>  $fallbacks
     */
    public function __construct(
        public string $stage,
        public string $profile,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $displayName = null,
        public array $fallbacks = [],
        public string $routingMode = 'standard',
        public ?string $status = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'profile' => $this->profile,
            'provider' => $this->provider,
            'model' => $this->model,
            'display_name' => $this->displayName ?: ($this->model ?? 'Not configured'),
            'fallbacks' => array_map(
                static fn (AssumedModelCandidate $candidate): array => $candidate->toArray(),
                $this->fallbacks,
            ),
            'routing_mode' => $this->routingMode,
            'status' => $this->status ?? ($this->model !== null ? 'available' : 'not_configured'),
        ];
    }
}
