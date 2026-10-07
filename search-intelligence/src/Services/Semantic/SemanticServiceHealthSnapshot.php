<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic;

final class SemanticServiceHealthSnapshot
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly SemanticServiceHealthStatus $status,
        public readonly string $errorCode,
        public readonly string $reason,
        public readonly ?string $baseHost = null,
        public readonly ?int $httpStatus = null,
        public readonly array $payload = [],
        public readonly int $latencyMs = 0,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === SemanticServiceHealthStatus::Healthy;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return array_filter([
            'source' => 'semantic_service',
            'health_status' => $this->status->value,
            'error_code' => $this->errorCode !== '' ? $this->errorCode : null,
            'semantic_host' => $this->baseHost,
            'http_status' => $this->httpStatus,
            'latency_ms' => $this->latencyMs > 0 ? $this->latencyMs : null,
            'ready' => $this->payload['ready'] ?? null,
            'status' => $this->payload['status'] ?? null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
