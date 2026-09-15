<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiProviderBalanceSnapshot;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiProviderBalanceSnapshotCache;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityPolicy;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules\KnownOutputCapacityRule;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules\KnownWalletFloorRule;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules\ProfileBudgetSuppressionRule;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use PHPUnit\Framework\TestCase;

final class AiRouteCapacityPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AiProviderBalanceSnapshotCache::clear();
        AiRuntimeHealthService::clearProfilePaidBudgetBlocks();
        AiRuntimeHealthService::clearSuppressedFreeLanes();
    }

    protected function tearDown(): void
    {
        AiProviderBalanceSnapshotCache::clear();
        AiRuntimeHealthService::clearProfilePaidBudgetBlocks();
        AiRuntimeHealthService::clearSuppressedFreeLanes();
        parent::tearDown();
    }

    public function test_wallet_floor_rejects_longform_below_one_usd(): void
    {
        $policy = $this->policy();
        $candidate = $this->candidate(AiExecutionProfile::TextLongform);
        $balance = $this->usdSnapshot(0.99);

        $decision = $policy->evaluate(
            $candidate,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(hookKey: 'article.content.generate'),
            $balance,
        );

        self::assertFalse($decision->eligible);
        self::assertSame(KnownWalletFloorRule::REASON, $decision->reason);
        self::assertSame('profile', $decision->scope);
        self::assertSame(0.99, $decision->knownBalanceUsd);
        self::assertSame(1.0, $decision->thresholdUsd);
    }

    public function test_wallet_floor_allows_exactly_one_usd_longform(): void
    {
        $decision = $this->policy()->evaluate(
            $this->candidate(AiExecutionProfile::TextLongform),
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(hookKey: 'article.content.generate'),
            $this->usdSnapshot(1.00),
        );

        self::assertTrue($decision->eligible);
        self::assertNull($decision->reason);
    }

    public function test_wallet_floor_allows_reasoning_below_one_usd(): void
    {
        $decision = $this->policy()->evaluate(
            $this->candidate(AiExecutionProfile::TextReasoning),
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(hookKey: 'article.outline.generate'),
            $this->usdSnapshot(0.60),
        );

        self::assertTrue($decision->eligible);
    }

    public function test_article_content_hook_applies_floor_even_on_non_longform_profile(): void
    {
        $decision = $this->policy()->evaluate(
            $this->candidate(AiExecutionProfile::TextFast),
            AiExecutionProfile::TextFast,
            new AiRoutingContext(hookKey: 'article.content.generate'),
            $this->usdSnapshot(0.50),
        );

        self::assertFalse($decision->eligible);
        self::assertSame(KnownWalletFloorRule::REASON, $decision->reason);
    }

    public function test_unknown_balance_does_not_reject_from_wallet_rule(): void
    {
        $decision = $this->policy()->evaluate(
            $this->candidate(AiExecutionProfile::TextLongform),
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(hookKey: 'article.content.generate'),
            AiProviderBalanceSnapshot::unknown(42, 'unknown'),
        );

        self::assertTrue($decision->eligible);
    }

    public function test_stale_non_trustworthy_balance_does_not_reject(): void
    {
        $decision = $this->policy()->evaluate(
            $this->candidate(AiExecutionProfile::TextLongform),
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(hookKey: 'article.content.generate'),
            new AiProviderBalanceSnapshot(
                connectionId: 42,
                balanceUsd: 0.10,
                currency: 'USD',
                trustworthy: false,
                source: 'stale_connection_column',
            ),
        );

        self::assertTrue($decision->eligible);
    }

    public function test_profile_budget_suppression_blocks_longform_not_reasoning(): void
    {
        $connection = $this->connection();
        $longform = $this->candidate(AiExecutionProfile::TextLongform, $connection);
        $reasoning = $this->candidate(AiExecutionProfile::TextReasoning, $connection);

        $health = new AiRuntimeHealthService;
        $health->suppressConnectionPaidForProfile($longform);

        $policy = new AiRouteCapacityPolicy(
            new AiProviderBalanceSnapshotCache,
            [
                new ProfileBudgetSuppressionRule($health),
                new KnownWalletFloorRule,
                new KnownOutputCapacityRule,
            ],
        );

        $longformDecision = $policy->evaluate(
            $longform,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(hookKey: 'article.content.generate'),
            $this->usdSnapshot(5.0),
        );
        $reasoningDecision = $policy->evaluate(
            $reasoning,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(hookKey: 'article.outline.generate'),
            $this->usdSnapshot(5.0),
        );

        self::assertFalse($longformDecision->eligible);
        self::assertSame(ProfileBudgetSuppressionRule::REASON, $longformDecision->reason);
        self::assertTrue($reasoningDecision->eligible);
    }

    public function test_manual_order_survivors_keep_relative_priority(): void
    {
        $low = $this->candidate(AiExecutionProfile::TextLongform, priority: 1, model: 'a');
        $mid = $this->candidate(AiExecutionProfile::TextLongform, priority: 2, model: 'b');
        $high = $this->candidate(AiExecutionProfile::TextLongform, priority: 3, model: 'c');

        $policy = $this->policy();
        $context = new AiRoutingContext(hookKey: 'article.content.generate');
        $below = $this->usdSnapshot(0.99);
        $ok = $this->usdSnapshot(2.00);

        $survivors = [];
        foreach ([$low, $mid, $high] as $index => $candidate) {
            $balance = $index === 1 ? $below : $ok;
            $decision = $policy->evaluate($candidate, AiExecutionProfile::TextLongform, $context, $balance);
            if ($decision->eligible) {
                $survivors[] = $candidate->model;
            }
        }

        self::assertSame(['a', 'c'], $survivors);
    }

    private function policy(): AiRouteCapacityPolicy
    {
        return new AiRouteCapacityPolicy(
            new AiProviderBalanceSnapshotCache,
            [
                new ProfileBudgetSuppressionRule(new AiRuntimeHealthService),
                new KnownWalletFloorRule,
                new KnownOutputCapacityRule,
            ],
        );
    }

    private function usdSnapshot(float $balance): AiProviderBalanceSnapshot
    {
        $now = now();

        return new AiProviderBalanceSnapshot(
            connectionId: 42,
            balanceUsd: $balance,
            currency: 'USD',
            trustworthy: true,
            source: 'test',
            observedAt: $now,
            expiresAt: $now->copy()->addMinutes(5),
            status: 'normal',
        );
    }

    private function connection(): ApiConnection
    {
        $connection = new ApiConnection;
        $connection->forceFill([
            'id' => 42,
            'provider' => 'openrouter',
            'name' => 'OR',
            'status' => 'active',
            'paid_locked' => false,
            'balance' => 0.99,
            'balance_status' => 'low_balance',
            'currency' => 'USD',
        ]);
        $connection->syncOriginal();

        return $connection;
    }

    private function candidate(
        AiExecutionProfile $profile,
        ?ApiConnection $connection = null,
        int $priority = 1,
        string $model = 'openrouter/model',
    ): RoutedAiCandidate {
        return new RoutedAiCandidate(
            profile: $profile->value,
            connection: $connection ?? $this->connection(),
            provider: 'openrouter',
            model: $model,
            capabilities: ['text.generate'],
            priority: $priority,
            seoAiModelId: $priority,
            isFree: false,
        );
    }
}
