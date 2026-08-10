<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders;

use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiExecutionContext;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\AiProviderDriver;

/**
 * Legacy `AiProviderDriver` adapter so ExtensionHealthService (which keys drivers by
 * extension id) can report aggregate health for the "ai-providers" builtin extension.
 */
final class AiProvidersHealthDriver implements AiProviderDriver
{
    public function __construct(
        private readonly GeminiAiTextProvider $gemini,
        private readonly ClaudeAiTextProvider $claude,
    ) {}

    public function id(): string
    {
        return 'ai-providers';
    }

    public function label(): string
    {
        return 'Built-in AI Providers (Gemini, Claude)';
    }

    public function supportsChat(): bool
    {
        return true;
    }

    public function supportsImage(): bool
    {
        return false;
    }

    public function supportsEmbedding(): bool
    {
        return false;
    }

    public function supportsModeration(): bool
    {
        return false;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array
    {
        $context = new AiExecutionContext(connectionProviderKey: 'ai-providers');

        $gemini = $this->gemini->health($context);
        $claude = $this->claude->health($context);

        return [
            'ok' => $gemini->ok && $claude->ok,
            'message' => trim('Gemini: '.$gemini->message.' | Claude: '.$claude->message),
        ];
    }
}
