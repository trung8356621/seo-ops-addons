<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\DataTransfer\OutboundAiRequest;
use Omnichannel\Addons\AiPrompt\DataTransfer\PromptBudgetPlan;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptBudgetException;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;
use Illuminate\Support\Str;

/**
 * Dual-layer budget:
 * A) task-planning estimate (structured options, may exclude already-inlined parts)
 * B) final outbound invariant on exact messages/schema/tools (no double-count)
 */
final class PromptBudgetPreflightService
{
    /** @var array<string, PromptBudgetPlan> */
    private array $verifiedPlans = [];

    public function __construct(
        private readonly ModelContextCapabilityResolver $capabilityResolver = new ModelContextCapabilityResolver(),
        private readonly PromptTokenEstimator $estimator = new PromptTokenEstimator(),
        private readonly PromptSplitStrategyRegistry $strategies = new PromptSplitStrategyRegistry(),
    ) {}

    /**
     * Task-planning estimate. Pass ONLY parts NOT already inside $compiledPrompt.
     *
     * @param  array<string, mixed>  $options
     *   - continuation_already_inlined: bool (default true when continuation empty)
     *   - schema_already_inlined: bool
     *   - desired_output_tokens / minimum_required_output_tokens / quantity / count
     */
    public function plan(
        RoutedAiCandidate $candidate,
        string $compiledPrompt,
        ?string $hookKey = null,
        array $options = [],
    ): PromptBudgetPlan {
        $capability = $this->capabilityResolver->resolve($candidate);
        $strategy = $this->strategies->forHook($hookKey);

        return $this->planWithCapability($capability, $strategy, $compiledPrompt, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function planWithCapability(
        ModelContextCapability $capability,
        PromptSplitStrategy $strategy,
        string $compiledPrompt,
        array $options = [],
    ): PromptBudgetPlan {
        $inputTokens = $this->estimator->estimate($compiledPrompt, $capability->estimatorFamily)
            + $capability->providerMessageOverheadTokens;

        // Continuation/schema only if NOT already inlined into compiled (prevents double-count).
        $continuationAlreadyInlined = (bool) ($options['continuation_already_inlined'] ?? true);
        $continuation = (string) ($options['continuation'] ?? '');
        $continuationTokens = 0;
        if (! $continuationAlreadyInlined && $continuation !== '') {
            $continuationTokens = $this->estimator->estimate($continuation, $capability->estimatorFamily);
            $inputTokens += $continuationTokens;
        } elseif (isset($options['continuation_tokens']) && ! $continuationAlreadyInlined) {
            $continuationTokens = (int) $options['continuation_tokens'];
            $inputTokens += $continuationTokens;
        }

        $schemaAlreadyInlined = (bool) ($options['schema_already_inlined'] ?? false);
        $schemaExtra = (string) ($options['schema_text'] ?? '');
        $schemaTokens = 0;
        if (! $schemaAlreadyInlined && $schemaExtra !== '') {
            $schemaTokens = $this->estimator->estimate($schemaExtra, $capability->estimatorFamily);
            $inputTokens += $schemaTokens;
        }

        return $this->buildPlan(
            capability: $capability,
            strategy: $strategy,
            inputTokens: $inputTokens,
            options: $options,
            extraDiagnostics: [
                'estimated_prompt_tokens' => $inputTokens - $capability->providerMessageOverheadTokens - $continuationTokens - $schemaTokens,
                'estimated_continuation_tokens' => $continuationTokens,
                'estimated_schema_tokens' => $schemaTokens,
                'continuation_already_inlined' => $continuationAlreadyInlined,
                'schema_already_inlined' => $schemaAlreadyInlined,
                'layer' => 'task_planning',
            ],
        );
    }

    /**
     * Final outbound invariant — estimate ONLY what will actually be sent.
     */
    public function assertOutbound(
        OutboundAiRequest $request,
        ModelContextCapability $capability,
        PromptSplitStrategy $strategy,
        array $options = [],
    ): PromptBudgetPlan {
        $parts = [];
        foreach ($request->messages as $message) {
            $parts[] = (string) ($message['content'] ?? '');
        }
        $inputTokens = $this->estimator->estimateParts($parts, $capability->estimatorFamily);
        $inputTokens += $capability->providerMessageOverheadTokens;

        $schemaTokens = 0;
        if ($request->jsonSchema !== null && $request->jsonSchema !== '') {
            $schemaTokens = $this->estimator->estimate($request->jsonSchema, $capability->estimatorFamily);
            $inputTokens += $schemaTokens;
        }

        $toolTokens = 0;
        if ($request->tools !== []) {
            $encoded = json_encode($request->tools);
            if (is_string($encoded) && $encoded !== '') {
                $toolTokens = $this->estimator->estimate($encoded, $capability->estimatorFamily);
                $inputTokens += $toolTokens;
            }
        }

        if ($request->requestedMaxOutputTokens > 0) {
            $options['desired_output_tokens'] = (int) ($options['desired_output_tokens'] ?? $request->requestedMaxOutputTokens);
            $options['minimum_required_output_tokens'] = (int) ($options['minimum_required_output_tokens']
                ?? max(1, (int) floor($request->requestedMaxOutputTokens * 0.5)));
        }

        $plan = $this->buildPlan(
            capability: $capability,
            strategy: $strategy,
            inputTokens: $inputTokens,
            options: $options,
            extraDiagnostics: [
                'estimated_message_tokens' => $inputTokens - $capability->providerMessageOverheadTokens - $schemaTokens - $toolTokens,
                'estimated_schema_tokens' => $schemaTokens,
                'estimated_tool_tokens' => $toolTokens,
                'provider' => $request->provider,
                'model' => $request->model,
                'layer' => 'final_outbound',
                'message_count' => count($request->messages),
            ],
            planId: $request->planId !== '' ? $request->planId : null,
        );

        if (! $plan->outputCapabilitySufficient) {
            throw PromptBudgetException::outputCapabilityExceeded(
                'minimum required output exceeds model max output',
                $plan->toDiagnostics(),
            );
        }

        if (! $plan->requestFits) {
            if ($strategy->supportsSplit()) {
                throw PromptBudgetException::contextBudget(
                    'Outbound payload exceeds safe budget; semantic split/replan required.',
                    $plan->toDiagnostics(),
                );
            }
            throw PromptBudgetException::unsplittable(
                'Outbound payload exceeds model context and task is not semantically splittable.',
                $plan->toDiagnostics(),
            );
        }

        $this->verifiedPlans[$plan->planId] = $plan;

        return $plan;
    }

    public function requireVerifiedPlanId(string $planId): void
    {
        if ($planId === '' || ! isset($this->verifiedPlans[$planId])) {
            throw PromptBudgetException::unsplittable(
                'Provider call blocked: missing verified budget plan_id.',
                ['plan_id' => $planId],
            );
        }
    }

    public function hasVerifiedPlan(string $planId): bool
    {
        return $planId !== '' && isset($this->verifiedPlans[$planId]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function assertSendable(
        RoutedAiCandidate $candidate,
        string $compiledPrompt,
        ?string $hookKey = null,
        array $options = [],
    ): PromptBudgetPlan {
        // Planning layer — continuation already in compiled by default.
        $options['continuation_already_inlined'] = $options['continuation_already_inlined'] ?? true;
        $options['schema_already_inlined'] = $options['schema_already_inlined'] ?? true;

        $capability = $this->capabilityResolver->resolve($candidate);
        $strategy = $this->strategies->forHook($hookKey);
        $plan = $this->planWithCapability($capability, $strategy, $compiledPrompt, $options);

        if (! $plan->outputCapabilitySufficient) {
            if ($strategy->supportsSplit()) {
                throw PromptBudgetException::outputCapabilityExceeded(
                    'Model max output below task minimum; shrink semantic unit before provider call.',
                    $plan->toDiagnostics(),
                );
            }
            throw PromptBudgetException::outputCapabilityExceeded(
                'Model cannot satisfy minimum required output for this atomic task.',
                $plan->toDiagnostics(),
            );
        }

        if ($plan->requestFits) {
            $this->verifiedPlans[$plan->planId] = $plan;

            return $plan;
        }

        if ($strategy->supportsSplit()) {
            throw PromptBudgetException::contextBudget(
                'Request exceeds model safe budget; semantic split required before provider call.',
                $plan->toDiagnostics(),
            );
        }

        throw PromptBudgetException::unsplittable(
            'Prompt does not fit model context and has no semantic split strategy.',
            $plan->toDiagnostics(),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $extraDiagnostics
     */
    private function buildPlan(
        ModelContextCapability $capability,
        PromptSplitStrategy $strategy,
        int $inputTokens,
        array $options,
        array $extraDiagnostics,
        ?string $planId = null,
    ): PromptBudgetPlan {
        $desired = (int) ($options['desired_output_tokens'] ?? 0);
        if ($desired <= 0) {
            $desired = $strategy->estimateOutputReserve($options, $capability);
        }
        $minimum = (int) ($options['minimum_required_output_tokens'] ?? 0);
        if ($minimum <= 0) {
            $minimum = max(64, (int) floor($desired * 0.35));
        }

        $modelMax = $capability->maxOutputTokens;
        $outputOk = $minimum <= $modelMax;
        // Only after capability check: request the lesser of desired and model max.
        $requestedMax = $outputOk ? min($desired, $modelMax) : 0;

        $safe = $capability->safeContextBudget();
        $fits = $outputOk && (($inputTokens + $requestedMax) <= $safe);

        $id = $planId ?? (string) Str::uuid();

        return new PromptBudgetPlan(
            requestFits: $fits,
            estimatedInputTokens: $inputTokens,
            desiredOutputTokens: $desired,
            minimumRequiredOutputTokens: $minimum,
            requestedMaxOutputTokens: $requestedMax,
            modelMaxOutputTokens: $modelMax,
            safeContextBudget: $safe,
            outputCapabilitySufficient: $outputOk,
            splitClass: $strategy->splitClass()->value,
            splitStrategy: $strategy->hookKey(),
            planId: $id,
            estimatorConfidence: $capability->estimatorConfidence,
            capabilityConfidence: $capability->capabilityConfidence,
            diagnostics: array_merge($capability->toDiagnostics(), $extraDiagnostics, [
                'hook' => $strategy->hookKey(),
                'supports_split' => $strategy->supportsSplit(),
            ]),
        );
    }

    public function strategies(): PromptSplitStrategyRegistry
    {
        return $this->strategies;
    }

    public function capabilities(): ModelContextCapabilityResolver
    {
        return $this->capabilityResolver;
    }

    public function estimator(): PromptTokenEstimator
    {
        return $this->estimator;
    }
}
