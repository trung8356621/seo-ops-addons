<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Wallet;

final class ProviderBalanceResult
{
    public function __construct(
        public readonly bool $supported,
        public readonly bool $success,
        public readonly ?float $balance = null,
        public readonly string $currency = 'USD',
        public readonly ?string $error = null,
    ) {}

    public static function supportedSuccess(float $balance, string $currency = 'USD'): self
    {
        return new self(
            supported: true,
            success: true,
            balance: $balance,
            currency: $currency,
            error: null,
        );
    }

    public static function supportedFailed(string $error): self
    {
        return new self(
            supported: true,
            success: false,
            balance: null,
            currency: 'USD',
            error: $error,
        );
    }

    public static function unsupported(): self
    {
        return new self(
            supported: false,
            success: false,
            balance: null,
            currency: 'USD',
            error: null,
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isSupported(): bool
    {
        return $this->supported;
    }

    public function isUnsupported(): bool
    {
        return ! $this->supported;
    }
}
