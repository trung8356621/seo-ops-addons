<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookModelConfig;

/** Immutable rendered AI request — no provider SDK objects. */
final class RenderedPromptRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $redactedVariableMetadata
     * @param  array<string, mixed>  $modelSettings
     * @param  array<string, mixed>  $metadata  Non-secret binding (prompt_id, variables, …)
     */
    public function __construct(
        public readonly string $system,
        public readonly array $messages,
        public readonly PromptHookModelConfig $model,
        public readonly array $modelSettings,
        public readonly string $localeCode,
        public readonly string $languageName,
        public readonly string $hookKey,
        public readonly string $hookVersion,
        public readonly array $redactedVariableMetadata,
        public readonly array $metadata = [],
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            'hook' => $this->hookKey.'@'.$this->hookVersion,
            'locale' => $this->localeCode,
            'system_len' => strlen($this->system),
            'messages' => array_map(
                static fn (array $m): array => [
                    'role' => $m['role'] ?? '',
                    'len' => strlen((string) ($m['content'] ?? '')),
                ],
                $this->messages,
            ),
            'model_settings' => $this->modelSettings,
            'prompt_id' => $this->metadata['prompt_id'] ?? null,
        ], JSON_UNESCAPED_UNICODE));
    }
}
