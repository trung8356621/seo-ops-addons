<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiProviderAuthType;
use Omnichannel\Addons\AiPrompt\Support\AiProviderProtocol;

final readonly class NormalizedAiProviderTemplate
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, array<string, mixed>>  $endpoints
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $auth
     */
    public function __construct(
        public string $schemaVersion,
        public string $providerKey,
        public string $providerName,
        public AiProviderProtocol $protocol,
        public string $baseUrl,
        public AiProviderAuthType $authType,
        public array $auth,
        public array $headers,
        public array $endpoints,
        public array $warnings,
        public bool $allowBaseUrlOverride = false,
        public ?string $shortCode = null,
    ) {}

    public function withBaseUrl(string $baseUrl): self
    {
        return new self(
            schemaVersion: $this->schemaVersion,
            providerKey: $this->providerKey,
            providerName: $this->providerName,
            protocol: $this->protocol,
            baseUrl: rtrim(trim($baseUrl), '/'),
            authType: $this->authType,
            auth: $this->auth,
            headers: $this->headers,
            endpoints: $this->endpoints,
            warnings: $this->warnings,
            allowBaseUrlOverride: $this->allowBaseUrlOverride,
            shortCode: $this->shortCode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        $provider = [
            'key' => $this->providerKey,
            'name' => $this->providerName,
            'protocol' => $this->protocol->value,
        ];
        if ($this->shortCode !== null && $this->shortCode !== '') {
            $provider['short_code'] = $this->shortCode;
        }

        return [
            'package_type' => 'ai_provider_template',
            'schema_version' => $this->schemaVersion,
            'provider' => $provider,
            'connection' => [
                'base_url' => $this->baseUrl,
                'auth' => $this->auth,
                'headers' => $this->headers,
                'allow_base_url_override' => $this->allowBaseUrlOverride,
            ],
            'endpoints' => $this->endpoints,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function preview(): array
    {
        $models = $this->endpoints['models'] ?? [];
        $text = $this->endpoints['text'] ?? [];
        $image = $this->endpoints['image'] ?? [];
        $video = $this->endpoints['video'] ?? [];

        return [
            'provider' => $this->providerName,
            'short_code' => $this->shortCode ?? '',
            'protocol' => $this->protocol->value,
            'base_url' => $this->baseUrl,
            'authentication' => $this->authType->value,
            'model_discovery' => ! empty($models['enabled'])
                ? strtoupper((string) ($models['method'] ?? 'GET')).' '.(string) ($models['path'] ?? '')
                : 'Not configured',
            'text_generation' => ! empty($text['enabled'])
                ? strtoupper((string) ($text['method'] ?? 'POST')).' '.(string) ($text['path'] ?? '')
                : 'Not configured',
            'image' => ! empty($image['enabled']) ? 'Configured' : 'Not configured',
            'video' => ! empty($video['enabled']) ? 'Configured' : 'Not configured',
        ];
    }
}
