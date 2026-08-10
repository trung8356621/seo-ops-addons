<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos;

/**
 * @phpstan-type Effect array{type: string, label: string, count?: int|null, ref?: string|null}
 */
final readonly class AgentExecutionPreview
{
    /**
     * @param  array<string, mixed>  $normalizedInput
     * @param  list<string>  $missingFields
     * @param  list<string>  $warnings
     * @param  list<Effect>  $effects
     * @param  array<string, mixed>  $display
     * @param  array<string, mixed>  $availability
     */
    public function __construct(
        public string $executionRef,
        public string $skillKey,
        public string $capabilityKey,
        public string $mode,
        public bool $executable,
        public bool $requiresConfirmation,
        public string $confirmationPolicy,
        public array $normalizedInput,
        public array $missingFields = [],
        public array $warnings = [],
        public array $effects = [],
        public array $display = [],
        public ?string $confirmationToken = null,
        public string $previewLevel = 'gateway',
        public string $status = 'ready',
        public array $availability = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'execution_ref' => $this->executionRef,
            'skill_key' => $this->skillKey,
            'capability_key' => $this->capabilityKey,
            'mode' => $this->mode,
            'executable' => $this->executable,
            'requires_confirmation' => $this->requiresConfirmation,
            'confirmation_policy' => $this->confirmationPolicy,
            'normalized_input' => $this->normalizedInput,
            'missing_fields' => $this->missingFields,
            'warnings' => $this->warnings,
            'effects' => $this->effects,
            'display' => $this->display,
            'confirmation_token' => $this->confirmationToken,
            'preview_level' => $this->previewLevel,
            'status' => $this->status,
            'availability' => $this->availability,
        ];
    }
}
