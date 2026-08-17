<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

final class AiProviderTemplateLimits
{
    public const SCHEMA_VERSION = '1.0';

    public const MAX_BYTES = 65536;

    public const MAX_DEPTH = 12;

    public const MAX_ARRAY_LENGTH = 200;

    public const MAX_OBJECT_KEYS = 80;

    public const MAX_STRING_LENGTH = 2048;

    public const MAX_ENDPOINTS = 8;

    public const MAX_HEADERS = 8;

    public const MAX_HEADER_NAME = 64;

    public const MAX_HEADER_VALUE = 256;

    public const MAX_PROVIDER_KEY = 64;

    /**
     * @var list<string>
     */
    public const ALLOWED_METHODS = ['GET', 'POST'];

    /**
     * @var list<string>
     */
    public const ALLOWED_HEADER_NAMES = [
        'authorization',
        'x-api-key',
        'content-type',
        'accept',
        'http-referer',
        'x-title',
        'x-openrouter-title',
        'anthropic-version',
    ];

    /**
     * @var list<string>
     */
    public const FORBIDDEN_HEADER_NAMES = [
        'host',
        'content-length',
        'transfer-encoding',
        'connection',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'forwarded',
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
    ];

    /**
     * @var list<string>
     */
    public const SECRET_FIELD_NAMES = [
        'api_key',
        'apikey',
        'token',
        'password',
        'secret',
        'authorization',
        'bearer',
        'private_key',
        'client_secret',
        'access_token',
        'refresh_token',
    ];

    /**
     * @var list<string>
     */
    public const FORBIDDEN_FIELD_NAMES = [
        'script',
        'code',
        'php_class',
        'handler_class',
        'eval',
        'command',
        'closure',
        'serialize',
    ];
}
