<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Provider;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\RenderedPromptRequest;

/** Fake provider for unit tests — no real AI. */
final class FakePromptProviderAdapter implements PromptProviderAdapter
{
    /** @var list<RenderedPromptRequest> */
    public array $calls = [];

    /**
     * @param  array{
     *   text?: string,
     *   refused?: bool,
     *   truncated?: bool,
     *   tokens_in?: int,
     *   tokens_out?: int,
     *   model?: string
     * }  $response
     */
    public function __construct(
        private array $response = ['text' => 'ok'],
        private readonly PromptProviderCapabilities $capabilities = new PromptProviderCapabilities(
            textGeneration: true,
            jsonMode: true,
            nativeStructuredOutput: false,
            systemMessage: true,
            temperature: true,
            maxTokens: true,
        ),
    ) {}

    public function capabilities(): PromptProviderCapabilities
    {
        return $this->capabilities;
    }

    public function generate(RenderedPromptRequest $request, PromptStructuredStrategy $strategy): PromptProviderResponse
    {
        $this->calls[] = $request;

        return new PromptProviderResponse(
            text: (string) ($this->response['text'] ?? 'ok'),
            refused: (bool) ($this->response['refused'] ?? false),
            truncated: (bool) ($this->response['truncated'] ?? false),
            inputTokens: isset($this->response['tokens_in']) ? (int) $this->response['tokens_in'] : 1,
            outputTokens: isset($this->response['tokens_out']) ? (int) $this->response['tokens_out'] : 1,
            totalTokens: null,
            usageSource: 'provider',
            provider: 'fake',
            model: (string) ($this->response['model'] ?? 'fake'),
            attempts: 1,
        );
    }
}
