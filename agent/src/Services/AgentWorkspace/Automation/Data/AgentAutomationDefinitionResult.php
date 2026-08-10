<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationDefinitionResult
{
    /**
     * @param  array<string, mixed>|null  $automation
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public bool $ok,
        public ?array $automation = null,
        public ?AgentAutomationPreview $preview = null,
        public array $errors = [],
        public array $warnings = [],
        public ?string $code = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'automation' => $this->automation,
            'preview' => $this->preview?->toArray(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'code' => $this->code,
        ];
    }
}
