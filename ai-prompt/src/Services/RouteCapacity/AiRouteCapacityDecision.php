<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\RouteCapacity;

use DateTimeInterface;

/**
 * Pre-provider capacity decision for one physical route / workload.
 */
final readonly class AiRouteCapacityDecision
{
    public function __construct(
        public bool $eligible,
        public ?string $reason = null,
        public string $scope = 'route',
        public ?float $knownBalanceUsd = null,
        public ?float $thresholdUsd = null,
        public string $source = 'none',
        public ?DateTimeInterface $observedAt = null,
        public ?DateTimeInterface $expiresAt = null,
    ) {}

    public static function allow(
        ?AiProviderBalanceSnapshot $balance = null,
        string $source = 'capacity_policy',
    ): self {
        return new self(
            eligible: true,
            reason: null,
            scope: 'route',
            knownBalanceUsd: $balance?->balanceUsd,
            thresholdUsd: null,
            source: $balance?->source ?? $source,
            observedAt: $balance?->observedAt,
            expiresAt: $balance?->expiresAt,
        );
    }

    public static function deny(
        string $reason,
        string $scope,
        ?AiProviderBalanceSnapshot $balance = null,
        ?float $thresholdUsd = null,
        ?string $source = null,
    ): self {
        return new self(
            eligible: false,
            reason: $reason,
            scope: $scope,
            knownBalanceUsd: $balance?->balanceUsd,
            thresholdUsd: $thresholdUsd,
            source: $source ?? $balance?->source ?? 'capacity_policy',
            observedAt: $balance?->observedAt,
            expiresAt: $balance?->expiresAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttemptDiagnostics(): array
    {
        return array_filter([
            'capacity_eligible' => $this->eligible,
            'capacity_reason' => $this->reason,
            'capacity_scope' => $this->scope,
            'known_balance_usd' => $this->knownBalanceUsd,
            'threshold_usd' => $this->thresholdUsd,
            'capacity_source' => $this->source,
            'capacity_observed_at' => $this->observedAt?->format(DATE_ATOM),
            'capacity_expires_at' => $this->expiresAt?->format(DATE_ATOM),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
