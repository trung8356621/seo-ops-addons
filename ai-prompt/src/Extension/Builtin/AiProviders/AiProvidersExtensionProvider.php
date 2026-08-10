<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders;

use Omnichannel\Addons\Agent\Extension\Contracts\ExtensionProvider;
use Omnichannel\Addons\Agent\Extension\ExtensionContext;

final class AiProvidersExtensionProvider implements ExtensionProvider
{
    public function __construct(
        private readonly GeminiAiTextProvider $gemini,
        private readonly ClaudeAiTextProvider $claude,
        private readonly AiProvidersHealthDriver $healthDriver,
    ) {}

    public function id(): string
    {
        return 'ai-providers';
    }

    public function register(ExtensionContext $ctx): void
    {
        $ctx->aiProviders()->registerText($this->gemini);
        $ctx->aiProviders()->registerText($this->claude);
        $ctx->aiProviders()->register($this->id(), $this->healthDriver);
    }

    public function boot(ExtensionContext $ctx): void
    {
        unset($ctx);
    }
}
