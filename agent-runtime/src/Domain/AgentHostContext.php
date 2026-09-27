<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Domain;

/**
 * Generic host context for mounting Agent Runtime across different applications/hosts
 * (SEO Ops, WordPress, standalone harness, other addons).
 */
final readonly class AgentHostContext
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $appKey,
        public AgentProjectScope $scope,
        public array $capabilities = ['turn', 'model-input'],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $appKey = trim((string) ($payload['appKey'] ?? $payload['app_key'] ?? 'standalone'));
        if ($appKey === '') {
            $appKey = 'standalone';
        }

        $scopeRaw = $payload['scope'] ?? ['type' => 'global'];
        $scope = is_array($scopeRaw)
            ? AgentProjectScope::fromArray($scopeRaw)
            : AgentProjectScope::global();

        $capsRaw = $payload['capabilities'] ?? ['turn', 'model-input'];
        $capabilities = is_array($capsRaw)
            ? array_values(array_filter($capsRaw, 'is_string'))
            : ['turn', 'model-input'];

        return new self($appKey, $scope, $capabilities);
    }

    public static function standalone(?AgentProjectScope $scope = null): self
    {
        return new self('standalone', $scope ?? AgentProjectScope::global(), ['turn', 'model-input']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'appKey' => $this->appKey,
            'scope' => [
                'type' => $this->scope->type,
                'ref' => $this->scope->siteRef ?? 'global',
            ],
            'capabilities' => $this->capabilities,
        ];
    }
}
