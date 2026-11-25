<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiErrorPresentationSource: string
{
    case Internal = 'internal';
    case Provider = 'provider';
    case Network = 'network';
    case Credential = 'credential';
    case Unknown = 'unknown';

    public function labelVi(): string
    {
        return match ($this) {
            self::Internal => 'SEO-OPS Backend',
            self::Provider => 'AI Provider',
            self::Network => 'Mạng / Gateway',
            self::Credential => 'API Credential',
            self::Unknown => 'Chưa xác định',
        };
    }
}
