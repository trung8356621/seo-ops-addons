<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts\GscIntelligenceProviderInterface;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts\GscIntelligenceProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscSearchAnalyticsRequest;

/**
 * Resolve GSC intelligence provider — fail-closed, không silent fallback.
 */
final class GscProviderResolver
{
    public function __construct(
        private readonly GscIntelligenceProviderRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $context  enabled_providers?, provider_key?, require_capability?
     * @return array{provider: ?GscIntelligenceProviderInterface, error_code: ?string, metadata: array<string, mixed>}
     */
    public function resolve(GscSearchAnalyticsRequest $request, array $context = []): array
    {
        $providerKey = trim($request->providerKey);
        if ($providerKey === '') {
            $providerKey = trim((string) ($context['provider_key'] ?? ''));
        }

        if ($providerKey === '') {
            return $this->fail('gsc_provider.not_configured', ['reason' => 'missing_provider_key']);
        }

        $provider = $this->registry->get($providerKey);
        if ($provider === null) {
            return $this->fail('gsc_provider.not_registered', ['provider_key' => $providerKey]);
        }

        $enabled = is_array($context['enabled_providers'] ?? null)
            ? $context['enabled_providers']
            : $this->configStringList('providers.enabled', ['manual_import']);

        if ($enabled !== [] && ! in_array($providerKey, $enabled, true)) {
            return $this->fail('gsc_provider.disabled', ['provider_key' => $providerKey]);
        }

        if (! $provider->supports($request)) {
            return $this->fail('gsc_provider.incompatible', [
                'provider_key' => $providerKey,
                'property_ref' => $request->propertyRef,
            ]);
        }

        $health = $provider->health();
        if (($health['healthy'] ?? false) !== true) {
            return $this->fail('gsc_provider.unhealthy', [
                'provider_key' => $providerKey,
                'health' => $health,
            ]);
        }

        $requiredCapability = trim((string) ($context['require_capability'] ?? ''));
        if ($requiredCapability !== '') {
            $capabilities = (array) ($health['metadata']['capabilities'] ?? []);
            if (! in_array($requiredCapability, $capabilities, true)) {
                return $this->fail('gsc_provider.capability_unsupported', [
                    'provider_key' => $providerKey,
                    'capability' => $requiredCapability,
                ]);
            }
        }

        return [
            'provider' => $provider,
            'error_code' => null,
            'metadata' => ['provider_key' => $providerKey],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{provider: null, error_code: string, metadata: array<string, mixed>}
     */
    private function fail(string $code, array $metadata): array
    {
        return [
            'provider' => null,
            'error_code' => $code,
            'metadata' => $metadata,
        ];
    }

    /** @return list<string> */
    private function configStringList(string $key, array $default): array
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = config('seo-content-ai.gsc_intelligence.'.$key, $default);

            return is_array($value) ? array_values(array_map('strval', $value)) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
