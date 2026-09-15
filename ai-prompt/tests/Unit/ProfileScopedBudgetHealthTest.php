<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiProviderBalanceSnapshot;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiProviderBalanceSnapshotCache;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityPolicy;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules\KnownOutputCapacityRule;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules\KnownWalletFloorRule;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules\ProfileBudgetSuppressionRule;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProfileScopedBudgetHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AiRuntimeHealthService::clearProfilePaidBudgetBlocks();
        AiRuntimeHealthService::clearSuppressedFreeLanes();
        AiProviderBalanceSnapshotCache::clear();
    }

    protected function tearDown(): void
    {
        AiRuntimeHealthService::clearProfilePaidBudgetBlocks();
        AiRuntimeHealthService::clearSuppressedFreeLanes();
        AiProviderBalanceSnapshotCache::clear();
        parent::tearDown();
    }

    public function test_longform_budget_block_does_not_skip_reasoning_on_same_connection(): void
    {
        $connection = new ApiConnection;
        $connection->forceFill([
            'id' => 9001,
            'provider' => 'deepseek',
            'name' => 'DeepSeek',
            'status' => 'active',
            'paid_locked' => false,
        ]);
        $connection->syncOriginal();

        $longform = new RoutedAiCandidate(
            profile: AiExecutionProfile::TextLongform->value,
            connection: $connection,
            provider: 'deepseek',
            model: 'deepseek-v4-pro',
            capabilities: ['text.generate'],
            priority: 1,
            seoAiModelId: 1,
            isFree: false,
        );
        $reasoning = new RoutedAiCandidate(
            profile: AiExecutionProfile::TextReasoning->value,
            connection: $connection,
            provider: 'deepseek',
            model: 'deepseek-v4-pro',
            capabilities: ['text.generate', 'text.reasoning'],
            priority: 1,
            seoAiModelId: 1,
            isFree: false,
        );

        $health = new AiRuntimeHealthService;
        $health->suppressConnectionPaidForProfile($longform);

        // Health skipReason is no longer the authority for profile budget blocks.
        self::assertNull($health->skipReason(1, $longform));
        self::assertSame(
            'connection_paid_profile_budget_limited',
            $health->paidProfileBudgetSkipReason($longform),
        );

        $policy = new AiRouteCapacityPolicy(
            new AiProviderBalanceSnapshotCache,
            [
                new ProfileBudgetSuppressionRule($health),
                new KnownWalletFloorRule,
                new KnownOutputCapacityRule,
            ],
        );
        $okBalance = new AiProviderBalanceSnapshot(
            connectionId: 9001,
            balanceUsd: 5.0,
            currency: 'USD',
            trustworthy: true,
            source: 'test',
        );

        $longformDecision = $policy->evaluate(
            $longform,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(hookKey: 'article.content.generate'),
            $okBalance,
        );
        $reasoningDecision = $policy->evaluate(
            $reasoning,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(hookKey: 'article.outline.generate'),
            $okBalance,
        );

        self::assertFalse($longformDecision->eligible);
        self::assertSame(ProfileBudgetSuppressionRule::REASON, $longformDecision->reason);
        self::assertTrue($reasoningDecision->eligible);
        self::assertNull($health->skipReason(1, $reasoning));
    }

    public function test_insufficient_budget_decision_does_not_require_global_paid_lock_flag(): void
    {
        $decision = new AiFailureDecision(
            category: AiFailureClass::InsufficientBudgetForRequest,
            scope: AiFailureScope::ConnectionPaid,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::BudgetLimited,
            lockConnectionPaid: false,
            affectsRuntimeHealth: true,
        );

        self::assertFalse($decision->lockConnectionPaid);
        self::assertTrue($decision->shouldContinueRouting());
    }

    public function test_router_uses_capacity_policy_not_inline_balance_checks(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\AiModelRouterService::class))->getFileName() ?: '',
        );
        self::assertStringContainsString('routeCapacityPolicy()', $src);
        self::assertStringContainsString('AiRouteCapacityPolicy', $src);
        self::assertStringNotContainsString('insufficient_credit_preflight', $src);
        self::assertStringNotContainsString('knownZeroBalancePaidSkipReason', $src);
        self::assertStringNotContainsString('AiCapacityStatusService', $src);
    }
}
