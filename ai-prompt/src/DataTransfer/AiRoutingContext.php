<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionTransport;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
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
         * Explicit free-only filter flag. Prefer {@see costPolicy} / EffectiveAiCostPolicyResolver
         * for article generation mode; this flag remains for micro-task / isolation callers.
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
        public readonly ?AiRoutingPolicy $routingPolicy = null,
        public readonly ?AiRoutingPolicy $routingPolicyRequested = null,
        public readonly ?AiRoutingPolicy $routingPolicyEffective = null,
        public readonly ?AiExecutionTransport $executionTransport = null,
    ) {}

    public function costPolicy(): AiCostPolicy
    {
        return $this->costPolicy ?? AiCostPolicy::Default;
    }

    public function isFreeOnly(): bool
    {
        return $this->freeOnly
            || $this->costPolicy()->isFreeOnly()
            || ($this->routingPolicyEffective ?? $this->routingPolicy) === AiRoutingPolicy::FreeOnly;
    }

    public function effectiveRoutingPolicy(): AiRoutingPolicy
    {
        return $this->routingPolicyEffective
            ?? $this->routingPolicy
            ?? AiRoutingPolicy::Normal;
    }

    public function executionTransport(): AiExecutionTransport
    {
        return $this->executionTransport ?? AiExecutionTransport::Background;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            userId: array_key_exists('userId', $overrides) ? $overrides['userId'] : $this->userId,
            legacyConnection: array_key_exists('legacyConnection', $overrides) ? $overrides['legacyConnection'] : $this->legacyConnection,
            allowLegacyFallback: array_key_exists('allowLegacyFallback', $overrides) ? (bool) $overrides['allowLegacyFallback'] : $this->allowLegacyFallback,
            usageModeOverride: array_key_exists('usageModeOverride', $overrides) ? $overrides['usageModeOverride'] : $this->usageModeOverride,
            allowedFamilyKeys: array_key_exists('allowedFamilyKeys', $overrides) ? $overrides['allowedFamilyKeys'] : $this->allowedFamilyKeys,
            costPolicy: array_key_exists('costPolicy', $overrides) ? $overrides['costPolicy'] : $this->costPolicy,
            preferredModelId: array_key_exists('preferredModelId', $overrides) ? $overrides['preferredModelId'] : $this->preferredModelId,
            requirePreferredModel: array_key_exists('requirePreferredModel', $overrides) ? (bool) $overrides['requirePreferredModel'] : $this->requirePreferredModel,
            itemGenerationMode: array_key_exists('itemGenerationMode', $overrides) ? $overrides['itemGenerationMode'] : $this->itemGenerationMode,
            hookKey: array_key_exists('hookKey', $overrides) ? $overrides['hookKey'] : $this->hookKey,
            freeOnly: array_key_exists('freeOnly', $overrides) ? (bool) $overrides['freeOnly'] : $this->freeOnly,
            isolationMode: array_key_exists('isolationMode', $overrides) ? $overrides['isolationMode'] : $this->isolationMode,
            generationStrategy: array_key_exists('generationStrategy', $overrides) ? $overrides['generationStrategy'] : $this->generationStrategy,
            canonicalPromptKey: array_key_exists('canonicalPromptKey', $overrides) ? $overrides['canonicalPromptKey'] : ($this->canonicalPromptKey ?? $this->hookKey),
            promptTaskType: array_key_exists('promptTaskType', $overrides) ? $overrides['promptTaskType'] : $this->promptTaskType,
            modelArea: array_key_exists('modelArea', $overrides) ? $overrides['modelArea'] : $this->modelArea,
            routingMode: array_key_exists('routingMode', $overrides) ? $overrides['routingMode'] : $this->routingMode,
            maxAiAttempts: array_key_exists('maxAiAttempts', $overrides) ? $overrides['maxAiAttempts'] : $this->maxAiAttempts,
            maxFreeAttempts: array_key_exists('maxFreeAttempts', $overrides) ? $overrides['maxFreeAttempts'] : $this->maxFreeAttempts,
            routingDecisionSource: array_key_exists('routingDecisionSource', $overrides) ? $overrides['routingDecisionSource'] : $this->routingDecisionSource,
            correlationId: array_key_exists('correlationId', $overrides) ? $overrides['correlationId'] : $this->correlationId,
            workflowRunId: array_key_exists('workflowRunId', $overrides) ? $overrides['workflowRunId'] : $this->workflowRunId,
            projectItemId: array_key_exists('projectItemId', $overrides) ? $overrides['projectItemId'] : $this->projectItemId,
            workflowNodeId: array_key_exists('workflowNodeId', $overrides) ? $overrides['workflowNodeId'] : $this->workflowNodeId,
            retryAttempt: array_key_exists('retryAttempt', $overrides) ? $overrides['retryAttempt'] : $this->retryAttempt,
            routingPolicy: array_key_exists('routingPolicy', $overrides) ? $overrides['routingPolicy'] : $this->routingPolicy,
            routingPolicyRequested: array_key_exists('routingPolicyRequested', $overrides) ? $overrides['routingPolicyRequested'] : $this->routingPolicyRequested,
            routingPolicyEffective: array_key_exists('routingPolicyEffective', $overrides) ? $overrides['routingPolicyEffective'] : $this->routingPolicyEffective,
            executionTransport: array_key_exists('executionTransport', $overrides) ? $overrides['executionTransport'] : $this->executionTransport,
        );
    }

    public function withRoutingDecision(
        AiExecutionRoutingMode $routingMode,
        ?int $maxAiAttempts = null,
        ?int $maxFreeAttempts = null,
        ?string $routingDecisionSource = null,
    ): self {
        return $this->with([
            'routingMode' => $routingMode,
            'maxAiAttempts' => $maxAiAttempts ?? $this->maxAiAttempts,
            'maxFreeAttempts' => $maxFreeAttempts ?? $this->maxFreeAttempts,
            'routingDecisionSource' => $routingDecisionSource ?? $this->routingDecisionSource,
        ]);
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
            'routing_policy' => $this->effectiveRoutingPolicy()->value,
            'routing_policy_requested' => ($this->routingPolicyRequested ?? $this->routingPolicy)?->value,
            'routing_policy_effective' => $this->effectiveRoutingPolicy()->value,
            'execution_transport' => $this->executionTransport()->value,
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
