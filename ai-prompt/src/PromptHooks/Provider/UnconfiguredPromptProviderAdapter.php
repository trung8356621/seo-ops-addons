<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Provider;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderFailed;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\RenderedPromptRequest;

/** Fallback when PromptRunner adapter not bound — hook mode must fail clearly. */
final class UnconfiguredPromptProviderAdapter implements PromptProviderAdapter
{
    public function capabilities(): PromptProviderCapabilities
    {
        return new PromptProviderCapabilities(
            textGeneration: true,
            jsonMode: true,
            nativeStructuredOutput: false,
            systemMessage: true,
            temperature: true,
            maxTokens: true,
        );
    }

    public function generate(RenderedPromptRequest $request, PromptStructuredStrategy $strategy): PromptProviderResponse
    {
        throw new ProviderFailed(
            'Prompt provider adapter not configured for hook mode. Keep migration flag legacy.',
        );
    }
}
