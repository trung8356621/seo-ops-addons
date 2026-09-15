<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\RouteCapacity;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules\KnownOutputCapacityRule;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules\KnownWalletFloorRule;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules\ProfileBudgetSuppressionRule;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;

/**
 * Single pre-provider-call authority for physical-route workload capacity.
 *
 * {@see \Omnichannel\Addons\AiPrompt\Services\AiCapacityStatusService} remains Rescue Mode/UI only
 * and must not be used as route-level routing authority.
 */
final class AiRouteCapacityPolicy
{
    /** @var list<AiRouteCapacityRule> */
    private array $rules;

    /**
     * @param  list<AiRouteCapacityRule>|null  $rules
     */
    public function __construct(
        private readonly AiProviderBalanceSnapshotCache $balances = new AiProviderBalanceSnapshotCache,
        ?array $rules = null,
    ) {
        $this->rules = $rules ?? [
            new ProfileBudgetSuppressionRule,
            new KnownWalletFloorRule,
            new KnownOutputCapacityRule,
        ];
    }

    public function evaluate(
        RoutedAiCandidate $candidate,
        AiExecutionProfile $profile,
        AiRoutingContext $context,
        ?AiProviderBalanceSnapshot $balance = null,
    ): AiRouteCapacityDecision {
        $snapshot = $balance ?? $this->balances->snapshotFor($candidate->connection);

        foreach ($this->rules as $rule) {
            $decision = $rule->evaluate($candidate, $profile, $context, $snapshot);
            if (! $decision->eligible) {
                return $decision;
            }
        }

        return AiRouteCapacityDecision::allow($snapshot);
    }

    public function balances(): AiProviderBalanceSnapshotCache
    {
        return $this->balances;
    }

    public function invalidateBalance(int $connectionId): void
    {
        $this->balances->invalidate($connectionId);
    }
}
