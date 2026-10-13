<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Illuminate\Http\Request;
use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;
use Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata;
use Omnichannel\Addons\AgentRuntime\Model\AssumedModelResolver;
use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Tests\TestCase;

final class TierRecordingGateway implements AnswerModelGateway
{
    public int $calls = 0;
    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public function complete(int $userId, PreparedModelInput $input, array $options = []): string
    {
        $this->calls++;
        $this->lastOptions = $options;

        return '{"message": "Answer completed", "blocks": [{"type": "markdown", "text": "Answer completed"}]}';
    }
}

final class TierRecordingResolver implements AssumedModelResolver
{
    public ?AiRoutingPolicy $lastRoutingPolicy = null;

    public function resolveDecisionModel(int $userId): AssumedModelMetadata
    {
        return new AssumedModelMetadata(
            stage: 'decision',
            profile: 'decision.route',
            provider: 'test',
            model: 'test-decision',
            displayName: 'Test Decision',
            fallbacks: [],
            routingMode: 'priority_order',
            status: 'available',
        );
    }

    public function resolveAnswerModel(int $userId, ?AiRoutingPolicy $routingPolicy = null): AssumedModelMetadata
    {
        $this->lastRoutingPolicy = $routingPolicy;

        $modelName = match ($routingPolicy) {
            AiRoutingPolicy::FreeOnly => 'free-model',
            AiRoutingPolicy::QuickFree => 'quick-free-model',
            AiRoutingPolicy::Normal => 'master-model',
            default => 'default-model',
        };

        return new AssumedModelMetadata(
            stage: 'answer',
            profile: 'text.reasoning',
            provider: 'test',
            model: $modelName,
            displayName: ucfirst($modelName),
            fallbacks: [],
            routingMode: 'text.reasoning',
            status: 'available',
        );
    }
}

class AgentTierRoutingTest extends TestCase
{
    public function test_ai_routing_policy_free_only_never_allows_paid_routes(): void
    {
        $freePolicy = AiRoutingPolicy::FreeOnly;
        $this->assertFalse($freePolicy->allowsPaidRoutes());

        $proPolicy = AiRoutingPolicy::QuickFree;
        $this->assertTrue($proPolicy->allowsPaidRoutes());
        $this->assertSame(1, $proPolicy->freeAttemptCap());

        $masterPolicy = AiRoutingPolicy::Normal;
        $this->assertTrue($masterPolicy->allowsPaidRoutes());
        $this->assertNull($masterPolicy->freeAttemptCap());
    }

    public function test_turn_rejects_invalid_tier(): void
    {
        $controller = new AgentRuntimeController();
        $user = new class {
            public int $id = 1;
            public function accountOwnerId(): int { return 1; }
        };

        $request = Request::create('/agent/turn', 'POST', [
            'message' => 'Hello test',
            'scope' => ['type' => 'site', 'ref' => 'site:1', 'siteId' => 1],
            'tier' => 'ultra_mega_tier',
        ]);
        $request->setUserResolver(fn () => $user);

        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $sites = $this->createMock(SiteDirectory::class);
        $sites->method('isSiteVisible')->willReturn(true);
        $threads = $this->createMock(AgentThreadRepository::class);
        $persistence = $this->createMock(AgentTurnPersistence::class);

        $response = $controller->turn($request, $coordinator, $sites, $threads, $persistence);

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Invalid tier.', $data['message']);
    }

    public function test_coordinator_propagates_free_tier_and_routing_policy(): void
    {
        $gateway = new TierRecordingGateway();
        $resolver = new TierRecordingResolver();
        $this->app->instance(AssumedModelResolver::class, $resolver);

        $coordinatorMethod = new \ReflectionMethod(AgentTurnCoordinator::class, 'send');
        $params = $coordinatorMethod->getParameters();
        $this->assertCount(7, $params);
        $this->assertSame('routingPolicy', $params[5]->getName());
        $this->assertSame('tier', $params[6]->getName());

        $answerConfirmedMethod = new \ReflectionMethod(AgentTurnCoordinator::class, 'answerConfirmed');
        $acParams = $answerConfirmedMethod->getParameters();
        $this->assertCount(9, $acParams);
        $this->assertSame('routingPolicy', $acParams[7]->getName());
        $this->assertSame('tier', $acParams[8]->getName());
    }

    public function test_tier_mapping_consistency(): void
    {
        $map = [
            'free' => AiRoutingPolicy::FreeOnly,
            'pro' => AiRoutingPolicy::QuickFree,
            'master' => AiRoutingPolicy::Normal,
        ];

        foreach ($map as $tier => $expectedPolicy) {
            $mapped = match ($tier) {
                'free' => AiRoutingPolicy::FreeOnly,
                'pro' => AiRoutingPolicy::QuickFree,
                'master' => AiRoutingPolicy::Normal,
                default => null,
            };
            $this->assertSame($expectedPolicy, $mapped);
        }
    }
}
