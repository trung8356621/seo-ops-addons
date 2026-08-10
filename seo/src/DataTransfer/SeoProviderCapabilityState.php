<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\DataTransfer;

final readonly class SeoProviderCapabilityState
{
    public function __construct(
        public bool $supported,
        public bool $implemented,
        public bool $configured,
        public bool $available,
        public ?string $reason = null,
    ) {}

    /**
     * @return array{supported: bool, implemented: bool, configured: bool, available: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'supported' => $this->supported,
            'implemented' => $this->implemented,
            'configured' => $this->configured,
            'available' => $this->available,
            'reason' => $this->reason,
        ];
    }

    public static function unsupported(): self
    {
        return new self(
            supported: false,
            implemented: false,
            configured: false,
            available: false,
            reason: 'unsupported',
        );
    }

    public static function vendorOnly(): self
    {
        return new self(
            supported: true,
            implemented: false,
            configured: false,
            available: false,
            reason: 'not_implemented',
        );
    }

    public static function notConfigured(): self
    {
        return new self(
            supported: true,
            implemented: true,
            configured: false,
            available: false,
            reason: 'not_configured',
        );
    }

    public static function available(): self
    {
        return new self(
            supported: true,
            implemented: true,
            configured: true,
            available: true,
            reason: null,
        );
    }
}
