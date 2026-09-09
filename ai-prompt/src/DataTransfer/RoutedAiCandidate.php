<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Support\AiCanonicalModelKey;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

final class RoutedAiCandidate
{
    /**
     * @param  list<string>  $capabilities
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $profile,
        public readonly ApiConnection $connection,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $capabilities,
        public readonly int $priority,
        public readonly array $options = [],
        public readonly ?int $seoAiModelId = null,
        public readonly bool $legacyFallback = false,
        public readonly bool $isFree = false,
    ) {}

    /**
     * Logical-model identity shared by Direct + aggregator aliases (e.g. deepseek.chat).
     * Must NEVER be used alone as failure / attempted / suppression key.
     */
    public function logicalModelKey(): string
    {
        return AiCanonicalModelKey::fromProviderModelId($this->model, $this->provider);
    }

    /**
     * Physical route identity: connection + provider model id.
     * Failures/suppressions/attempt tracking must key off this (plus billing lane), not logicalModelKey().
     */
    public function physicalRouteKey(): string
    {
        return ((int) $this->connection->id).'|'.$this->provider.'|'.$this->model;
    }

    public function isAggregatorRoute(): bool
    {
        return ApiConnectionProviders::isAggregator($this->provider);
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'profile' => $this->profile,
            'capability' => $this->profile,
            'provider' => $this->provider,
            'connection_id' => (int) $this->connection->id,
            'connection_name' => (string) $this->connection->name,
            'model' => $this->model,
            'logical_model' => $this->logicalModelKey(),
            'physical_route' => $this->physicalRouteKey(),
            'is_aggregator_route' => $this->isAggregatorRoute(),
            'capabilities' => $this->capabilities,
            'priority' => $this->priority,
            'route_position' => $this->priority,
            'legacy_fallback' => $this->legacyFallback,
            'seo_ai_model_id' => $this->seoAiModelId,
            'is_free' => $this->isFree,
            'free' => $this->isFree,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttemptLogContext(int $attemptNumber, ?string $routeRevision = null): array
    {
        return array_filter([
            ...$this->toLogContext(),
            'attempt' => $attemptNumber,
            'attempt_number' => $attemptNumber,
            'route_revision' => $routeRevision,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
