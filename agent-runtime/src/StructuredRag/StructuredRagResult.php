<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\StructuredRag;

use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;

final readonly class StructuredRagResult
{
    /**
     * @param array<string, int> $timings
     * @param list<string> $limitations
     */
    public function __construct(
        public string $status,
        public QueryUnderstanding $understanding,
        public RetrievalPlanResult $plan,
        public ?ContextBuildResult $context,
        public ?PreparedModelInput $modelInput,
        public ?AgentResponse $directResponse,
        public array $timings,
        public array $limitations = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'query_understanding' => $this->understanding->toArray(),
            'data_requirements' => $this->understanding->dataRequirements,
            'selected_capabilities' => $this->plan->capabilities,
            'retrieval_parameters' => $this->plan->parameters,
            'retrieved_evidence' => $this->context?->bundle->toArray(),
            'retrieval_limitations' => $this->limitations,
            'missing_data_requirements' => $this->plan->missingRequirements,
            'context_budget' => $this->context?->budget(),
            'model_input' => $this->modelInput === null ? null : [
                'stage' => $this->modelInput->stage,
                'messages' => $this->modelInput->messages,
                'export_text' => $this->modelInput->exportText(),
            ],
            'direct_response' => $this->directResponse?->toArray(),
            'stage_timing_ms' => $this->timings,
            'model_execution' => [
                'decision_model_called' => false,
                'answer_model_called' => false,
                'status' => 'not_called_input_only',
            ],
        ];
    }
}
