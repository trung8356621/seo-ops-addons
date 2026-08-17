<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiProviderProtocol: string
{
    case OpenaiCompatible = 'openai_compatible';
    case Gemini = 'gemini';
    case Openrouter = 'openrouter';
    case Anthropic = 'anthropic';
    case CustomHttp = 'custom_http';
}
