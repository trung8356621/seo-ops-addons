<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts\SerpIntelligenceProviderInterface;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts\SerpIntelligenceProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpQueryRequest;

/**
 * Resolve SERP intelligence provider — fail-closed, không silent fallback.
 */
final class SerpProviderResolver
{
    public function __construct(
        private readonly SerpIntelligenceProviderRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $context  enabled_providers?, provider_key?
     * @return array{provider: ?SerpIntelligenceProviderInterface, error_code: ?string, metadata: array<string, mixed>}
     */
    public function resolve(SerpQueryRequest $request, array $context = []): array
    {
        $providerKey = trim($request->providerKey);
        if ($providerKey === '') {
            $providerKey = trim((string) ($context['provider_key'] ?? ''));
        }

        if ($providerKey === '') {
            return $this->fail('serp_provider.not_configured', ['reason' => 'missing_provider_key']);
        }

        $provider = $this->registry->get($providerKey);
        if ($provider === null) {
            return $this->fail('serp_provider.not_registered', ['provider_key' => $providerKey]);
        }

        $enabled = is_array($context['enabled_providers'] ?? null)
            ? $context['enabled_providers']
            : $this->configStringList('providers.enabled', ['manual_import']);

        // disabled chỉ khi provider đã register nhưng không nằm trong allowlist
        if ($enabled !== [] && ! in_array($providerKey, $enabled, true)) {
            return $this->fail('serp_provider.disabled', ['provider_key' => $providerKey]);
        }

        if (! $provider->supports($request)) {
            return $this->fail('serp_provider.incompatible', [
                'provider_key' => $providerKey,
                'query' => $request->normalizedQuery,
            ]);
        }

        $health = $provider->health();
        if (($health['healthy'] ?? false) !== true) {
            return $this->fail('serp_provider.unhealthy', [
                'provider_key' => $providerKey,
                'health' => $health,
            ]);
        }

        return [
            'provider' => $provider,
            'error_code' => null,
            'metadata' => ['provider_key' => $providerKey],
        ];
    }

    /**
     * @return array{provider: ?SerpIntelligenceProviderInterface, error_code: ?string, metadata: array<string, mixed>}
     */
    public function resolveForQuerySupport(SerpQueryRequest $request, array $context = []): array
    {
        $resolved = $this->resolve($request, $context);
        if ($resolved['provider'] === null) {
            return $resolved;
        }

        if (! $resolved['provider']->supports($request)) {
            return $this->fail('serp_provider.query_unsupported', [
                'provider_key' => $resolved['provider']->key(),
            ]);
        }

        return $resolved;
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
            $value = config('seo-content-ai.serp_intelligence.'.$key, $default);

            return is_array($value) ? array_values(array_map('strval', $value)) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
