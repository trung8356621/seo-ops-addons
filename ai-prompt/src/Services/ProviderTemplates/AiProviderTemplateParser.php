<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use Omnichannel\Addons\AiPrompt\DataTransfer\NormalizedAiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationJsonGuard;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationPackageLimits;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionShortCode;
use Omnichannel\Addons\AiPrompt\Support\AiProviderAuthType;
use Omnichannel\Addons\AiPrompt\Support\AiProviderProtocol;
use Omnichannel\Addons\AiPrompt\Support\ConfigurationPackageType;

final class AiProviderTemplateParser
{
    public function __construct(
        private readonly ConfigurationJsonGuard $guard = new ConfigurationJsonGuard(),
        private readonly AiProviderOutboundUrlPolicy $urls = new AiProviderOutboundUrlPolicy(),
    ) {}

    public function parse(string $rawJson): NormalizedAiProviderTemplate
    {
        try {
            $clean = $this->guard->decode($rawJson, ConfigurationPackageLimits::providerTemplate());
        } catch (\Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException $exception) {
            throw AiProviderTemplateException::rejected($exception->getMessage());
        }

        $type = trim((string) ($clean['package_type'] ?? ConfigurationPackageType::AiProviderTemplate->value));
        if ($type !== '' && $type !== ConfigurationPackageType::AiProviderTemplate->value) {
            throw AiProviderTemplateException::rejected('unexpected package_type "'.$type.'".');
        }

        return $this->normalize($clean);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalize(array $data): NormalizedAiProviderTemplate
    {
        $warnings = [];
        $version = trim((string) ($data['schema_version'] ?? ''));
        if ($version !== AiProviderTemplateLimits::SCHEMA_VERSION) {
            throw AiProviderTemplateException::rejected('Unsupported Provider Template version.');
        }

        $provider = is_array($data['provider'] ?? null) ? $data['provider'] : [];
        $key = strtolower(trim((string) ($provider['key'] ?? '')));
        $name = trim((string) ($provider['name'] ?? ''));
        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_-]{1,'.(AiProviderTemplateLimits::MAX_PROVIDER_KEY - 1).'}$/', $key)) {
            throw AiProviderTemplateException::rejected('provider.key is invalid.');
        }
        if ($name === '') {
            throw AiProviderTemplateException::rejected('provider.name is required.');
        }

        $protocol = AiProviderProtocol::tryFrom(strtolower(trim((string) ($provider['protocol'] ?? ''))));
        if ($protocol === null) {
            throw AiProviderTemplateException::rejected('unsupported protocol "'.trim((string) ($provider['protocol'] ?? '')).'".');
        }

        $shortCode = null;
        if (array_key_exists('short_code', $provider) && trim((string) $provider['short_code']) !== '') {
            try {
                $shortCode = AiConnectionShortCode::assertValidExplicit((string) $provider['short_code']);
            } catch (\InvalidArgumentException $exception) {
                throw AiProviderTemplateException::rejected($exception->getMessage());
            }
        }

        $connection = is_array($data['connection'] ?? null) ? $data['connection'] : [];
        $baseUrl = rtrim(trim((string) ($connection['base_url'] ?? '')), '/');
        $this->urls->assertSafeUrl($baseUrl);

        $authRaw = is_array($connection['auth'] ?? null) ? $connection['auth'] : [];
        $authType = AiProviderAuthType::tryFrom(strtolower(trim((string) ($authRaw['type'] ?? 'bearer'))));
        if ($authType === null) {
            throw AiProviderTemplateException::rejected('unsupported auth type.');
        }
        $auth = ['type' => $authType->value];
        if ($authType === AiProviderAuthType::Header) {
            $header = strtolower(trim((string) ($authRaw['header'] ?? 'x-api-key')));
            $this->assertHeaderName($header);
            $auth['header'] = $header;
        }
        if ($authType === AiProviderAuthType::Query) {
            $param = strtolower(trim((string) ($authRaw['query_param'] ?? 'key')));
            if (! preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $param)) {
                throw AiProviderTemplateException::rejected('auth query parameter name is invalid.');
            }
            $auth['query_param'] = $param;
        }

        $headers = $this->normalizeHeaders(is_array($connection['headers'] ?? null) ? $connection['headers'] : []);
        $endpoints = $this->normalizeEndpoints(is_array($data['endpoints'] ?? null) ? $data['endpoints'] : [], $warnings);
        $allowOverride = (bool) ($connection['allow_base_url_override'] ?? false);

        foreach ($data as $unknown => $value) {
            unset($value);
            if (! in_array($unknown, ['schema_version', 'package_type', 'meta', 'provider', 'connection', 'endpoints'], true)) {
                $warnings[] = 'Unknown optional field ignored: '.$unknown;
            }
        }

        if ($protocol === AiProviderProtocol::CustomHttp) {
            $warnings[] = 'Custom HTTP mapping';
        }

        return new NormalizedAiProviderTemplate(
            schemaVersion: $version,
            providerKey: $key,
            providerName: $name,
            protocol: $protocol,
            baseUrl: $baseUrl,
            authType: $authType,
            auth: $auth,
            headers: $headers,
            endpoints: $endpoints,
            warnings: array_values(array_unique($warnings)),
            allowBaseUrlOverride: $allowOverride,
            shortCode: $shortCode,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private function normalizeHeaders(array $raw): array
    {
        if (count($raw) > AiProviderTemplateLimits::MAX_HEADERS) {
            throw AiProviderTemplateException::rejected('too many headers.');
        }
        $out = [];
        foreach ($raw as $name => $value) {
            $header = strtolower(trim((string) $name));
            $this->assertHeaderName($header);
            $text = trim((string) $value);
            if ($text === '' || strlen($text) > AiProviderTemplateLimits::MAX_HEADER_VALUE) {
                throw AiProviderTemplateException::rejected('header value is invalid.');
            }
            if (str_contains($text, "\r") || str_contains($text, "\n")) {
                throw AiProviderTemplateException::rejected('header value contains CR/LF.');
            }
            if (preg_match('/bearer\s+\S{8,}/i', $text) === 1) {
                throw AiProviderTemplateException::rejected('API credentials must not be stored inside provider templates.');
            }
            $out[$header] = $text;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $warnings
     * @return array<string, array<string, mixed>>
     */
    private function normalizeEndpoints(array $raw, array &$warnings): array
    {
        if (count($raw) > AiProviderTemplateLimits::MAX_ENDPOINTS) {
            throw AiProviderTemplateException::rejected('too many endpoints.');
        }
        $out = [];
        foreach (['models', 'text', 'image', 'video'] as $name) {
            $item = is_array($raw[$name] ?? null) ? $raw[$name] : [];
            $enabled = (bool) ($item['enabled'] ?? false);
            if (! $enabled) {
                $out[$name] = ['enabled' => false];
                continue;
            }
            $method = strtoupper(trim((string) ($item['method'] ?? ($name === 'models' ? 'GET' : 'POST'))));
            if (! in_array($method, AiProviderTemplateLimits::ALLOWED_METHODS, true)) {
                throw AiProviderTemplateException::rejected('HTTP method "'.$method.'" is not allowed.');
            }
            $path = $this->urls->assertRelativePath((string) ($item['path'] ?? ''));
            $endpoint = [
                'enabled' => true,
                'method' => $method,
                'path' => $path,
            ];
            if ($name === 'models') {
                $response = is_array($item['response'] ?? null) ? $item['response'] : [];
                $endpoint['response'] = [
                    'items_path' => $this->safeDotPath((string) ($response['items_path'] ?? 'data')),
                    'id_path' => $this->safeDotPath((string) ($response['id_path'] ?? 'id')),
                    'name_path' => $this->safeDotPath((string) ($response['name_path'] ?? 'id')),
                ];
            }
            if ($name === 'text' && is_array($item['request'] ?? null)) {
                $endpoint['request'] = [
                    'model_path' => $this->safeDotPath((string) ($item['request']['model_path'] ?? 'model')),
                    'prompt_path' => $this->safeDotPath((string) ($item['request']['prompt_path'] ?? 'messages')),
                ];
            }
            $out[$name] = $endpoint;
        }
        foreach (array_keys($raw) as $unknown) {
            if (! in_array($unknown, ['models', 'text', 'image', 'video'], true)) {
                $warnings[] = 'Unknown optional field ignored: endpoints.'.$unknown;
            }
        }

        return $out;
    }

    private function safeDotPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || strlen($path) > 64) {
            throw AiProviderTemplateException::rejected('JSON path is invalid.');
        }
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)*$/', $path)) {
            throw AiProviderTemplateException::rejected('JSON path is invalid.');
        }

        return $path;
    }

    private function assertHeaderName(string $header): void
    {
        if ($header === '' || strlen($header) > AiProviderTemplateLimits::MAX_HEADER_NAME) {
            throw AiProviderTemplateException::rejected('header name is invalid.');
        }
        if (str_contains($header, "\r") || str_contains($header, "\n") || str_contains($header, ':')) {
            throw AiProviderTemplateException::rejected('header name contains illegal characters.');
        }
        if (in_array($header, AiProviderTemplateLimits::FORBIDDEN_HEADER_NAMES, true)
            || str_starts_with($header, 'x-forwarded-')) {
            throw AiProviderTemplateException::rejected('header "'.$header.'" is not allowed.');
        }
        if (! in_array($header, AiProviderTemplateLimits::ALLOWED_HEADER_NAMES, true)) {
            throw AiProviderTemplateException::rejected('header "'.$header.'" is not allowed.');
        }
    }
}
