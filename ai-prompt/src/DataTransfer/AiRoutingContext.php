<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use App\Models\ApiConnection;

final class AiRoutingContext
{
    /**
     * @param  list<string>|null  $allowedFamilyKeys
     */
    public function __construct(
        public readonly ?int $userId = null,
        public readonly ?ApiConnection $legacyConnection = null,
        public readonly bool $allowLegacyFallback = true,
        public readonly ?AiUsageMode $usageModeOverride = null,
        public readonly ?array $allowedFamilyKeys = null,
        public readonly ?AiCostPolicy $costPolicy = null,
        public readonly ?int $preferredModelId = null,
        public readonly bool $requirePreferredModel = false,
        public readonly ?string $itemGenerationMode = null,
        public readonly ?string $hookKey = null,
        /**
         * Per-execution free-only filter (sectioned_free). Never mutate global routing.
         */
        public readonly bool $freeOnly = false,
        public readonly ?string $isolationMode = null,
        public readonly ?string $generationStrategy = null,
        public readonly ?string $canonicalPromptKey = null,
        public readonly ?string $promptTaskType = null,
        public readonly ?string $modelArea = null,
        public readonly ?AiExecutionRoutingMode $routingMode = null,
        public readonly ?int $maxAiAttempts = null,
        public readonly ?int $maxFreeAttempts = null,
        public readonly ?string $routingDecisionSource = null,
        public readonly ?string $correlationId = null,
        public readonly ?int $workflowRunId = null,
        public readonly ?int $projectItemId = null,
        public readonly ?string $workflowNodeId = null,
        public readonly ?int $retryAttempt = null,
    ) {}

    public function costPolicy(): AiCostPolicy
    {
        return $this->costPolicy ?? AiCostPolicy::Default;
    }

    public function withRoutingDecision(
        AiExecutionRoutingMode $routingMode,
        ?int $maxAiAttempts = null,
        ?int $maxFreeAttempts = null,
        ?string $routingDecisionSource = null,
    ): self {
        return new self(
            userId: $this->userId,
            legacyConnection: $this->legacyConnection,
            allowLegacyFallback: $this->allowLegacyFallback,
            usageModeOverride: $this->usageModeOverride,
            allowedFamilyKeys: $this->allowedFamilyKeys,
            costPolicy: $this->costPolicy,
            preferredModelId: $this->preferredModelId,
            requirePreferredModel: $this->requirePreferredModel,
            itemGenerationMode: $this->itemGenerationMode,
            hookKey: $this->hookKey,
            freeOnly: $this->freeOnly,
            isolationMode: $this->isolationMode,
            generationStrategy: $this->generationStrategy,
            canonicalPromptKey: $this->canonicalPromptKey ?? $this->hookKey,
            promptTaskType: $this->promptTaskType,
            modelArea: $this->modelArea,
            routingMode: $routingMode,
            maxAiAttempts: $maxAiAttempts ?? $this->maxAiAttempts,
            maxFreeAttempts: $maxFreeAttempts ?? $this->maxFreeAttempts,
            routingDecisionSource: $routingDecisionSource ?? $this->routingDecisionSource,
            correlationId: $this->correlationId,
            workflowRunId: $this->workflowRunId,
            projectItemId: $this->projectItemId,
            workflowNodeId: $this->workflowNodeId,
            retryAttempt: $this->retryAttempt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDebugArray(): array
    {
        return array_filter([
            'canonical_prompt_key' => $this->canonicalPromptKey ?? $this->hookKey,
            'prompt_task_type' => $this->promptTaskType,
            'model_area' => $this->modelArea,
            'hook_key' => $this->hookKey,
            'routing_mode' => $this->routingMode?->value,
            'cost_policy' => $this->costPolicy()->value,
            'item_generation_mode' => $this->itemGenerationMode,
            'preferred_model_id' => $this->preferredModelId,
            'require_preferred_model' => $this->requirePreferredModel,
            'free_only' => $this->freeOnly,
            'max_ai_attempts' => $this->maxAiAttempts,
            'max_free_attempts' => $this->maxFreeAttempts,
            'routing_decision_source' => $this->routingDecisionSource,
            'correlation_id' => $this->correlationId,
            'workflow_run_id' => $this->workflowRunId,
            'project_item_id' => $this->projectItemId,
            'workflow_node_id' => $this->workflowNodeId,
            'retry_attempt' => $this->retryAttempt,
            'generation_strategy' => $this->generationStrategy,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
