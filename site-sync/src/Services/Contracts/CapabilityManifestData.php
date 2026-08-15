<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Contracts;

/**
 * @phpstan-type CapabilityEntry array{available: bool, provider?: string|null, provider_version?: string|null, score_kind?: string|null}
 */
final readonly class CapabilityManifestData
{
    /**
     * @param  array<string, CapabilityEntry>  $capabilities
     */
    public function __construct(
        public string $schema,
        public string $siteUrl,
        public string $bridgeVersion,
        public string $detectedAt,
        public array $capabilities,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $schema = (string) ($payload['schema'] ?? '');
        if (! SiteSyncSchema::isSupportedSchema($schema)) {
            throw new \InvalidArgumentException('Unsupported capability schema: '.$schema);
        }

        $capabilities = [];
        $rawCaps = is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [];
        $keys = array_values(array_unique(array_merge(
            SiteSyncSchema::CAPABILITY_KEYS,
            SiteSyncSchema::LOCAL_ENGINE_CAPABILITY_KEYS,
            array_keys($rawCaps),
        )));
        foreach ($keys as $key) {
            $entry = is_array($rawCaps[$key] ?? null) ? $rawCaps[$key] : [];
            $capabilities[$key] = [
                'available' => (bool) ($entry['available'] ?? false),
                'provider' => isset($entry['provider']) ? (string) $entry['provider'] : null,
                'provider_version' => isset($entry['provider_version']) ? (string) $entry['provider_version'] : null,
                'score_kind' => isset($entry['score_kind']) ? (string) $entry['score_kind'] : null,
            ];
        }

        return new self(
            schema: $schema,
            siteUrl: (string) ($payload['site_url'] ?? ''),
            bridgeVersion: (string) ($payload['bridge_version'] ?? ''),
            detectedAt: (string) ($payload['detected_at'] ?? gmdate('c')),
            capabilities: $capabilities,
            raw: $payload,
        );
    }

    public function isAvailable(string $capability): bool
    {
        return (bool) ($this->capabilities[$capability]['available'] ?? false);
    }

    /**
     * @return list<string>
     */
    public function localEngineGaps(): array
    {
        $missing = [];
        foreach (SiteSyncSchema::LOCAL_ENGINE_CAPABILITY_KEYS as $key) {
            if (! $this->isAvailable($key)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function provider(string $capability): ?string
    {
        $provider = $this->capabilities[$capability]['provider'] ?? null;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'site_url' => $this->siteUrl,
            'bridge_version' => $this->bridgeVersion,
            'detected_at' => $this->detectedAt,
            'capabilities' => $this->capabilities,
        ];
    }
}
