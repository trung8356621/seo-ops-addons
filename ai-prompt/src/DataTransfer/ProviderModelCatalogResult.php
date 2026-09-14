<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

/**
 * Result of a provider model-catalog discovery attempt.
 *
 * @phpstan-type CatalogModelRow array{
 *   id: string,
 *   display_name?: string,
 *   metadata?: array<string, mixed>
 * }
 */
final class ProviderModelCatalogResult
{
    /**
     * @param  list<CatalogModelRow>  $models
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $models,
        public readonly bool $authoritative,
        public readonly ?string $error = null,
        public readonly array $providerMetadata = [],
        public readonly bool $emptyCatalogConfirmed = false,
    ) {}

    /**
     * @param  list<CatalogModelRow>  $models
     * @param  array<string, mixed>  $providerMetadata
     */
    public static function ok(array $models, bool $authoritative = true, array $providerMetadata = []): self
    {
        return new self(
            success: true,
            models: $models,
            authoritative: $authoritative,
            providerMetadata: $providerMetadata,
            emptyCatalogConfirmed: $models === [],
        );
    }

    public static function failed(string $error, array $providerMetadata = []): self
    {
        return new self(
            success: false,
            models: [],
            authoritative: false,
            error: $error,
            providerMetadata: $providerMetadata,
            emptyCatalogConfirmed: false,
        );
    }

    /**
     * HTTP success but unexpected empty — treat as sync failure, preserve LKG.
     */
    public static function suspiciousEmpty(string $error = 'suspicious_empty_catalog'): self
    {
        return new self(
            success: false,
            models: [],
            authoritative: false,
            error: $error,
            emptyCatalogConfirmed: false,
        );
    }
}
