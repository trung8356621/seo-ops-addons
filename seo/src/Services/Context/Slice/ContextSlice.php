<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Slice;

use Omnichannel\Addons\Seo\Services\Context\ContextEnvelopeBuilder;
use Omnichannel\Addons\Seo\Services\Context\ContextFreshness;

/**
 * Canonical result for one context slice.
 *
 * @phpstan-type ContextSliceArray array{
 *   key: string,
 *   scope: array{site_ref: string},
 *   generated_at: string,
 *   source_updated_at: string|null,
 *   stale: bool,
 *   available: bool,
 *   data: array<string, mixed>
 * }
 */
final class ContextSlice
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $key,
        public readonly int $siteId,
        public readonly array $data,
        public readonly ?string $sourceUpdatedAt,
        public readonly string $generatedAt,
        public readonly bool $available,
        public readonly bool $stale,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(
        string $key,
        int $siteId,
        array $data,
        ?string $sourceUpdatedAt,
        bool $available = true,
        ?string $generatedAt = null,
        ?bool $stale = null,
    ): self {
        return new self(
            key: $key,
            siteId: $siteId,
            data: $data,
            sourceUpdatedAt: $sourceUpdatedAt,
            generatedAt: $generatedAt ?? now()->toIso8601String(),
            available: $available,
            stale: $stale ?? ContextFreshness::isSourceStale($sourceUpdatedAt),
        );
    }

    /**
     * @return array{site_ref: string}
     */
    public function scope(): array
    {
        return ['site_ref' => ContextEnvelopeBuilder::siteRef($this->siteId)];
    }

    /**
     * @return ContextSliceArray
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'scope' => $this->scope(),
            'generated_at' => $this->generatedAt,
            'source_updated_at' => $this->sourceUpdatedAt,
            'stale' => $this->stale,
            'available' => $this->available,
            'data' => $this->data,
        ];
    }
}
