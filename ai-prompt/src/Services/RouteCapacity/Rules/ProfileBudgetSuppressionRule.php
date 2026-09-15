<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiProviderBalanceSnapshot;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityDecision;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityRule;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;

/**
 * Honors prior profile-scoped paid budget suppression (e.g. InsufficientBudgetForRequest).
 * text.longform may be blocked while text.reasoning on the same connection stays eligible.
 */
final class ProfileBudgetSuppressionRule implements AiRouteCapacityRule
{
    public const REASON = 'connection_paid_profile_budget_limited';

    public function __construct(
        private readonly ?AiRuntimeHealthService $health = null,
    ) {}

    public function evaluate(
        RoutedAiCandidate $candidate,
        AiExecutionProfile $profile,
        AiRoutingContext $context,
        ?AiProviderBalanceSnapshot $balance,
    ): AiRouteCapacityDecision {
        unset($profile, $context);

        $health = $this->health;
        if ($health === null && function_exists('app')) {
            try {
                $health = app(AiRuntimeHealthService::class);
            } catch (\Throwable) {
                $health = new AiRuntimeHealthService;
            }
        }
        $health ??= new AiRuntimeHealthService;

        $reason = $health->paidProfileBudgetSkipReason($candidate);
        if ($reason === null) {
            return AiRouteCapacityDecision::allow($balance);
        }

        return AiRouteCapacityDecision::deny(
            reason: $reason !== '' ? $reason : self::REASON,
            scope: 'profile',
            balance: $balance,
            source: 'profile_budget_suppression',
        );
    }
}
