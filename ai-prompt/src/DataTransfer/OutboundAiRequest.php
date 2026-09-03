<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

/**
 * Exact outbound text request shape used for final budget invariant.
 * One token source of truth — no double-count of continuation/schema already in messages.
 */
final readonly class OutboundAiRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<array<string, mixed>>  $tools
     */
    public function __construct(
        public array $messages,
        public ?string $jsonSchema = null,
        public array $tools = [],
        public int $requestedMaxOutputTokens = 0,
        public string $provider = '',
        public string $model = '',
        public ?string $responseFormat = null,
        public string $planId = '',
    ) {}

    /**
     * Build from a single compiled user prompt (DeepSeek / OpenAI-compatible plain path).
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromCompiledUserPrompt(
        string $compiled,
        string $provider,
        string $model,
        array $options = [],
        string $planId = '',
    ): self {
        $schema = null;
        if (isset($options['json_schema']) && is_string($options['json_schema']) && $options['json_schema'] !== '') {
            $schema = $options['json_schema'];
        } elseif (isset($options['response_format']['json_schema']) && is_array($options['response_format']['json_schema'])) {
            $encoded = json_encode($options['response_format']['json_schema']);
            $schema = is_string($encoded) ? $encoded : null;
        }

        $tools = [];
        if (isset($options['tools']) && is_array($options['tools'])) {
            $tools = $options['tools'];
        }

        return new self(
            messages: [['role' => 'user', 'content' => $compiled]],
            jsonSchema: $schema,
            tools: $tools,
            requestedMaxOutputTokens: (int) ($options['max_output'] ?? $options['max_tokens'] ?? 0),
            provider: $provider,
            model: $model,
            responseFormat: isset($options['response_format']) ? 'json_schema' : null,
            planId: $planId,
        );
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public static function fromMessages(
        array $messages,
        string $provider,
        string $model,
        array $options = [],
        string $planId = '',
    ): self {
        $normalized = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? 'user');
            $content = (string) ($message['content'] ?? '');
            $normalized[] = ['role' => $role, 'content' => $content];
        }

        $schema = null;
        if (isset($options['json_schema']) && is_string($options['json_schema'])) {
            $schema = $options['json_schema'];
        }

        return new self(
            messages: $normalized,
            jsonSchema: $schema,
            tools: is_array($options['tools'] ?? null) ? $options['tools'] : [],
            requestedMaxOutputTokens: (int) ($options['max_output'] ?? $options['max_tokens'] ?? 0),
            provider: $provider,
            model: $model,
            responseFormat: isset($options['response_format']) ? 'structured' : null,
            planId: $planId,
        );
    }
}
