<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

/**
 * Canonical Prompt Hook Definition (Spec v0.1 runtime). Immutable.
 * Phase 1 Data\PromptHookDefinition is legacy dual-read source only.
 */
final class PromptHookDefinition
{
    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, array<string, mixed>>  $settingsSchema
     * @param  list<string>  $sensitiveInputFields
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly PromptHookKey $key,
        public readonly PromptHookVersion $version,
        public readonly PromptHookStatus $status,
        public readonly string $name,
        public readonly string $description,
        public readonly PromptHookModelConfig $model,
        public readonly PromptHookLocalePolicy $locale,
        public readonly PromptHookInputSchema $inputSchema,
        public readonly PromptHookOutputSchema $outputSchema,
        public readonly array $template,
        public readonly PromptHookRetryPolicy $retry,
        public readonly PromptHookLoggingPolicy $logging,
        public readonly PromptHookLimits $limits,
        public readonly array $settingsSchema = [],
        public readonly array $sensitiveInputFields = [],
        public readonly array $metadata = [],
        public readonly string $manifestPath = '',
        public readonly bool $strictTemplateVariables = true,
        public readonly ?string $outputContract = null,
        /** Settings UI selector — Hook type only; ownership stays in Settings binding. */
        public readonly bool $settingsVisible = false,
        public readonly string $category = 'general',
        /** UI-only guidance; never used by runtime resolver. */
        public readonly array $presentation = [],
    ) {}

    public function outputContractKey(): ?string
    {
        $key = trim((string) ($this->outputContract ?? ''));

        return $key !== '' ? $key : null;
    }

    public function cacheKey(): string
    {
        return $this->key->value.'@'.$this->version->toString();
    }
}
