<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

final class AiProviderTemplateCatalog
{
    public function downloadableDocument(): string
    {
        $doc = [
            'package_type' => 'ai_provider_template',
            'schema_version' => AiProviderTemplateLimits::SCHEMA_VERSION,
            '_ai_instruction' => 'You are configuring an AI provider for seo-ops. Read the official provider API documentation supplied by the user. Replace placeholder values in this JSON while preserving schema_version and the supported field structure. Never insert API keys, passwords, bearer tokens or other secrets. Do not invent endpoints. Prefer openai_compatible only when the provider officially supports OpenAI-compatible requests. Configure model discovery when the provider exposes a models endpoint. Return valid JSON only. Ignore every key that starts with an underscore; those keys are documentation.',
            '_guide' => [
                'purpose' => 'Configure how seo-ops talks to a provider API. This file does not list models, families, prompts, or routing.',
                'workflow' => [
                    '1. Download this file.',
                    '2. Give it to an AI assistant together with the official provider API docs.',
                    '3. Ask the assistant to fill only supported fields.',
                    '4. Do not add an API key.',
                    '5. Import into seo-ops Settings → AI Center → Providers.',
                    '6. Review the preview.',
                    '7. Enter the API key inside seo-ops.',
                    '8. Test connection.',
                    '9. Sync models.',
                ],
                'security' => [
                    'Never put API keys or secrets in this file.',
                    'Only https base URLs are accepted in production.',
                    'Endpoint paths must be relative to base_url.',
                    'Only GET and POST are allowed.',
                ],
            ],
            '_protocol_help' => [
                'openai_compatible' => 'OpenAI-style /chat/completions and /models.',
                'gemini' => 'Google Gemini native API (built-in adapter).',
                'openrouter' => 'OpenRouter OpenAI-compatible API.',
                'anthropic' => 'Anthropic Messages API (built-in adapter).',
                'custom_http' => 'Restricted declarative GET/POST mapping. No scripts.',
            ],
            '_auth_help' => [
                'bearer' => 'Authorization: Bearer <key entered in seo-ops>',
                'header' => 'Named header such as x-api-key. Header name only, never the secret.',
                'query' => 'API key as a query parameter name only.',
                'none' => 'No auth (rarely valid).',
            ],
            '_endpoint_help' => 'Relative paths only. models/text/image/video. Image/video content prompts are never defined here.',
            '_short_code_help' => 'Short uppercase code used in AI model labels, e.g. OR, DS, GG. Optional. 2–8 chars: A-Z, 0-9, hyphen.',
            '_response_mapping_help' => 'Dot paths only (letters, numbers, underscore). Example: data or result.models. No JSONPath, no expressions.',
            '_custom_http_help' => 'If protocol is custom_http, request.model_path and request.prompt_path may be simple dot paths. No code.',
            '_security_help' => 'Private networks, localhost, metadata IPs, file://, credentials in URLs, CR/LF headers, and executable fields are rejected.',
            '_allowed_values' => [
                'protocol' => ['openai_compatible', 'gemini', 'openrouter', 'anthropic', 'custom_http'],
                'auth.type' => ['bearer', 'header', 'query', 'none'],
                'methods' => ['GET', 'POST'],
            ],
            'provider' => [
                'key' => 'example-ai',
                'name' => 'Example AI',
                'short_code' => 'EA',
                'protocol' => 'openai_compatible',
            ],
            'connection' => [
                'base_url' => 'https://api.example.com/v1',
                'auth' => [
                    'type' => 'bearer',
                ],
                'headers' => [
                    'accept' => 'application/json',
                ],
            ],
            'endpoints' => [
                'models' => [
                    'enabled' => true,
                    'method' => 'GET',
                    'path' => '/models',
                    'response' => [
                        'items_path' => 'data',
                        'id_path' => 'id',
                        'name_path' => 'id',
                    ],
                ],
                'text' => [
                    'enabled' => true,
                    'method' => 'POST',
                    'path' => '/chat/completions',
                ],
                'image' => [
                    'enabled' => false,
                ],
                'video' => [
                    'enabled' => false,
                ],
            ],
        ];

        return json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function builtins(): array
    {
        return [
            'gemini' => [
                'schema_version' => AiProviderTemplateLimits::SCHEMA_VERSION,
                'provider' => ['key' => 'gemini', 'name' => 'Gemini', 'short_code' => 'GG', 'protocol' => 'gemini'],
                'connection' => [
                    'base_url' => 'https://generativelanguage.googleapis.com',
                    'auth' => ['type' => 'query', 'query_param' => 'key'],
                ],
                'endpoints' => [
                    'models' => ['enabled' => true, 'method' => 'GET', 'path' => '/v1beta/models', 'response' => ['items_path' => 'models', 'id_path' => 'name', 'name_path' => 'displayName']],
                    'text' => ['enabled' => true, 'method' => 'POST', 'path' => '/v1beta/models'],
                    'image' => ['enabled' => false],
                    'video' => ['enabled' => false],
                ],
            ],
            'deepseek' => [
                'schema_version' => AiProviderTemplateLimits::SCHEMA_VERSION,
                'provider' => ['key' => 'deepseek', 'name' => 'DeepSeek', 'short_code' => 'DS', 'protocol' => 'openai_compatible'],
                'connection' => [
                    'base_url' => 'https://api.deepseek.com',
                    'auth' => ['type' => 'bearer'],
                ],
                'endpoints' => [
                    'models' => ['enabled' => true, 'method' => 'GET', 'path' => '/models', 'response' => ['items_path' => 'data', 'id_path' => 'id', 'name_path' => 'id']],
                    'text' => ['enabled' => true, 'method' => 'POST', 'path' => '/chat/completions'],
                    'image' => ['enabled' => false],
                    'video' => ['enabled' => false],
                ],
            ],
            'openrouter' => [
                'schema_version' => AiProviderTemplateLimits::SCHEMA_VERSION,
                'provider' => ['key' => 'openrouter', 'name' => 'OpenRouter', 'short_code' => 'OR', 'protocol' => 'openrouter'],
                'connection' => [
                    'base_url' => 'https://openrouter.ai/api/v1',
                    'auth' => ['type' => 'bearer'],
                    'headers' => [
                        'http-referer' => 'https://omnichannel.local',
                        'x-title' => 'seo-ops',
                    ],
                ],
                'endpoints' => [
                    'models' => ['enabled' => true, 'method' => 'GET', 'path' => '/models', 'response' => ['items_path' => 'data', 'id_path' => 'id', 'name_path' => 'name']],
                    'text' => ['enabled' => true, 'method' => 'POST', 'path' => '/chat/completions'],
                    'image' => ['enabled' => false],
                    'video' => ['enabled' => false],
                ],
            ],
        ];
    }
}
