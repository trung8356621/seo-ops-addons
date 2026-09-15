<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\RouteCapacity;

use DateTimeInterface;

/**
 * Authoritative USD wallet snapshot for route capacity (never invents a balance).
 */
final readonly class AiProviderBalanceSnapshot
{
    public function __construct(
        public int $connectionId,
        public ?float $balanceUsd,
        public string $currency,
        public bool $trustworthy,
        public string $source,
        public ?DateTimeInterface $observedAt = null,
        public ?DateTimeInterface $expiresAt = null,
        public ?string $status = null,
    ) {}

    public static function unknown(int $connectionId, string $source = 'unknown'): self
    {
        return new self(
            connectionId: $connectionId,
            balanceUsd: null,
            currency: 'USD',
            trustworthy: false,
            source: $source,
        );
    }

    public function hasKnownUsdBalance(): bool
    {
        return $this->trustworthy
            && $this->balanceUsd !== null
            && strtoupper($this->currency) === 'USD';
    }
}
