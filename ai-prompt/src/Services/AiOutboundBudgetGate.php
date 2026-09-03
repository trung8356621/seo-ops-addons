<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\DataTransfer\OutboundAiRequest;
use Omnichannel\Addons\AiPrompt\DataTransfer\PromptBudgetPlan;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use App\Models\ApiConnection;

/**
 * Gate at adapter boundary: verify outbound payload against route capability before HTTP.
 */
final class AiOutboundBudgetGate
{
    public function __construct(
        private readonly PromptBudgetPreflightService $preflight = new PromptBudgetPreflightService(),
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function verifyCompiled(
        ?RoutedAiCandidate $candidate,
        ApiConnection $connection,
        string $compiled,
        string $model,
        string $provider,
        ?string $hookKey,
        array $options = [],
    ): PromptBudgetPlan {
        $planId = (string) ($options['budget_plan_id'] ?? '');
        $this->assertVerifiedPlanRequired($planId, $options);

        $capability = $candidate instanceof RoutedAiCandidate
            ? $this->preflight->capabilities()->resolve($candidate)
            : $this->preflight->capabilities()->resolveFor($connection, $model);

        $strategy = $this->preflight->strategies()->forHook($hookKey);

        $outbound = OutboundAiRequest::fromCompiledUserPrompt(
            $compiled,
            $provider,
            $model,
            $options,
            $planId,
        );

        return $this->preflight->assertOutbound($outbound, $capability, $strategy, $options);
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function verifyMessages(
        ModelContextCapability $capability,
        array $messages,
        string $provider,
        string $model,
        ?string $hookKey,
        array $options = [],
    ): PromptBudgetPlan {
        $strategy = $this->preflight->strategies()->forHook($hookKey);
        $planId = (string) ($options['budget_plan_id'] ?? '');
        $this->assertVerifiedPlanRequired($planId, $options);
        $outbound = OutboundAiRequest::fromMessages(
            $messages,
            $provider,
            $model,
            $options,
            $planId,
        );

        return $this->preflight->assertOutbound($outbound, $capability, $strategy, $options);
    }

    public function preflight(): PromptBudgetPreflightService
    {
        return $this->preflight;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function assertVerifiedPlanRequired(string $planId, array $options): void
    {
        // Tests / internal bootstrap may opt into planning+outbound in one call.
        if (($options['allow_unverified_outbound'] ?? false) === true) {
            return;
        }
        $this->preflight->requireVerifiedPlanId($planId);
    }
}
