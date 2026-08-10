<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos;

use Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentErrorCategory;
use Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentExecutionStatus;

final readonly class AgentExecutionResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     * @param  list<array{capability?: string, skill_key?: string, reason?: string}>  $nextActions
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $error
     * @param  array<string, mixed>|null  $rendered
     */
    public function __construct(
        public string $executionRef,
        public AgentExecutionStatus $status,
        public bool $ok,
        public string $code,
        public string $message,
        public string $skillKey,
        public string $capabilityKey,
        public array $data = [],
        public array $warnings = [],
        public array $nextActions = [],
        public array $meta = [],
        public ?string $operationReference = null,
        public ?AgentErrorCategory $errorCategory = null,
        public ?array $error = null,
        public ?array $rendered = null,
        public int $attempt = 1,
        public bool $idempotentReplay = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'execution_ref' => $this->executionRef,
            'status' => $this->status->value,
            'ok' => $this->ok,
            'code' => $this->code,
            'message' => $this->message,
            'skill_key' => $this->skillKey,
            'capability_key' => $this->capabilityKey,
            'data' => $this->data,
            'warnings' => $this->warnings,
            'next_actions' => $this->nextActions,
            'meta' => $this->meta,
            'operation_reference' => $this->operationReference,
            'error_category' => $this->errorCategory?->value,
            'error' => $this->error,
            'rendered' => $this->rendered,
            'attempt' => $this->attempt,
            'idempotent_replay' => $this->idempotentReplay,
        ];
    }
}
