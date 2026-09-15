<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiProviderBalanceSnapshot;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityPolicy;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules\KnownWalletFloorRule;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use App\Models\ApiConnection;
use PHPUnit\Framework\TestCase;

/**
 * Simulates router short-circuit: capacity-ineligible routes never invoke the provider executor.
 */
final class AiRouteCapacityExecutorGuardTest extends TestCase
{
    public function test_below_floor_candidate_never_invokes_executor_while_next_route_does(): void
    {
        $policy = new AiRouteCapacityPolicy;
        $context = new AiRoutingContext(hookKey: 'article.content.generate');
        $below = new AiProviderBalanceSnapshot(1, 0.99, 'USD', true, 'test');
        $ok = new AiProviderBalanceSnapshot(2, 1.00, 'USD', true, 'test');

        $candidates = [
            $this->candidate(1, 'first'),
            $this->candidate(2, 'second'),
        ];
        $balances = [1 => $below, 2 => $ok];

        $calls = [];
        $output = null;
        foreach ($candidates as $candidate) {
            $decision = $policy->evaluate(
                $candidate,
                AiExecutionProfile::TextLongform,
                $context,
                $balances[(int) $candidate->connection->id],
            );
            if (! $decision->eligible) {
                self::assertSame(KnownWalletFloorRule::REASON, $decision->reason);
                continue;
            }
            $calls[] = $candidate->model;
            $output = 'from-'.$candidate->model;
            break;
        }

        self::assertSame(['second'], $calls);
        self::assertSame('from-second', $output);
    }

    private function candidate(int $connectionId, string $model): RoutedAiCandidate
    {
        $connection = new ApiConnection;
        $connection->forceFill([
            'id' => $connectionId,
            'provider' => 'openrouter',
            'name' => 'c'.$connectionId,
            'status' => 'active',
        ]);
        $connection->syncOriginal();

        return new RoutedAiCandidate(
            profile: AiExecutionProfile::TextLongform->value,
            connection: $connection,
            provider: 'openrouter',
            model: $model,
            capabilities: ['text.generate'],
            priority: $connectionId,
            isFree: false,
        );
    }
}
