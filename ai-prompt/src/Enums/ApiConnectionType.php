<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Enums;

enum ApiConnectionType: string
{
    case Ai = 'ai';
    case Seo = 'seo';

    public function label(): string
    {
        return match ($this) {
            self::Ai => __('seo-content-ai::filament.api_connections.type_ai'),
            self::Seo => __('seo-content-ai::filament.api_connections.type_seo'),
        };
    }
}
