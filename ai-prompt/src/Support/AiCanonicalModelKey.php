<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\BuiltInModelCapabilityCatalog;

/**
 * Stable logical-model identity across Direct + aggregator routes.
 *
 * Examples:
 * - gemini-2.5-pro / google/gemini-2.5-pro → google:gemini-2.5-pro (via family key)
 * - Unknown aggregator-only → openrouter:vendor/model
 *
 * Never snaps by display name alone.
 */
final class AiCanonicalModelKey
{
    public static function fromProviderModelId(string $providerModelId, ?string $provider = null): string
    {
        $normalized = BuiltInModelCapabilityCatalog::normalizeModel($providerModelId);
        if ($normalized === '') {
            return 'unknown:empty';
        }

        $catalog = new AiModelFamilyCatalog();
        $family = $catalog->familyForModelId($normalized);
        if ($family !== null) {
            return $family->familyKey;
        }

        // Aggregator synthetic family (openrouter.vendor.model) — stable across OR routes.
        $aggregator = $catalog->aggregatorFamily($normalized);
        if ($aggregator !== null) {
            return $aggregator->familyKey;
        }

        $vendor = self::guessVendor($normalized, $provider);

        return $vendor.':'.$normalized;
    }

    private static function guessVendor(string $normalized, ?string $provider): string
    {
        if (str_contains($normalized, '/')) {
            $prefix = strtolower((string) strstr($normalized, '/', true));

            return $prefix !== '' ? $prefix : 'unknown';
        }

        return match (true) {
            $provider === ApiConnectionProviders::GEMINI => 'google',
            $provider === ApiConnectionProviders::CLAUDE => 'anthropic',
            $provider === ApiConnectionProviders::DEEPSEEK => 'deepseek',
            $provider === ApiConnectionProviders::OPENROUTER => 'openrouter',
            default => $provider !== null && $provider !== '' ? strtolower($provider) : 'unknown',
        };
    }
}
