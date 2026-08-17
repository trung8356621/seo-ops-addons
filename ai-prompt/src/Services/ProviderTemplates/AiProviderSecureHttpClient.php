<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\AiPrompt\DataTransfer\NormalizedAiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Support\AiProviderAuthType;

final class AiProviderSecureHttpClient
{
    public function __construct(
        private readonly AiProviderOutboundUrlPolicy $urls = new AiProviderOutboundUrlPolicy(),
    ) {}

    /**
     * @param  array<string, string>  $extraHeaders
     */
    public function request(
        NormalizedAiProviderTemplate $template,
        string $endpointKey,
        ?string $apiKey,
        array $extraHeaders = [],
        ?array $jsonBody = null,
        int $timeoutSeconds = 20,
    ): Response {
        $endpoint = $template->endpoints[$endpointKey] ?? null;
        if (! is_array($endpoint) || empty($endpoint['enabled'])) {
            throw AiProviderTemplateException::rejected('Endpoint "'.$endpointKey.'" is not configured.');
        }

        $method = strtoupper((string) ($endpoint['method'] ?? 'GET'));
        if (! in_array($method, AiProviderTemplateLimits::ALLOWED_METHODS, true)) {
            throw AiProviderTemplateException::rejected('HTTP method is not allowed.');
        }

        $path = $this->urls->assertRelativePath((string) ($endpoint['path'] ?? ''));
        $url = $template->baseUrl.$path;
        $this->urls->assertSafeUrl($url);

        $headers = $template->headers;
        foreach ($extraHeaders as $name => $value) {
            $headers[strtolower((string) $name)] = (string) $value;
        }

        $query = [];
        $pending = Http::timeout($timeoutSeconds)
            ->withOptions(['allow_redirects' => false])
            ->acceptJson();

        $pending = $this->applyAuth($pending, $template, $apiKey, $query);

        if ($headers !== []) {
            $pending = $pending->withHeaders($headers);
        }

        $response = match ($method) {
            'GET' => $pending->get($url, $query),
            default => $pending->post($url, $jsonBody ?? []),
        };

        return $response;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function applyAuth(mixed $pending, NormalizedAiProviderTemplate $template, ?string $apiKey, array &$query): mixed
    {
        $key = trim((string) $apiKey);
        if ($template->authType === AiProviderAuthType::None || $key === '') {
            return $pending;
        }

        return match ($template->authType) {
            AiProviderAuthType::Bearer => $pending->withToken($key),
            AiProviderAuthType::Header => $pending->withHeaders([
                (string) ($template->auth['header'] ?? 'x-api-key') => $key,
            ]),
            AiProviderAuthType::Query => tap($pending, function () use ($template, $key, &$query): void {
                $param = (string) ($template->auth['query_param'] ?? 'key');
                $query[$param] = $key;
            }),
            AiProviderAuthType::None => $pending,
        };
    }

    public static function redact(string $text): string
    {
        $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text) ?? $text;
        $text = preg_replace('/(api[_-]?key|token|password|secret)([=:]+\s*)\S+/i', '$1$2[redacted]', $text) ?? $text;

        return $text;
    }
}
