<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationConditionResult
{
    /**
     * @param  list<array<string, mixed>>  $evaluations
     * @param  list<string>  $errors
     */
    public function __construct(
        public bool $matched,
        public bool $changed,
        public array $evaluations,
        public ?string $fingerprint = null,
        public array $errors = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'matched' => $this->matched,
            'changed' => $this->changed,
            'evaluations' => $this->evaluations,
            'fingerprint' => $this->fingerprint,
            'errors' => $this->errors,
        ];
    }
}
