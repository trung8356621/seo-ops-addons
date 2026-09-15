<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiProviderBalanceSnapshot;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityDecision;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityRule;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;

/**
 * Profile-scoped wallet floor for long-form / article content workloads.
 * balance < 1.00 USD ⇒ ineligible; balance == 1.00 remains eligible.
 * Unknown/stale/non-USD ⇒ no-op (do not invent, do not reject).
 */
final class KnownWalletFloorRule implements AiRouteCapacityRule
{
    public const REASON = 'known_wallet_below_longform_floor';

    public const FLOOR_USD = 1.0;

    public const ARTICLE_CONTENT_HOOK = 'article.content.generate';

    public function evaluate(
        RoutedAiCandidate $candidate,
        AiExecutionProfile $profile,
        AiRoutingContext $context,
        ?AiProviderBalanceSnapshot $balance,
    ): AiRouteCapacityDecision {
        if (! $this->appliesToWorkload($profile, $context)) {
            return AiRouteCapacityDecision::allow($balance);
        }

        if ($balance === null || ! $balance->hasKnownUsdBalance()) {
            return AiRouteCapacityDecision::allow($balance);
        }

        $known = (float) $balance->balanceUsd;
        if ($known >= self::FLOOR_USD) {
            return AiRouteCapacityDecision::allow($balance);
        }

        return AiRouteCapacityDecision::deny(
            reason: self::REASON,
            scope: 'profile',
            balance: $balance,
            thresholdUsd: self::FLOOR_USD,
            source: $balance->source,
        );
    }

    private function appliesToWorkload(AiExecutionProfile $profile, AiRoutingContext $context): bool
    {
        if ($profile === AiExecutionProfile::TextLongform) {
            return true;
        }

        $hook = strtolower(trim((string) ($context->hookKey ?? $context->canonicalPromptKey ?? '')));

        return $hook === self::ARTICLE_CONTENT_HOOK;
    }
}
