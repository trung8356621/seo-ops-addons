<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationRunResult
{
    /**
     * @param  array<string, mixed>|null  $summary
     * @param  list<array<string, mixed>>  $stepResults
     * @param  array<string, mixed>|null  $conditionResult
     * @param  array<string, mixed>|null  $error
     */
    public function __construct(
        public bool $ok,
        public string $status,
        public string $runHashId,
        public ?string $occurrenceKey = null,
        public ?array $summary = null,
        public array $stepResults = [],
        public ?array $conditionResult = null,
        public ?array $error = null,
        public ?string $skipReason = null,
        public ?string $approvalHashId = null,
        public ?string $executionRef = null,
        public ?string $planningRequestId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'status' => $this->status,
            'run_hash_id' => $this->runHashId,
            'occurrence_key' => $this->occurrenceKey,
            'summary' => $this->summary,
            'step_results' => $this->stepResults,
            'condition_result' => $this->conditionResult,
            'error' => $this->error,
            'skip_reason' => $this->skipReason,
            'approval_hash_id' => $this->approvalHashId,
            'execution_ref' => $this->executionRef,
            'planning_request_id' => $this->planningRequestId,
        ];
    }
}
