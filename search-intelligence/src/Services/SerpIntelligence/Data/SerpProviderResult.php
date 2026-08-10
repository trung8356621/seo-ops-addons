<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data;

/**
 * Raw provider payload sau collect — chưa persist snapshot.
 */
final class SerpProviderResult
{
    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $features
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly bool $success,
        public readonly array $results,
        public readonly array $features = [],
        public readonly array $metadata = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $collectedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'success' => $this->success,
            'results' => $this->results,
            'features' => $this->features,
            'metadata' => $this->metadata,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'collected_at' => $this->collectedAt,
        ];
    }
}
